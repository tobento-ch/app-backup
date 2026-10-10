<?php

/**
 * TOBENTO
 *
 * @copyright   Tobias Strub, TOBENTO
 * @license     MIT License, see LICENSE file distributed with this source code.
 * @author      Tobias Strub
 * @link        https://www.tobento.ch
 */

declare(strict_types=1);

namespace Tobento\App\Backup\Test\Feature\Registry\Artifact;

use Tobento\App\AppInterface;
use Tobento\App\Backup\Artifact\ReadableFileStorageArtifact;
use Tobento\App\Backup\Artifact\WritableFileStorageArtifact;
use Tobento\App\Backup\Exception\ArtifactCreateException;
use Tobento\App\Backup\Process\FileStorageDeleteProcess;
use Tobento\App\Backup\Queue\DeleteArtifactJobHandler;
use Tobento\App\Backup\Registry\Artifact\FileStorageArtifactRegistry;
use Tobento\App\Backup\Registry\Artifact\QueueDeletionRegistryInterface;
use Tobento\App\Backup\Registry\Artifact\ReadableArtifactRegistryInterface;
use Tobento\App\Backup\Registry\Artifact\WritableArtifactRegistryInterface;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\Backup\Test\Factory;
use Tobento\App\Testing\TestCase;
use Tobento\Service\FileStorage\Folder;
use Tobento\Service\FileStorage\Repository\FolderRepository;
use Tobento\Service\Queue\JobInterface;
use Tobento\Service\Queue\Parameter;
use Tobento\Service\Repository\NullRepository;

