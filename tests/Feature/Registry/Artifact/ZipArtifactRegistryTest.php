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
use Tobento\App\Backup\Artifact\ReadableZipArtifact;
use Tobento\App\Backup\Artifact\WritableZipArtifact;
use Tobento\App\Backup\Exception\ArtifactCreateException;
use Tobento\App\Backup\Registry\Artifact\ReadableArtifactRegistryInterface;
use Tobento\App\Backup\Registry\Artifact\WritableArtifactRegistryInterface;
use Tobento\App\Backup\Registry\Artifact\ZipArtifactRegistry;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\Testing\TestCase;
use Tobento\Service\FileStorage\File;
use Tobento\Service\FileStorage\FileInterface;
use Tobento\Service\FileStorage\Repository\FileRepository;
use Tobento\Service\Filesystem\Dir;
use Tobento\Service\Repository\NullRepository;

class ZipArtifactRegistryTest extends TestCase
{
    use \Tobento\App\Backup\Test\ZipHelperTrait;
    
    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        return $app;
    }

    public function testImplementsInterfaces()
    {
        $reg = new ZipArtifactRegistry(
            id: 'zip',
            name: 'Zip Storage',
            location: __DIR__.'/tmp',
        );

        $this->assertInstanceOf(ReadableArtifactRegistryInterface::class, $reg);
        $this->assertInstanceOf(WritableArtifactRegistryInterface::class, $reg);
    }

    public function testGetterMethods()
    {
        $this->bootingApp();

        $reg = new ZipArtifactRegistry(
            id: 'zip',
            name: 'Zip Storage',
            location: '/var/backups',
        );

        $this->assertSame('zip', $reg->id());
        $this->assertSame('Zip Storage', $reg->name());
        $this->assertSame('/var/backups/', $reg->location());
    }

    public function testCreateWritableArtifactMethodCreatesCorrectInstance()
    {
        $app = $this->bootingApp();

        $reg = new ZipArtifactRegistry(
            id: 'zip',
            name: 'Zip Storage',
            location: $app->dir('root').'zip-backups',
            throughputInBytes: 1234,
        );
        
        $entity = new ProcessEntity([
            'id' => 5,
            'artifact_source' => 'my-file'
        ]);

        $artifact = $reg->createWritableArtifact($app->container(), $entity);

        $this->assertInstanceOf(WritableZipArtifact::class, $artifact);
        $this->assertSame(
            $reg->location().'my-file-5.zip',
            $artifact->zipPath()
        );
        $this->assertSame(1234, $artifact->throughputInBytes());
    }

    public function testCreateWritableArtifactMethodCreatesDefaultFilename()
    {
        $app = $this->bootingApp();

        $reg = new ZipArtifactRegistry(
            id: 'zip',
            name: 'Zip Storage',
            location: $app->dir('root').'zip-backups'
        );

        $entity = new ProcessEntity([
            'id' => 99,
            'artifact_source' => null
        ]);

        $artifact = $reg->createWritableArtifact($app->container(), $entity);

        $this->assertSame(
            $reg->location().'backup-99.zip',
            $artifact->zipPath()
        );
    }
    
    public function testCreateWritableArtifactMethodFilenameIsSanitized()
    {
        $app = $this->bootingApp();

        $reg = new ZipArtifactRegistry(
            id: 'zip',
            name: 'Zip Storage',
            location: $app->dir('root').'zip-backups'
        );

        $entity = new ProcessEntity([
            'id' => 7,
            'artifact_source' => '  my*invalid.zip  '
        ]);

        $artifact = $reg->createWritableArtifact($app->container(), $entity);

        // Expected:
        // trim → "my*invalid.zip"
        // illegal chars → "my-invalid.zip"
        // remove .zip → "my-invalid"
        // trim separators → "my-invalid"
        // append "-7.zip" → "my-invalid-7.zip"

        $this->assertSame(
            $reg->location().'my-invalid-7.zip',
            $artifact->zipPath()
        );
    }

    public function testCreateReadableArtifactMethodCreatesCorrectInstance()
    {
        $app = $this->bootingApp();
        
        $reg = new ZipArtifactRegistry(
            id: 'zip',
            name: 'Zip Storage',
            location: $app->dir('root').'zip-backups'
        );
        
        $zipPath = $reg->location().'restore.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', '{}');
        $zip->close();
        
        $file = new File(storageName: 'backup', path: 'restore.zip');

        $entity = new ProcessEntity([
            'artifact_source' => $file
        ]);

        $artifact = $reg->createReadableArtifact($app->container(), $entity);

        $this->assertInstanceOf(ReadableZipArtifact::class, $artifact);
        $this->assertSame(
            $reg->location().'restore.zip',
            $artifact->zipPath()
        );
    }

    public function testCreateReadableArtifactMethodThrowsIfSourceInvalid()
    {
        $app = $this->bootingApp();

        $reg = new ZipArtifactRegistry(
            id: 'zip',
            name: 'Zip Storage',
            location: $app->dir('root').'zip-backups'
        );

        $entity = new ProcessEntity([
            'artifact_source' => 'not-a-file'
        ]);

        $this->expectException(ArtifactCreateException::class);

        $reg->createReadableArtifact($app->container(), $entity);
    }

    public function testCreateRepositoryMethodReturnsFileRepository()
    {
        $app = $this->bootingApp();

        $reg = new ZipArtifactRegistry(
            id: 'zip',
            name: 'Zip Storage',
            location: $app->dir('root').'zip-backups'
        );

        $repo = $reg->createRepository($app->container());

        $this->assertInstanceOf(FileRepository::class, $repo);
    }

    public function testMapEntityMethodReturnsRestoreEntity()
    {
        $app = $this->bootingApp();

        $reg = new ZipArtifactRegistry(
            id: 'zip',
            name: 'Zip Storage',
            location: $app->dir('root').'zip-backups'
        );

        $raw = new File(storageName: 'backup', path: 'restore.zip');

        $entity = $reg->mapEntity($raw, $app->container());

        $this->assertInstanceOf(RestoreEntityInterface::class, $entity);
        $this->assertSame('restore.zip', $entity->id());
        $this->assertSame('Zip', $entity->type());
        $this->assertSame($reg, $entity->registry());
    }
}