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
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Backup\Repo\RestoreEntity;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\Backup\Repo\RestoreRepositoryInterface;
use Tobento\App\Backup\Task\PruneBackupsRegistry;
use Tobento\App\Backup\Test\App;
use Tobento\App\Crud\Action\Edit;
use Tobento\App\Crud\Field\Fields;
use Tobento\App\Task\TaskEntity;
use Tobento\Service\Repository\Storage\EntityFactory;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Schedule\Task\CallableTask;
use Tobento\Service\Storage\InMemoryStorage;
use Tobento\Service\Storage\Tables\Tables;

class PruneBackupsRegistryTest extends \Tobento\App\Testing\TestCase
{
    use \Tobento\App\Testing\Database\RefreshDatabases;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        $app->boot(\Tobento\App\Task\Boot\Task::class);
        
        // Override RestoreRepositoryInterface with writable repo
        $app->on(\Tobento\App\Backup\Repo\RestoreRepositoryInterface::class, function() {
            
            $entityFactory = new class() extends EntityFactory {
                public function createEntityFromArray(array $attributes): RestoreEntityInterface
                {
                    $attributes = $this->columns->processReading($attributes);
                    
                    $createdAt = null;

                    if (isset($attributes['created_at'])) {
                        // Convert int timestamp → DateTimeImmutable
                        $createdAt = is_int($attributes['created_at'])
                            ? new \DateTimeImmutable('@'.$attributes['created_at'])
                            : new \DateTimeImmutable($attributes['created_at']);
                    }
                    
                    return new RestoreEntity(
                        id: (string)$attributes['id'],
                        name: $attributes['name'],
                        type: 'Type',
                        raw: null,
                        registry: new App\InMemoryArtifactRegistry(),
                        createdAt: $createdAt,
                    );
                }
            };
            
            return new class(
                storage: new InMemoryStorage(
                    items: [],
                    tables: new Tables()->add('restore', ['id','name','created_at'], 'id')
                ),
                table: 'restore',
                entityFactory: $entityFactory,
            ) extends StorageRepository implements RestoreRepositoryInterface {
                public function distinctValues(string $column): array { return []; }
            };
        });
        
        return $app;
    }

    public function testConfigureFieldsMethodReturnsFields()
    {
        $app = $this->bootingApp();

        $registry = new PruneBackupsRegistry('Prune');

        $editAction = new Edit();
        $editAction->setContainer($app->container());

        $fields = Fields::fromIterable($registry->configureFields($editAction));

        $this->assertTrue(in_array('data.backup.days', $fields->getNames()));
        $this->assertTrue(in_array('data.backup.with_processes', $fields->getNames()));
    }

    public function testCreateTaskMethodReturnsCallableTask()
    {
        $app = $this->bootingApp();

        $registry = new PruneBackupsRegistry('Prune');

        $task = $registry->createTask(
            container: $app->container(),
            taskEntity: new TaskEntity([]),
        );

        $this->assertInstanceOf(CallableTask::class, $task);
    }

    public function testProcessTaskPrunesBackupsOnly()
    {
        $app = $this->bootingApp();

        $restoreRepo = $app->get(RestoreRepositoryInterface::class);

        // Create 2 backups: one old, one new
        $restoreRepo->create([
            'id' => 1,
            'name' => 'OldBackup',
            'created_at' => time() - 86400 * 30, // 30 days old
        ]);

        $restoreRepo->create([
            'id' => 2,
            'name' => 'NewBackup',
            'created_at' => time(), // fresh
        ]);

        $registry = new PruneBackupsRegistry('Prune');

        $result = $registry->createTask(
            container: $app->container(),
            taskEntity: new TaskEntity([
                'data' => [
                    'backup' => [
                        'days' => 10,
                        'with_processes' => '0',
                    ],
                ],
            ]),
        )->processTask($app->container());

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('1 backups and 0 processes older than 10 days have been pruned.', $result->output());

        $this->assertNull($restoreRepo->findById(1));
        $this->assertNotNull($restoreRepo->findById(2));
    }

    public function testProcessTaskPrunesBackupsAndProcesses()
    {
        $app = $this->bootingApp();

        $restoreRepo = $app->get(RestoreRepositoryInterface::class);
        $processRepo = $app->get(ProcessRepositoryInterface::class);

        // Old backup
        $restoreRepo->create([
            'id' => 1,
            'name' => 'OldBackup',
            'created_at' => time() - 86400 * 20,
        ]);

        // Old process
        $processRepo->create([
            'id' => 10,
            'type' => 'backup',
            'created_at' => time() - 86400 * 20,
        ]);

        // New backup
        $restoreRepo->create([
            'id' => 2,
            'name' => 'NewBackup',
            'created_at' => time(),
        ]);

        // New process
        $processRepo->create([
            'id' => 11,
            'type' => 'backup',
            'created_at' => time(),
        ]);

        $registry = new PruneBackupsRegistry('Prune');

        $result = $registry->createTask(
            container: $app->container(),
            taskEntity: new TaskEntity([
                'data' => [
                    'backup' => [
                        'days' => 10,
                        'with_processes' => '1',
                    ],
                ],
            ]),
        )->processTask($app->container());

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('1 backups and 1 processes older than 10 days have been pruned.', $result->output());

        $this->assertNull($restoreRepo->findById(1));
        $this->assertNotNull($restoreRepo->findById(2));

        $this->assertNull($processRepo->findById(10));
        $this->assertNotNull($processRepo->findById(11));
    }
}