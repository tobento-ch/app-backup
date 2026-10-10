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

namespace Tobento\App\Backup\Test\Feature\Queue;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Tobento\App\AppInterface;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Action\Actions;
use Tobento\App\Backup\Artifact\WritableArtifactFactoryInterface;
use Tobento\App\Backup\Hook\HookInterface;
use Tobento\App\Backup\Hook\HooksInterface;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Backup\Queue\BackupJobHandler;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Repo\BackupRepositoryInterface;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Backup\Test\App;
use Tobento\App\Backup\Test\Factory;
use Tobento\App\Crud\Action\ActionInterface as CrudActionInterface;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Input\InputInterface;
use Tobento\App\Testing\TestCase as AppTestCase;
use Tobento\Service\Queue\Job;
use Tobento\Service\Queue\Parameter;
use Tobento\Service\Queue\QueueInterface;

class BackupJobHandlerTest extends AppTestCase
{
    use App\Traits\InMemoryArtifactHelpers;
    use \Tobento\App\Testing\Database\RefreshDatabases;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');

        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        // Register InMemory artifact registry
        $app->on(
            ArtifactRegistriesInterface::class,
            static function($registries): void {
                $registries->add(new App\InMemoryArtifactRegistry(newInstance: false));
            }
        );

        return $app;
    }
    
    protected function createQueueJob(array $payload): Job
    {
        return new Job(
            name: BackupJobHandler::class,
            payload: $payload,
        );
    }
    
    protected function createAction(string $name, int $steps): ActionInterface
    {
        return new App\StepsAction(name: $name, steps: $steps);
    }
    
    protected function applyAction(AppInterface $app, ActionInterface ...$actions): void
    {
        $app->on(
            \Tobento\App\Backup\Action\ActionsFactoryInterface::class,
            static fn() => new class($actions) implements \Tobento\App\Backup\Action\ActionsFactoryInterface {

                public function __construct(private array $actions) {}

                public function createActionsFromBackupEntity($entity): Actions
                {
                    return new Actions(...$this->actions);
                }

                public function createActionsFromArray(array $data): Actions
                {
                    return new Actions(...$this->actions);
                }

                public function createActionsFromJsonString(string $json): Actions
                {
                    return new Actions(...$this->actions);
                }
            }
        );
    }

    public function testHandleJobMethodExecutesIncrementalBackup()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();
        
        $this->applyAction(
            $app,
            $this->createAction(name: 'a', steps: 2),
        );
        
        $this->bootingApp();
        $backupRepo = $app->get(BackupRepositoryInterface::class);
        $processFactory = $app->get(BackupProcessFactoryInterface::class);
        $hooks = $app->get(HooksInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);

        // Store entity in repository
        $backupRepo->create([
            'name' => 'MyBackup',
            'options' => [
                'artifacts' => [],
                'hooks' => ['customHook'],
            ],
        ]);
        
        $handler = new BackupJobHandler(
            backupRepository: $backupRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );
        
        $job = $this->createQueueJob([
            'backup_id' => 1,
            'user_id' => null,
        ]);

        // First run → step 1 → requeue
        $handler->handleJob($job);
        
        $fakeQueue->queue(name: 'sync')->assertPushedTimes(BackupJobHandler::class, 1);

        // Second run → step 2 → finish
        $handler->handleJob($job);
        
        $fakeQueue->queue(name: 'sync')->assertPushedTimes(BackupJobHandler::class, 1);

        // Retrieve process
        $processId = $job->parameters()->get(Parameter\Data::class)->get('process_id');
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);

        // Assertions
        $this->assertSame(['a'], $processEntity->processedActions());
        $this->assertSame('MyBackup', $processEntity->name());
        $this->assertSame(1, $processEntity->typeId());
    }
    
    public function testHandleJobMethodExecutesMultipleActionsInOrder()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        // Add a test hook that records events
        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        // Action 1: finishes in 1 step
        $action1 = $this->createAction(name: 'a', steps: 1);

        // Action 2: finishes in 2 steps
        $action2 = $this->createAction(name: 'b', steps: 2);

        // Register both actions
        $this->applyAction($app, $action1, $action2);

        $this->bootingApp();
        $backupRepo = $app->get(BackupRepositoryInterface::class);
        $processFactory = $app->get(BackupProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        // Create backup entity with hook-a enabled
        $backupRepo->create([
            'name' => 'MultiActionTest',
            'options' => ['artifacts' => [], 'hooks' => ['hook-a']],
        ]);

        $handler = new BackupJobHandler(
            backupRepository: $backupRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['backup_id' => 1]);

        // First run:
        // - processStarted
        // - Action 1 step 1 → finishes
        // - requeue for next action
        $handler->handleJob($job);
        
        $processId = $job->parameters()->get(Parameter\Data::class)->get('process_id');
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);
        
        $this->assertSame(['a'], $processEntity->processedActions());

        // Second run:
        // - Action 2 step 1 → finishes
        // - processFinished
        $handler->handleJob($job);
        
        // Assert both actions finished
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);
        $this->assertSame(['a'], $processEntity->processedActions());
        
        // Third run:
        // - Action 2 step 1 → finishes
        // - processFinished
        $handler->handleJob($job);

        // Assert both actions finished
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);
        $this->assertSame(['a', 'b'], $processEntity->processedActions());
        
        // Finished all
        $handler->handleJob($job);
        
        // Assert hook events in correct order
        $this->assertSame([
            'processStarted',

            // Action 1 (1 step)
            'actionStepExecuting',
            'actionStepExecuted',
            'actionFinished',

            // Action 2 step 1
            'actionStepExecuting',
            'actionStepExecuted',

            // Action 2 step 2
            'actionStepExecuting',
            'actionStepExecuted',
            'actionFinished',

            // Process finished
            'processFinished',
        ], $hooks->get('hook-a')->calledEvents());
    }
    
    public function testHandleJobMethodProcessStartedIsTriggeredOnlyOnce()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        // Add a test hook that records events
        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        // Action: finishes in 2 steps
        $action = $this->createAction(name: 'a', steps: 2);

        // Register action
        $this->applyAction($app, $action);

        $this->bootingApp();
        $backupRepo = $app->get(BackupRepositoryInterface::class);
        $processFactory = $app->get(BackupProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        // Create backup entity with hook-a enabled
        $backupRepo->create([
            'name' => 'ProcessStartedTest',
            'options' => ['artifacts' => [], 'hooks' => ['hook-a']],
        ]);

        $handler = new BackupJobHandler(
            backupRepository: $backupRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['backup_id' => 1]);

        // First run:
        // - processStarted MUST fire
        // - step 1 executes
        $handler->handleJob($job);

        // Second run:
        // - processStarted MUST NOT fire again
        // - step 2 executes → finishes action
        $handler->handleJob($job);

        // Third run:
        // - processFinished fires
        $handler->handleJob($job);

        // Retrieve hook events
        $events = $hooks->get('hook-a')->calledEvents();

        // Assert processStarted fired only once
        $this->assertSame(
            1,
            count(array_filter($events, fn($e) => $e === 'processStarted'))
        );

        // Assert full event sequence is correct
        $this->assertSame([
            'processStarted',

            // Step 1
            'actionStepExecuting',
            'actionStepExecuted',

            // Step 2
            'actionStepExecuting',
            'actionStepExecuted',
            'actionFinished',

            // Process finished
            'processFinished',
        ], $events);
    }
    
    public function testHandleJobMethodHooksAreExecutedInCorrectOrder()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        $this->applyAction(
            $app,
            $this->createAction(name: 'a', steps: 1),
        );

        $this->bootingApp();
        $backupRepo = $app->get(BackupRepositoryInterface::class);
        $processFactory = $app->get(BackupProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        $backupRepo->create([
            'name' => 'HookTest',
            'options' => ['artifacts' => [], 'hooks' => ['hook-a']],
        ]);

        $handler = new BackupJobHandler(
            backupRepository: $backupRepo,
            processFactory: $processFactory,
            hooks: $app->get(HooksInterface::class),
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['backup_id' => 1]);

        // First run → executes + finishes action
        $handler->handleJob($job);

        // Second run → process finishes
        $handler->handleJob($job);

        $this->assertSame([
            'processStarted',
            'actionStepExecuting',
            'actionStepExecuted',
            'actionFinished',
            'processFinished',
        ], $hooks->get('hook-a')->calledEvents());
    }
    
    public function testHandleJobMethodMandatoryProcessLifecycleHookIsExecuted()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        // Add a user-defined hook (optional)
        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'user-hook'));
        });

        // Action finishes in 1 step
        $action = $this->createAction(name: 'a', steps: 1);

        // Register action
        $this->applyAction($app, $action);

        $this->bootingApp();
        $backupRepo = $app->get(BackupRepositoryInterface::class);
        $processFactory = $app->get(BackupProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        // Create backup entity WITHOUT any hooks → only mandatory hook should run
        $backupRepo->create([
            'name' => 'MandatoryHookTest',
            'options' => ['artifacts' => ['inmemory'], 'hooks' => []],
        ]);

        $handler = new BackupJobHandler(
            backupRepository: $backupRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['backup_id' => 1]);

        // First run → step 1 → mandatory hook must set status=processing
        $handler->handleJob($job);

        $processId = $job->parameters()->get(Parameter\Data::class)->get('process_id');
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);

        $this->assertSame(
            'processing',
            $processEntity->status(),
            'Mandatory ProcessLifecycle hook did not set status=processing'
        );

        // Second run → finalize → mandatory hook must set status=completed
        $handler->handleJob($job);

        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);

        $this->assertSame(
            'completed',
            $processEntity->status(),
            'Mandatory ProcessLifecycle hook did not set status=completed'
        );

        // Mandatory hook also writes memory_peak_bytes and runtime_seconds
        $this->assertNotNull(
            $processEntity->memoryPeakBytes(),
            'Mandatory ProcessLifecycle hook did not write memory_peak_bytes'
        );

        $this->assertNotNull(
            $processEntity->runtimeInSeconds(),
            'Mandatory ProcessLifecycle hook did not write runtime_seconds'
        );
    }
    
    public function testHandleJobMethodOnlyActivatedHooksAreExecuted()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        // Register two hooks
        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
            $hooks->add(Factory::createHook(id: 'hook-b'));
        });

        // Action finishes in 1 step
        $action = $this->createAction(name: 'a', steps: 1);
        $this->applyAction($app, $action);

        $this->bootingApp();
        $backupRepo = $app->get(BackupRepositoryInterface::class);
        $processFactory = $app->get(BackupProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        // Only hook-a is activated
        $backupRepo->create([
            'name' => 'HookFilterTest',
            'options' => [
                'artifacts' => ['inmemory'],
                'hooks' => ['hook-a'], // hook-b is NOT activated
            ],
        ]);

        $handler = new BackupJobHandler(
            backupRepository: $backupRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['backup_id' => 1]);

        // First run → step 1
        $handler->handleJob($job);

        // Second run → finalize
        $handler->handleJob($job);

        // hook-a must have events
        $this->assertNotEmpty(
            $hooks->get('hook-a')->calledEvents(),
            'Activated hook-a did not receive events'
        );

        // hook-b must NOT have events
        $this->assertEmpty(
            $hooks->get('hook-b')->calledEvents(),
            'Non-activated hook-b should not receive any events'
        );
    }
    
    public function testHandleJobMethodProcessFailedTriggersHook()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        // Add a test hook that records events
        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        // Create an action that ALWAYS throws
        $failingAction = Factory::createFailingAction(name: 'fail');

        // Register failing action
        $this->applyAction($app, $failingAction);

        $this->bootingApp();
        $backupRepo = $app->get(BackupRepositoryInterface::class);
        $processFactory = $app->get(BackupProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        // Create backup entity with hook-a enabled
        $backupRepo->create([
            'name' => 'FailTest',
            'options' => ['artifacts' => [], 'hooks' => ['hook-a']],
        ]);

        $handler = new BackupJobHandler(
            backupRepository: $backupRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['backup_id' => 1]);

        // Run handler → must throw → hook must record processFailed
        try {
            $handler->handleJob($job);
        } catch (\Throwable $e) {
            // Expected
        }

        // Assert hook events
        $this->assertSame([
            'processStarted',
            'actionStepExecuting',
            'processFailed',
        ], $hooks->get('hook-a')->calledEvents());
    }
    
    public function testHandleJobMethodArtifactFinalizeIsCalledWhenAllActionsFinished()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        // Add hook (consistent with suite)
        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        // Action finishes in 2 steps
        $action = $this->createAction(name: 'a', steps: 2);

        // Register action
        $this->applyAction($app, $action);

        $this->bootingApp();
        $backupRepo = $app->get(BackupRepositoryInterface::class);
        $processFactory = $app->get(BackupProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        // Create backup entity
        $backupRepo->create([
            'name' => 'FinalizeTest',
            'options' => ['artifacts' => ['inmemory'], 'hooks' => ['hook-a']],
        ]);

        $handler = new BackupJobHandler(
            backupRepository: $backupRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['backup_id' => 1]);
        
        // First run → step 1 → requeue
        $handler->handleJob($job);
        
        // Retrieve the REAL artifact instance used by the handler
        $registries = $app->get(ArtifactRegistriesInterface::class);
        $registry = $registries->get('inmemory');
        $artifact = $registry->writableArtifact();

        // Second run → step 2 → action finished → requeue
        $handler->handleJob($job);
        
        $this->assertFalse(
            $artifact->wasFinalizeCalled(),
            'Artifact finalize() was called before all actions finished'
        );

        // Third run → finalize
        $handler->handleJob($job);
        
        $this->assertTrue(
            $artifact->wasFinalizeCalled(),
            'Artifact finalize() must be called after all actions finish'
        );
    }
    
    public function testHandleJobMethodUserIdIsPropagated()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        // Add hook (not relevant for user_id, but consistent with suite)
        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        // Action finishes in 1 step
        $action = $this->createAction(name: 'a', steps: 1);

        // Register action
        $this->applyAction($app, $action);

        $this->bootingApp();
        $backupRepo = $app->get(BackupRepositoryInterface::class);
        $processFactory = $app->get(BackupProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        // Create backup entity
        $backupRepo->create([
            'name' => 'UserIdPropagationTest',
            'options' => ['artifacts' => ['inmemory'], 'hooks' => ['hook-a']],
        ]);

        $handler = new BackupJobHandler(
            backupRepository: $backupRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        // Queue job with user_id
        $job = $this->createQueueJob([
            'backup_id' => 1,
            'user_id'   => 777,
        ]);

        // First run → step 1 → process created
        $handler->handleJob($job);

        // Retrieve process entity
        $processId = $job->parameters()->get(Parameter\Data::class)->get('process_id');
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);

        // Assert user_id persisted in process entity
        $this->assertSame(
            777,
            $processEntity->userId(),
            'Process entity did not store propagated user_id'
        );

        // Now load the runtime process via factory
        $process = $processFactory->createProcess(
            backupEntity: $backupRepo->findById(1),
            processId: $processId,
        );

        // Assert user_id propagated into BackupProcess runtime object
        $this->assertSame(
            777,
            $process->userId(),
            'BackupProcess did not receive propagated user_id'
        );
    }
    
    public function testHandleJobMethodThrowsExceptionIfBackupEntityNotFound()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        $this->bootingApp();
        $backupRepo = $app->get(BackupRepositoryInterface::class);
        $processFactory = $app->get(BackupProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        // IMPORTANT: no create() → Backup-ID does not exists

        $handler = new BackupJobHandler(
            backupRepository: $backupRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['backup_id' => 999]);

        $this->expectException(\RuntimeException::class);

        $handler->handleJob($job);
    }
}