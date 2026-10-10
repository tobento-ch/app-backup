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

namespace Tobento\App\Backup\Test\Feature\Task;

use Tobento\App\AppInterface;
use Tobento\App\Backup\Queue\BackupJobHandler;
use Tobento\App\Backup\Repo\BackupRepositoryInterface;
use Tobento\App\Backup\Task\BackupRegistry;
use Tobento\App\Crud\Action\Edit;
use Tobento\App\Crud\Field\Fields;
use Tobento\App\Task\TaskEntity;
use Tobento\Service\Queue\JobInterface;
use Tobento\Service\Schedule\Task\CallableTask;

class BackupRegistryTest extends \Tobento\App\Testing\TestCase
{
    use \Tobento\App\Testing\Database\RefreshDatabases;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        $app->boot(\Tobento\App\Task\Boot\Task::class);
        return $app;
    }
    
    public function testConfigureFieldsMethodReturnsFields()
    {
        $app = $this->bootingApp();
        
        $registry = new BackupRegistry(name: 'Backup');
        
        $editAction = new Edit();
        $editAction->setContainer($app->container());

        $fields = $registry->configureFields($editAction);
        $fields = Fields::fromIterable($fields);
        
        $this->assertTrue(in_array('data.backup.id', $fields->getNames()));
    }
    
    public function testCreateTaskMethodReturnsCallableTask()
    {
        $app = $this->bootingApp();
        
        $registry = new BackupRegistry(name: 'Backup');
        
        $task = $registry->createTask(
            container: $app->container(),
            taskEntity: new TaskEntity([]),
        );
        
        $this->assertInstanceof(CallableTask::class, $task);
    }

    public function testProcessTaskOutputs()
    {
        $fakeQueue = $this->fakeQueue();
        
        $app = $this->bootingApp();
        $backupRepo = $app->get(BackupRepositoryInterface::class);
        $backupRepo->create(['name' => 'FullBackup']);
        
        $registry = new BackupRegistry(name: 'Backup');
        
        $result = $registry->createTask(
            container: $app->container(),
            taskEntity: new TaskEntity(['data' => ['backup' => ['id' => 1]]]),
        )->processTask($app->container());
        
        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->isFailure());
        $this->assertFalse($result->isSkipped());
        $this->assertSame('Backup "FullBackup" has started and will continue in the background.', $result->output());
        
        $fakeQueue->queue(name: 'file')->assertPushed(
            BackupJobHandler::class,
            function (JobInterface $job): bool {
                return $job->getPayload()['backup_id'] === 1
                    && $job->getPayload()['user_id'] === null;
            }
        );
    }
    
    public function testProcessTaskOutputsBackupNotFoundIfMissing()
    {
        $fakeQueue = $this->fakeQueue();
        
        $app = $this->bootingApp();
        
        $registry = new BackupRegistry(name: 'Backup');
        
        $result = $registry->createTask(
            container: $app->container(),
            taskEntity: new TaskEntity(['data' => ['backup' => ['id' => 1]]]),
        )->processTask($app->container());
        
        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->isFailure());
        $this->assertFalse($result->isSkipped());
        $this->assertSame('Backup not found.', $result->output());
        
        $fakeQueue->queue(name: 'file')->assertNotPushed(BackupJobHandler::class);
    }
}