class FileStorageArtifactRegistryTest extends TestCase
{
    use \Tobento\App\Testing\Database\RefreshDatabases;
    use \Tobento\App\Testing\FileStorage\RefreshFileStorages;
    
    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        return $app;
    }
    
    public function testThatImplementsInterfaces()
    {
        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'local',
            folder: 'backups',
        );
        
        $this->assertInstanceOf(ReadableArtifactRegistryInterface::class, $reg);
        $this->assertInstanceOf(WritableArtifactRegistryInterface::class, $reg);
        $this->assertInstanceOf(QueueDeletionRegistryInterface::class, $reg);
    }

    public function testGetterMethods()
    {
        $this->bootingApp();
        
        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'local',
            folder: 'backups',
        );

        $this->assertSame('fs', $reg->id());
        $this->assertSame('File Storage', $reg->name());
        $this->assertSame('local', $reg->storageName());
        $this->assertSame('backups/', $reg->folder());
    }

    public function testCreateWritableArtifactMethodCreatesCorrectInstance()
    {
        $app = $this->bootingApp();

        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'uploads-private',
            folder: 'backups',
            throughputInBytes: 1000,
        );

        $entity = new ProcessEntity([
            'id' => 10,
            'artifact_source' => 'my-folder'
        ]);

        $artifact = $reg->createWritableArtifact($app->container(), $entity);

        $this->assertInstanceOf(WritableFileStorageArtifact::class, $artifact);
        $this->assertSame('backups/my-folder-10', $artifact->folder());
        $this->assertSame('uploads-private', $artifact->storage()->name());
        $this->assertSame(1000, $artifact->throughputInBytes());
    }
    
    public function testCreateWritableArtifactMethodBuildsCorrectFolderName()
    {
        $app = $this->bootingApp();

        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'uploads-private',
            folder: 'backups',
        );

        $entity = new ProcessEntity([
            'id' => 42,
            'artifact_source' => '  my*invalid folder__name  ',
        ]);

        $artifact = $reg->createWritableArtifact($app->container(), $entity);

        // Expected transformation:
        // 1. trim → "my*invalid folder__name"
        // 2. illegal chars → "my-invalid-folder__name"
        // 3. trim separators → "my-invalid-folder__name"
        // 4. append "-42" → "my-invalid-folder__name-42"
        // 5. prefix with "backups/" → "backups/my-invalid-folder__name-42"

        $this->assertSame(
            'backups/my-invalid-folder__name-42',
            $artifact->folder()
        );
    }

    public function testCreateWritableArtifactMethodThrowsIfStorageMissing()
    {
        $app = $this->bootingApp();

        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'missing',
        );

        $this->expectException(ArtifactCreateException::class);

        $reg->createWritableArtifact($app->container(), new ProcessEntity(['id' => 1]));
    }

    public function testCreateReadableArtifactMethodCreatesCorrectInstance()
    {
        $app = $this->bootingApp();

        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'uploads-private',
        );

        $entity = new ProcessEntity([
            'artifact_source' => new Folder(storageName: 'uploads-private', path: 'restore-folder'),
        ]);

        $artifact = $reg->createReadableArtifact($app->container(), $entity);

        $this->assertInstanceOf(ReadableFileStorageArtifact::class, $artifact);
        $this->assertSame('restore-folder', $artifact->folder());
    }

    public function testCreateReadableArtifactMethodThrowsIfStorageMissing()
    {
        $app = $this->bootingApp();

        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'missing',
        );

        $entity = new ProcessEntity([
            'artifact_source' => new Folder(storageName: 'uploads-private', path: 'restore-folder'),
        ]);

        $this->expectException(ArtifactCreateException::class);

        $reg->createReadableArtifact($app->container(), $entity);
    }
    
    public function testCreateReadableArtifactMethodThrowsIfSourceInvalid()
    {
        $app = $this->bootingApp();

        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'uploads-private',
        );

        $entity = new ProcessEntity([
            'artifact_source' => 'not-a-folder'
        ]);

        $this->expectException(ArtifactCreateException::class);

        $reg->createReadableArtifact($app->container(), $entity);
    }

    public function testCreateRepositoryMethodReturnsNullRepositoryIfStorageMissing()
    {
        $app = $this->bootingApp();

        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'missing',
        );

        $repo = $reg->createRepository($app->container());

        $this->assertInstanceOf(NullRepository::class, $repo);
    }

    public function testCreateRepositoryMethodReturnsFolderRepository()
    {
        $app = $this->bootingApp();

        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'uploads-private',
            folder: 'backups',
        );

        $repo = $reg->createRepository($app->container());

        $this->assertInstanceOf(FolderRepository::class, $repo);
    }

    public function testMapEntityMethodReturnsRestoreEntity()
    {
        $app = $this->bootingApp();

        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'uploads-private',
            folder: 'backups',
        );

        $raw = new Folder(storageName: 'uploads-private', path: 'backups/restore-folder');

        $entity = $reg->mapEntity($raw, $app->container());

        $this->assertInstanceOf(RestoreEntityInterface::class, $entity);
        $this->assertSame('restore-folder', $entity->id());
        $this->assertSame('Folder', $entity->type());
        $this->assertSame($reg, $entity->registry());
    }
    
    public function testQueueDeletionMethodQueuesJob()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->bootingApp();

        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'uploads-private',
            folder: 'backups',
        );

        $reg->queueDeletion(id: 1, container: $app->container());

        $fakeQueue->queue(name: 'file')->assertPushed(
            DeleteArtifactJobHandler::class,
            function (JobInterface $job): bool {
                $uniqueId = $job->parameters()->get(Parameter\Unique::class)?->id();
                
                return $job->getPayload()['restore_id'] === 1
                    && $job->getPayload()['user_id'] === null
                    && $uniqueId === 'backup:delete:1';
            }
        );
        
        // clear because of unique job
        $fakeQueue->clearQueue(queue: $fakeQueue->queue(name: 'file'));
    }
    
    public function testCreateDeleteProcessMethodReturnsFileStorageDeleteProcess()
    {
        $app = $this->bootingApp();

        $reg = new FileStorageArtifactRegistry(
            id: 'fs',
            name: 'File Storage',
            storageName: 'uploads-private',
            folder: 'backups',
        );
        
        $process = $reg->createDeleteProcess(
            restoreEntity: Factory::createRestoreEntity([
                'raw' => new Folder(storageName: 'uploads-private', path: 'backups/restore-folder'),
            ]),
            container: $app->container(),
        );

        $this->assertInstanceOf(FileStorageDeleteProcess::class, $process);
        $this->assertSame('uploads-private', $process->storage()->name());
        $this->assertSame('backups/restore-folder', $process->rootFolder());
    }
}