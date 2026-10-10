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
use Tobento\App\Backup\Hook\DeleteFinishedProcess;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Process\ProcessInterface;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Backup\Repo\ProcessStorageRepository;
use Tobento\App\Testing\TestCase;
use Tobento\App\Testing\Database\RefreshDatabases;
use Tobento\App\Testing\Logging\LogEntry;
use Tobento\Service\Repository\RepositoryDeleteException;

class DeleteFinishedProcessTest extends TestCase
{
    use RefreshDatabases;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        return $app;
    }

    public function testThatImplementsHookInterface()
    {
        $app = $this->bootingApp();

        $hook = new DeleteFinishedProcess('Delete');

        $this->assertInstanceOf(
            \Tobento\App\Backup\Hook\HookInterface::class,
            $hook
        );
    }
    
    public function testNameMethod()
    {
        $app = $this->bootingApp();

        $hook = new DeleteFinishedProcess('Delete Finished');

        $this->assertSame('Delete Finished', $hook->name());
    }

    public function testGroupMethod()
    {
        $app = $this->bootingApp();

        $this->assertSame('Maintenance', (new DeleteFinishedProcess('foo'))->group());
        $this->assertSame('Custom', (new DeleteFinishedProcess(name: 'foo', group: 'Custom'))->group());
    }

    public function testDefaultSelectedMethod()
    {
        $app = $this->bootingApp();

        $this->assertFalse((new DeleteFinishedProcess('foo'))->defaultSelected());
        $this->assertTrue((new DeleteFinishedProcess('foo', defaultSelected: true))->defaultSelected());
    }

    public function testSupportsBackupMethod()
    {
        $app = $this->bootingApp();

        $hook = new DeleteFinishedProcess('foo');

        $this->assertTrue($hook->supportsBackup());
    }

    public function testSupportsRestoreMethod()
    {
        $app = $this->bootingApp();

        $hook = new DeleteFinishedProcess('foo');

        $this->assertTrue($hook->supportsRestore());
    }
    
    public function testCreateHookHandlerMethodReturnsHookHandlerInterface()
    {
        $app = $this->bootingApp();

        $handler = new DeleteFinishedProcess('foo')->createHookHandler($app->container());

        $this->assertInstanceOf(\Tobento\App\Backup\Hook\HookHandlerInterface::class, $handler);
    }

    public function testProcessFinishedDeletesProcess()
    {
        $app = $this->bootingApp();
        $repo = $app->get(ProcessRepositoryInterface::class);

        $entity = $repo->create([
            'type' => 'backup',
            'status' => 'completed',
        ]);

        $backupEntity = new BackupEntity([
            'id' => 1,
            'name' => 'Test Backup',
            'options' => [
                'artifacts' => [],
                'hooks' => [],
            ],
            'meta' => [],
        ]);

        // Create a real BackupProcess (implements ProcessInterface)
        $factory = $app->get(BackupProcessFactoryInterface::class);

        $process = $factory->createProcess(
            backupEntity: $backupEntity,
            processId: $entity->id(),
            userId: null
        );

        // Run hook
        $hook = new DeleteFinishedProcess('Delete')->createHookHandler($app->container());
        $hook->processFinished($process);

        // Assert deletion
        $this->assertNull($repo->findById($entity->id()));
    }

    public function testProcessFinishedLogsWarningOnFailure()
    {
        $this->onCreateApp(function(AppInterface $app) {
            $app->on(ProcessRepositoryInterface::class, function(ProcessRepositoryInterface $repo) {

                return new class($repo) extends ProcessStorageRepository {

                    public function __construct(ProcessRepositoryInterface $repo)
                    {
                        parent::__construct(
                            storage: $repo->storage(),
                            table: $repo->table(),
                            processEntityFactory: $repo->entityFactory(),
                            request: $repo->request
                        );
                    }

                    public function deleteById(string|int $id): object
                    {
                        throw new RepositoryDeleteException('fail');
                    }
                };
            });
        });
        
        $fakeLogging = $this->fakeLogging();

        $app = $this->bootingApp();
        $repo = $app->get(ProcessRepositoryInterface::class);

        $entity = $repo->create([
            'type' => 'backup',
            'status' => 'completed',
        ]);

        $backupEntity = new BackupEntity([
            'id' => 1,
            'name' => 'Test Backup',
            'options' => [
                'artifacts' => [],
                'hooks' => [],
            ],
            'meta' => [],
        ]);

        $factory = $app->get(BackupProcessFactoryInterface::class);

        $process = $factory->createProcess(
            backupEntity: $backupEntity,
            processId: $entity->id(),
            userId: null
        );

        $hook = new DeleteFinishedProcess('Delete')->createHookHandler($app->container());
        $hook->processFinished($process);

        $fakeLogging->logger()->assertLogged(
            fn(LogEntry $log): bool =>
                $log->level === 'warning'
                && $log->message === 'Failed to auto-delete finished backup/restore process entity'
                && $log->context['processId'] === $entity->id()
        );
    }
}