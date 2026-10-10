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

use Psr\Container\ContainerInterface;
use Tobento\App\AppInterface;
use Tobento\App\Backup\Hook\HooksInterface;
use Tobento\App\Backup\Process\RestoreProcessFactoryInterface;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Queue\RestoreJobHandler;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Backup\Repo\RestoreEntity;
use Tobento\App\Backup\Repo\RestoreRepositoryInterface;
use Tobento\App\Backup\Test\App;
use Tobento\App\Backup\Test\Factory;
use Tobento\Service\Queue\Job;
use Tobento\Service\Queue\Parameter;
use Tobento\Service\Queue\QueueInterface;

class RestoreJobHandlerTest extends \Tobento\App\Testing\TestCase
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
            name: RestoreJobHandler::class,
            payload: $payload,
        );
    }

    protected function createRestore(
        AppInterface $app,
        string $id,
        array $manifest,
        string $name = 'MyRestore',
        null|int $userId = null,
        null|string $domain = null,
    ): void {
        $registry = $app->get(ArtifactRegistriesInterface::class)->get('inmemory');
        
        $artifact = $registry->createReadableArtifact($app->container(), new ProcessEntity([]));
        
        $manifest = array_merge(['name' => $name], $manifest);
        $artifact->seedFile('manifest.json', json_encode(array_merge(
            $manifest,
            ['name' => $name, 'user_id' => $userId, 'domain' => $domain]
        )));
        
        $registry->setRepository(Factory::createRestoreRepository(
            new RestoreEntity(
                id: $id,
                name: $name,
                type: 'Zip',
                raw: null,
                registry: $registry,
                userId: $userId,
                domain: $domain,
                createdAt: new \DateTimeImmutable(),
            ),
        ));
    }
    
    public function testHandleJobMethodExecutesIncrementalRestore()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->bootingApp();

        $this->createRestore(
            app: $app,
            id: 'restore-1',
            manifest: [
                'process_id' => 1,
                'actions' => [
                    [
                        'class' => 'Tobento\\App\\Backup\\Test\\App\\StepsAction',
                        'data' => ['name' => 'a', 'steps' => 2],
                    ],
                ],
            ],
        );
        
        $handler = new RestoreJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            processFactory: $app->get(RestoreProcessFactoryInterface::class),
            hooks: $app->get(HooksInterface::class),
            queue: $app->get(QueueInterface::class),
            container: $app->container(),
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-1',
            'user_id' => null,
        ]);

        // First run → step 1 → requeue
        $handler->handleJob($job);

        $fakeQueue->queue(name: 'sync')->assertPushedTimes(RestoreJobHandler::class, 1);

        // Second run → step 2 → finish
        $handler->handleJob($job);

        // Retrieve process
        $processId = $job->parameters()->get(Parameter\Data::class)->get('process_id');
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);

        // Assertions
        $this->assertSame(['a'], $processEntity->processedActions());
        $this->assertSame('MyRestore', $processEntity->name());
    }

    public function testHandleJobMethodExecutesMultipleActionsInOrder()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        // Register hook
        $app->on(HooksInterface::class, function($hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        $this->bootingApp();

        $this->createRestore(
            app: $app,
            id: 'restore-multi',
            manifest: [
                'process_id' => 1,
                'actions' => [
                    [
                        'class' => 'Tobento\\App\\Backup\\Test\\App\\StepsAction',
                        'data' => ['name' => 'a', 'steps' => 1],
                    ],
                    [
                        'class' => 'Tobento\\App\\Backup\\Test\\App\\StepsAction',
                        'data' => ['name' => 'b', 'steps' => 2],
                    ],
                ],
            ],
        );

        $handler = new RestoreJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            processFactory: $app->get(RestoreProcessFactoryInterface::class),
            hooks: $app->get(HooksInterface::class),
            queue: $app->get(QueueInterface::class),
            container: $app->container(),
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-multi',
            'input' => ['hooks' => ['hook-a']],
        ]);

        // A step 1 → finished
        $handler->handleJob($job);

        $processId = $job->parameters()->get(Parameter\Data::class)->get('process_id');
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);
        $this->assertSame(['a'], $processEntity->processedActions());

        // B step 1
        $handler->handleJob($job);

        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);
        $this->assertSame(['a'], $processEntity->processedActions());

        // B step 2 → finished
        $handler->handleJob($job);

        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);
        $this->assertSame(['a', 'b'], $processEntity->processedActions());

        // processFinished
        $handler->handleJob($job);

        $this->assertSame([
            'processStarted',

            // A step 1
            'actionStepExecuting',
            'actionStepExecuted',
            'actionFinished',

            // B step 1
            'actionStepExecuting',
            'actionStepExecuted',

            // B step 2
            'actionStepExecuting',
            'actionStepExecuted',
            'actionFinished',

            'processFinished',
        ], $app->get(HooksInterface::class)->get('hook-a')->calledEvents());
    }
    
    public function testHandleJobMethodProcessStartedIsTriggeredOnlyOnce()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        // Register hook
        $app->on(HooksInterface::class, function($hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });
        
        $this->bootingApp();
        
        $this->createRestore(
            app: $app,
            id: 'restore-3',
            manifest: [
                'process_id' => 1,
                'actions' => [
                    [
                        'class' => 'Tobento\\App\\Backup\\Test\\App\\StepsAction',
                        'data' => ['name' => 'a', 'steps' => 2],
                    ],
                ],
            ],
        );

        $handler = new RestoreJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            processFactory: $app->get(RestoreProcessFactoryInterface::class),
            hooks: $app->get(HooksInterface::class),
            queue: $app->get(QueueInterface::class),
            container: $app->container(),
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-3',
            'input' => ['hooks' => ['hook-a']],
        ]);

        // Step 1
        $handler->handleJob($job);

        // Step 2
        $handler->handleJob($job);       

        $events = $app->get(HooksInterface::class)->get('hook-a')->calledEvents();
        
        $this->assertSame(
            1,
            count(array_filter($events, fn($e) => $e === 'processStarted'))
        );
    }
    
    public function testHandleJobMethodHooksAreExecutedInCorrectOrder()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->bootingApp();

        $app->on(HooksInterface::class, function($hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        $this->createRestore(
            app: $app,
            id: 'restore-hooks',
            manifest: [
                'process_id' => 1,
                'actions' => [
                    [
                        'class' => 'Tobento\\App\\Backup\\Test\\App\\StepsAction',
                        'data' => ['name' => 'a', 'steps' => 1],
                    ],
                ],
            ],
        );

        $handler = new RestoreJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            processFactory: $app->get(RestoreProcessFactoryInterface::class),
            hooks: $app->get(HooksInterface::class),
            queue: $app->get(QueueInterface::class),
            container: $app->container(),
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-hooks',
            'input' => ['hooks' => ['hook-a']],
        ]);

        // Step 1 → finished
        $handler->handleJob($job);

        // processFinished
        $handler->handleJob($job);

        $this->assertSame([
            'processStarted',
            'actionStepExecuting',
            'actionStepExecuted',
            'actionFinished',
            'processFinished',
        ], $app->get(HooksInterface::class)->get('hook-a')->calledEvents());
    }
    
    public function testHandleJobMethodMandatoryProcessLifecycleHookIsExecuted()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        // Register a user-defined hook (optional)
        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'user-hook'));
        });

        $this->bootingApp();

        // Create restore with 1-step action
        $this->createRestore(
            app: $app,
            id: 'restore-mandatory',
            manifest: [
                'process_id' => 1,
                'actions' => [
                    [
                        'class' => 'Tobento\\App\\Backup\\Test\\App\\StepsAction',
                        'data' => ['name' => 'a', 'steps' => 1],
                    ],
                ],
            ],
        );

        $handler = new RestoreJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            processFactory: $app->get(RestoreProcessFactoryInterface::class),
            hooks: $app->get(HooksInterface::class),
            queue: $app->get(QueueInterface::class),
            container: $app->container(),
        );

        // IMPORTANT: no user hooks → only mandatory hook runs
        $job = $this->createQueueJob([
            'restore_id' => 'restore-mandatory',
            'input' => [], // no hooks
        ]);

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
        $app = $this->bootingApp();

        $app->on(HooksInterface::class, function($hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
            $hooks->add(Factory::createHook(id: 'hook-b'));
        });

        $this->createRestore(
            app: $app,
            id: 'restore-filter',
            manifest: [
                'process_id' => 1,
                'actions' => [
                    [
                        'class' => 'Tobento\\App\\Backup\\Test\\App\\StepsAction',
                        'data' => ['name' => 'a', 'steps' => 1],
                    ],
                ],
            ],
        );

        $handler = new RestoreJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            processFactory: $app->get(RestoreProcessFactoryInterface::class),
            hooks: $app->get(HooksInterface::class),
            queue: $app->get(QueueInterface::class),
            container: $app->container(),
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-filter',
            'input' => ['hooks' => ['hook-a']],
        ]);

        // Step 1 → finished
        $handler->handleJob($job);

        // processFinished
        $handler->handleJob($job);

        $hooks = $app->get(HooksInterface::class);

        $this->assertNotEmpty($hooks->get('hook-a')->calledEvents());
        $this->assertEmpty($hooks->get('hook-b')->calledEvents());
    }
    
    public function testHandleJobMethodProcessFailedTriggersHook()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->bootingApp();

        $app->on(HooksInterface::class, function($hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        $this->createRestore(
            app: $app,
            id: 'restore-fail',
            manifest: [
                'process_id' => 1,
                'actions' => [
                    [
                        'class' => 'Tobento\\App\\Backup\\Test\\App\\FailAction',
                        'data' => ['name' => 'fail'],
                    ],
                ],
            ],
        );

        $handler = new RestoreJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            processFactory: $app->get(RestoreProcessFactoryInterface::class),
            hooks: $app->get(HooksInterface::class),
            queue: $app->get(QueueInterface::class),
            container: $app->container(),
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-fail',
            'input' => ['hooks' => ['hook-a']],
        ]);

        try {
            $handler->handleJob($job);
        } catch (\Throwable $e) {
            // Expected
        }

        $this->assertSame([
            'processStarted',
            'actionStepExecuting',
            'processFailed',
        ], $app->get(HooksInterface::class)->get('hook-a')->calledEvents());
    }
    
    public function testHandleJobMethodUserIdIsPropagated()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->bootingApp();

        $app->on(HooksInterface::class, function($hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        $this->createRestore(
            app: $app,
            id: 'restore-user',
            manifest: [
                'process_id' => 1,
                'actions' => [
                    [
                        'class' => 'Tobento\\App\\Backup\\Test\\App\\StepsAction',
                        'data' => ['name' => 'a', 'steps' => 1],
                    ],
                ],
            ],
            userId: 777,
        );

        $handler = new RestoreJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            processFactory: $app->get(RestoreProcessFactoryInterface::class),
            hooks: $app->get(HooksInterface::class),
            queue: $app->get(QueueInterface::class),
            container: $app->container(),
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-user',
            'user_id' => 777,
            'input' => ['hooks' => ['hook-a']],
        ]);

        $handler->handleJob($job);

        $processId = $job->parameters()->get(Parameter\Data::class)->get('process_id');
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);

        $this->assertSame(777, $processEntity->userId());

        $process = $app->get(RestoreProcessFactoryInterface::class)->createProcess(
            restoreEntity: $app->get(RestoreRepositoryInterface::class)->findById('restore-user'),
            processId: $processId,
        );

        $this->assertSame(777, $process->userId());
    }
    
    public function testHandleJobMethodThrowsExceptionIfRestoreEntityNotFound()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->bootingApp();

        $handler = new RestoreJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            processFactory: $app->get(RestoreProcessFactoryInterface::class),
            hooks: $app->get(HooksInterface::class),
            queue: $app->get(QueueInterface::class),
            container: $app->container(),
        );

        $job = $this->createQueueJob(['restore_id' => 'unknown']);

        $this->expectException(\RuntimeException::class);

        $handler->handleJob($job);
    }
}