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
use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\Hook\ProcessLifecycle;
use Tobento\App\Backup\Hook\HookInterface;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Backup\Test\Factory;
use Tobento\App\Testing\Database\RefreshDatabases;
use Tobento\App\Testing\TestCase;

class ProcessLifecycleTest extends TestCase
{
    use RefreshDatabases;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        return $app;
    }

    public function testImplementsHookInterface()
    {
        $app = $this->bootingApp();
        
        $hook = new ProcessLifecycle('Lifecycle');
        $this->assertInstanceOf(HookInterface::class, $hook);
    }

    public function testIdMethod()
    {
        $app = $this->bootingApp();
        
        $hook = new ProcessLifecycle('Lifecycle');
        $this->assertSame('process.lifecycle', $hook->id());
    }

    public function testNameMethod()
    {
        $app = $this->bootingApp();
        
        $hook = new ProcessLifecycle('Lifecycle');
        $this->assertSame('Lifecycle', $hook->name());
    }

    public function testGroupMethod()
    {
        $app = $this->bootingApp();
        
        $this->assertSame('Process Lifecycle', (new ProcessLifecycle('foo'))->group());
        $this->assertSame('Custom', new ProcessLifecycle(name: 'foo', group: 'Custom')->group());
    }

    public function testDefaultSelectedMethod()
    {
        $app = $this->bootingApp();
        
        $this->assertFalse(new ProcessLifecycle('foo')->defaultSelected());
    }

    public function testSupportsBackupMethod()
    {
        $app = $this->bootingApp();
        
        $this->assertTrue(new ProcessLifecycle('foo')->supportsBackup());
    }

    public function testSupportsRestoreMethod()
    {
        $app = $this->bootingApp();
        
        $this->assertTrue(new ProcessLifecycle('foo')->supportsRestore());
    }

    public function testCreateHookHandlerReturnsHookHandlerInterface()
    {
        $app = $this->bootingApp();
        
        $handler = new ProcessLifecycle('foo')->createHookHandler($app->container());
        $this->assertInstanceOf(\Tobento\App\Backup\Hook\HookHandlerInterface::class, $handler);
    }

    protected function createProcess(AppInterface $app, array $options = []): \Tobento\App\Backup\Process\ProcessInterface
    {
        $repo = $app->get(ProcessRepositoryInterface::class);

        $entity = $repo->create([
            'type' => 'backup',
            'status' => 'pending',
            'name' => 'My Backup',
        ]);

        $backupEntity = new BackupEntity([
            'id' => 1,
            'name' => 'My Backup',
            'options' => $options,
            'meta' => [],
        ]);

        $factory = $app->get(BackupProcessFactoryInterface::class);

        return $factory->createProcess(
            backupEntity: $backupEntity,
            processId: $entity->id(),
            userId: null
        );
    }

    public function testProcessStartedUpdatesStatus()
    {
        $app = $this->bootingApp();
        $repo = $app->get(ProcessRepositoryInterface::class);

        $process = $this->createProcess($app);

        $hook = new ProcessLifecycle('Lifecycle')->createHookHandler($app->container());
        $hook->processStarted($process);

        $updated = $repo->findById($process->id());
        $this->assertSame('processing', $updated->status());
    }

    public function testActionStepExecutingSetsStepStartTime()
    {
        $app = $this->bootingApp();
        $process = $this->createProcess($app);

        $action = new NullAction('test.action');

        $hook = new ProcessLifecycle('Lifecycle')->createHookHandler($app->container());
        $hook->actionStepExecuting($action, $process);

        $state = $process->stateFor($action);

        $this->assertArrayHasKey('step_started_at', $state);
        $this->assertIsFloat($state['step_started_at']);
        $this->assertArrayHasKey('runtime', $state);
        $this->assertSame(0, $state['runtime']);
    }

    public function testActionStepExecutedAccumulatesRuntime()
    {
        $app = $this->bootingApp();
        $process = $this->createProcess($app);

        $action = new NullAction('test.action');

        $hook = new ProcessLifecycle('Lifecycle')->createHookHandler($app->container());

        $hook->actionStepExecuting($action, $process);
        usleep(50000); // 50ms
        $hook->actionStepExecuted($action, $process);

        $state = $process->stateFor($action);

        $this->assertArrayHasKey('runtime', $state);
        $this->assertGreaterThan(0.0, $state['runtime']);
        $this->assertNull($state['step_started_at']);
    }
    
    public function testProcessFinishedUpdatesStatusAndRuntimeAndPeakMemory()
    {
        $this->fakeConfig()->with('backup.actions', [
            Factory::createActionRegistry(id: 'test.action'),
        ]);

        $app = $this->bootingApp();
        $repo = $app->get(ProcessRepositoryInterface::class);
        $factory = $app->get(BackupProcessFactoryInterface::class);

        $backupEntity = Factory::createBackupEntity(['name' => 'My Backup']);

        $processA = $factory->createProcess(
            backupEntity: $backupEntity,
            processId: null,
            userId: null
        );

        $action = $processA->actions()->get('test.action');
        $this->assertNotNull($action);

        $hook = new ProcessLifecycle('Lifecycle')->createHookHandler($app->container());

        $hook->actionStepExecuting($action, $processA);
        usleep(30000);
        $hook->actionStepExecuted($action, $processA);

        $processB = $factory->createProcess(
            backupEntity: $backupEntity,
            processId: $processA->id(),
            userId: null
        );

        $hook->processFinished($processB);

        $updated = $repo->findById($processA->id());

        $this->assertSame('completed', $updated->status());
        $this->assertGreaterThan(0.0, $updated->runtimeInSeconds());
        $this->assertGreaterThan(0, $updated->memoryPeakBytes());
    }

    public function testProcessFailedUpdatesStatus()
    {
        $app = $this->bootingApp();
        $repo = $app->get(ProcessRepositoryInterface::class);

        $process = $this->createProcess($app);

        $hook = new ProcessLifecycle('Lifecycle')->createHookHandler($app->container());
        $hook->processFailed($process, new \RuntimeException('fail'));

        $updated = $repo->findById($process->id());
        $this->assertSame('failed', $updated->status());
    }
}