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

namespace Tobento\App\Backup\Test\Feature\Hook;

use Tobento\App\AppInterface;
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Artifact\WritableArtifacts;
use Tobento\App\Backup\Hook\DeleteFailedBackupData;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Queue\DeleteArtifactJobHandler;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\FileStorageArtifactRegistry;
use Tobento\App\Backup\Repo\RestoreEntity;
use Tobento\App\Backup\Repo\RestoreRepositoryInterface;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Test\Factory;
use Tobento\App\Testing\Logging\LogEntry;
use Tobento\Service\Queue\JobInterface;
use Tobento\Service\Repository\RepositoryDeleteException;

class DeleteFailedBackupDataTest extends \Tobento\App\Testing\TestCase
{
    use \Tobento\App\Testing\Database\RefreshDatabases;
    use \Tobento\App\Testing\FileStorage\RefreshFileStorages;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        return $app;
    }
    
    protected function createBackupProcess(AppInterface $app, string $name, array $artifacts = [])
    {
        $backupEntity = Factory::createBackupEntity([
            'name' => $name,
            'options' => [
                'artifacts' => $artifacts,
                'app' => ['dir' => ['root' => []]],
                'hooks' => [],
            ],
        ]);

        $factory = $app->get(BackupProcessFactoryInterface::class);

        return $factory->createProcess(
            backupEntity: $backupEntity,
            processId: null,
            userId: null,
        );
    }
    
    public function testImplementsHookInterface()
    {
        $app = $this->bootingApp();

        $hook = new DeleteFailedBackupData('Auto Delete');

        $this->assertInstanceOf(
            \Tobento\App\Backup\Hook\HookInterface::class,
            $hook
        );
    }

    public function testNameMethod()
    {
        $app = $this->bootingApp();

        $hook = new DeleteFailedBackupData('Auto‑Delete Failed Backup Data');

        $this->assertSame('Auto‑Delete Failed Backup Data', $hook->name());
    }

    public function testGroupMethod()
    {
        $app = $this->bootingApp();

        $this->assertSame('Maintenance', (new DeleteFailedBackupData('foo'))->group());
        $this->assertSame('Custom', (new DeleteFailedBackupData(name: 'foo', group: 'Custom'))->group());
    }

    public function testDefaultSelectedMethod()
    {
        $app = $this->bootingApp();

        $this->assertFalse((new DeleteFailedBackupData('foo'))->defaultSelected());
        $this->assertTrue((new DeleteFailedBackupData('foo', defaultSelected: true))->defaultSelected());
    }

    public function testSupportsBackupMethod()
    {
        $app = $this->bootingApp();

        $hook = new DeleteFailedBackupData('foo');

        $this->assertTrue($hook->supportsBackup());
    }

    public function testDoesNotSupportRestoreMethod()
    {
        $app = $this->bootingApp();

        $hook = new DeleteFailedBackupData('foo');

        $this->assertFalse($hook->supportsRestore());
    }
    
    public function testDeletesFileStorageArtifact()
    {
        $app = $this->getApp();
        
        $reg = new FileStorageArtifactRegistry(
            id: 'file-storage',
            name: 'File Storage',
            storageName: 'uploads-private',
            folder: '',
        );
        
        $app->on(
            ArtifactRegistriesInterface::class,
            static function(ArtifactRegistriesInterface $registries) use ($reg): void {
                $registries->add($reg);
            }
        );
        
        $fileStorage = $this->fakeFileStorage();
        $fakeQueue = $this->fakeQueue();
        $app = $this->bootingApp();
        
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);

        $repo = $reg->createRepository($app->container());
        $repo->create(['path' => 'backup-1']);
        
        $process = $this->createBackupProcess(app: $app, name: 'backup', artifacts: ['file-storage']);
        
        $hook = new DeleteFailedBackupData('Auto Delete');
        $handler = $hook->createHookHandler($app->container());
        
        $this->assertNotNull($restoreRepo->findById('backup-1'));
        
        $handler->processFailed($process, new \RuntimeException('failure'));

        // deletion is queued
        $fakeQueue->queue(name: 'file')->assertPushed(DeleteArtifactJobHandler::class);
        $this->assertNotNull($restoreRepo->findById('backup-1'));
        
        // clear because of unique job
        $fakeQueue->clearQueue(queue: $fakeQueue->queue(name: 'file'));
    }

    public function testLogsWarningOnDeleteException()
    {
        $app = $this->getApp();
        
        $reg = new FileStorageArtifactRegistry(
            id: 'file-storage',
            name: 'File Storage',
            storageName: 'uploads-private',
            folder: '',
        );
        
        $app->on(
            ArtifactRegistriesInterface::class,
            static function(ArtifactRegistriesInterface $registries) use ($reg): void {
                $registries->add($reg);
            }
        );
        
        $fileStorage = $this->fakeFileStorage();
        $fakeQueue = $this->fakeQueue();
        $fakeLogging = $this->fakeLogging();
        $app = $this->bootingApp();
        
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);

        $repo = $reg->createRepository($app->container());
        $repo->create(['path' => 'backup-1']);
        
        $process = $this->createBackupProcess(app: $app, name: 'backup-missing', artifacts: ['file-storage']);
        
        $hook = new DeleteFailedBackupData('Auto Delete');
        $handler = $hook->createHookHandler($app->container());
        
        $this->assertNotNull($restoreRepo->findById('backup-1'));
        
        $handler->processFailed($process, new \RuntimeException('failure'));
                
        $fakeLogging->logger()->assertLogged(
            fn(LogEntry $log): bool =>
                $log->level === 'warning'
                && $log->message === 'Failed to auto-delete failed backup artifact'
                && $log->context['artifactId'] === 'backup-missing-1'
                && $log->context['processId'] === 1,
        );
        
        $this->assertNotNull($restoreRepo->findById('backup-1'));
        $fakeQueue->queue(name: 'file')->assertNothingPushed();
    }
}