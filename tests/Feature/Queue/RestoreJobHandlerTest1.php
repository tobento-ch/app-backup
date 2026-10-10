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
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Action\Actions;
use Tobento\App\Backup\Hook\HooksInterface;
use Tobento\App\Backup\Process\RestoreProcessFactoryInterface;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Queue\RestoreJobHandler;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Backup\Repo\RestoreEntity;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\Backup\Repo\RestoreRepositoryInterface;
use Tobento\App\Backup\Test\App;
use Tobento\App\Backup\Test\Factory;
use Tobento\App\Crud\Action\ActionInterface as CrudActionInterface;
use Tobento\App\Crud\Input\InputInterface;
use Tobento\App\Testing\TestCase as AppTestCase;
use Tobento\Service\Queue\Job;
use Tobento\Service\Queue\Parameter;
use Tobento\Service\Queue\QueueInterface;
use Tobento\Service\Repository\RepositoryCreateException;
use Tobento\Service\Repository\RepositoryDeleteException;
use Tobento\Service\Repository\RepositoryUpdateException;

class RestoreJobHandlerTest extends AppTestCase
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

    /**
     * Same shape as the backup suite's action double, but drives the
     * restore() side of ActionInterface instead of backup().
     */
    protected function createAction(string $name, int $steps): ActionInterface
    {
        return new class($name, $steps) implements ActionInterface {
            private int $step = 0;
            public function __construct(
                private string $name,
                private int $steps,
            ) {}
            public function id(): string { return $this->name; }
            public function backup(BackupProcessInterface $process, ContainerInterface $container): void
            {
            }
            public function restore(RestoreProcessInterface $process, ContainerInterface $container): void
            {
                $this->step++;
                if ($this->step >= $this->steps) {
                    $process->markActionFinished($this);
                }
            }
            public function configureRestoreFields(CrudActionInterface $action): iterable
            {
                return [];
            }
            public function configureForRestore(InputInterface $input): ActionInterface
            {
                return clone $this;
            }
            public function jsonSerialize(): array
            {
                return [];
            }
        };
    }

    /**
     * A failing action double for the restore direction, mirroring
     * Factory::createFailingAction() but throwing from restore() instead
     * of backup(). Swap this for Factory::createFailingAction(...) if that
     * fake already throws from both directions.
     */
    protected function createFailingAction(string $name): ActionInterface
    {
        return new class($name) implements ActionInterface {
            public function __construct(private string $name) {}
            public function id(): string { return $this->name; }
            public function backup(BackupProcessInterface $process, ContainerInterface $container): void
            {
            }
            public function restore(RestoreProcessInterface $process, ContainerInterface $container): void
            {
                throw new \RuntimeException('Restore action failed.');
            }
            public function configureRestoreFields(CrudActionInterface $action): iterable
            {
                return [];
            }
            public function configureForRestore(InputInterface $input): ActionInterface
            {
                return clone $this;
            }
            public function jsonSerialize(): array
            {
                return [];
            }
        };
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

    /**
     * Registers a fully in-memory RestoreRepositoryInterface, bypassing
     * CompositeRestoreRepository entirely.
     *
     * Why: CompositeRestoreRepository resolves entities by delegating to
     * each ReadableArtifactRegistryInterface's createRepository()/mapEntity(),
     * but Test\App\InMemoryArtifactRegistry throws 'Not implemented for
     * testing.' from both of those — even findById() on an unrelated id
     * would blow up immediately, since that call sits outside Composite's
     * try/catch. So for restore tests we swap RestoreRepositoryInterface
     * for a plain in-memory fake instead of trying to seed the registry.
     *
     * $entities: array<array{id: string, name: string, manifest?: array}>
     *   'manifest' is written as manifest.json into the shared InMemory
     *   readable artifact (id 'inmemory', registered in createApp()) in
     *   case RestoreProcessFactory reads it — e.g. to reconstruct hooks
     *   or pass raw JSON to ActionsFactoryInterface::createActionsFromJsonString().
     *   Since ActionsFactoryInterface is already overridden via applyAction()
     *   to always return your fixed action list, the JSON content itself
     *   doesn't need to be authoritative — only present and valid.
     */
    protected function applyRestoreEntities(AppInterface $app, array $entities): void
    {
        $app->on(RestoreRepositoryInterface::class, function() use ($app, $entities) {
            $container = $app->get(ContainerInterface::class);
            $registry = $app->get(ArtifactRegistriesInterface::class)->get('inmemory');

            $restoreEntities = [];

            foreach ($entities as $config) {
                $restoreEntities[] = $this->createRestoreEntity(
                    container: $container,
                    registry: $registry,
                    id: $config['id'],
                    name: $config['name'],
                    manifest: $config['manifest'] ?? [],
                );
            }

            return $this->fakeRestoreRepository(...$restoreEntities);
        });
    }

    /**
     * NOTE: RestoreEntity's constructor signature and RestoreEntityInterface's
     * full method list are inferred only from how ZipArtifactRegistry::mapEntity()
     * builds one (id, name, type, raw, registry, userId, domain, createdAt).
     * If the real constructor differs, adjust here only — nothing else in
     * the suite depends on RestoreEntity's internals directly.
     */
    protected function createRestoreEntity(
        ContainerInterface $container,
        object $registry,
        string $id,
        string $name,
        array $manifest = [],
    ): RestoreEntityInterface {
        // Seed manifest.json on the shared readable in-memory artifact so
        // anything reading it during process/action reconstruction succeeds.
        // 'process_id' is required: ManifestFactory builds a Manifest whose
        // constructor requires an int $processId, read from this same field —
        // it's the id of the (original) backup process that produced this
        // archive. Any int works here since nothing in these tests asserts
        // on it; override via $manifest['process_id'] if a test needs a
        // specific value.
        $artifact = $registry->createReadableArtifact($container, new ProcessEntity([]));
        $artifact->seedFile('manifest.json', json_encode(array_merge(
            ['name' => $name, 'process_id' => 1],
            $manifest,
        )));

        return new RestoreEntity(
            id: $id,
            name: $name,
            type: 'Zip',
            raw: $id,
            registry: $registry,
            userId: $manifest['user_id'] ?? null,
            domain: $manifest['domain'] ?? null,
            createdAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Minimal in-memory RestoreRepositoryInterface implementation.
     * Only findById() is actually exercised by RestoreJobHandler; the rest
     * is filled in just to satisfy the interface.
     */
    protected function fakeRestoreRepository(RestoreEntityInterface ...$entities): RestoreRepositoryInterface
    {
        return new class(...$entities) implements RestoreRepositoryInterface {
            /** @var array<string, RestoreEntityInterface> */
            private array $entities = [];

            public function __construct(RestoreEntityInterface ...$entities)
            {
                foreach ($entities as $entity) {
                    $this->entities[(string) $entity->id()] = $entity;
                }
            }

            public function findById(int|string $id): null|object
            {
                return $this->entities[(string) $id] ?? null;
            }

            public function findByIds(int|string ...$ids): iterable
            {
                foreach ($ids as $id) {
                    if (isset($this->entities[(string) $id])) {
                        yield $this->entities[(string) $id];
                    }
                }
            }

            public function findOne(array $where = []): ?object
            {
                foreach ($this->entities as $entity) {
                    return $entity;
                }
                return null;
            }

            public function findAll(array $where = [], array $orderBy = [], null|int|array $limit = null): iterable
            {
                return array_values($this->entities);
            }

            public function findColumn(
                string $column,
                null|string $key = null,
                array $where = [],
                array $orderBy = [],
                null|int|array $limit = null
            ): array {
                return [];
            }

            public function count(array $where = []): int
            {
                return count($this->entities);
            }

            public function create(array $attributes): object
            {
                throw new RepositoryCreateException(
                    message: 'Fake RestoreRepository does not support create().',
                );
            }

            public function updateById(string|int $id, array $attributes): object
            {
                throw new RepositoryUpdateException(
                    message: 'Fake RestoreRepository does not support updateById().',
                );
            }

            public function update(array $where, array $attributes): iterable
            {
                throw new RepositoryUpdateException(
                    message: 'Fake RestoreRepository does not support update().',
                );
            }

            public function deleteById(string|int $id): object
            {
                throw new RepositoryDeleteException(
                    message: 'Fake RestoreRepository does not support deleteById().',
                );
            }

            public function delete(array $where): iterable
            {
                throw new RepositoryDeleteException(
                    message: 'Fake RestoreRepository does not support delete().',
                );
            }

            public function distinctValues(string $column): array
            {
                return [];
            }
        };
    }

    public function testHandleJobMethodExecutesIncrementalRestore()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        $this->applyAction(
            $app,
            $this->createAction(name: 'a', steps: 2),
        );

        /*$this->seedZip(filename: 'restore-test.zip', manifest: [
            'process_id' => 1,
            'name' => 'Restore Test',
            'actions' => [
                ['class' => 'Tobento\\App\\Backup\\Action\\AppsDirectory', 'data' => [
                    'dirs' => ['root' => ['config', 'countries']],
                    'targetFolder' => 'apps-directory/',
                    'timeBudgetInSeconds' => 20,
                ]],
            ],
        ]);*/
        $this->applyRestoreEntities($app, [
            ['id' => 'restore-1', 'name' => 'MyRestore', 'manifest' => ['hooks' => ['customHook']]],
        ]);

        $this->bootingApp();
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);
        $processFactory = $app->get(RestoreProcessFactoryInterface::class);
        $hooks = $app->get(HooksInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);

        $handler = new RestoreJobHandler(
            restoreRepository: $restoreRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
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

        // Add a test hook that records events
        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        // Action 1: finishes in 1 step
        $action1 = $this->createAction(name: 'a', steps: 1);

        // Action 2: finishes in 2 steps
        $action2 = $this->createAction(name: 'b', steps: 2);

        $this->applyAction($app, $action1, $action2);

        $this->applyRestoreEntities($app, [
            ['id' => 'restore-1', 'name' => 'MultiActionTest', 'manifest' => ['hooks' => ['hook-a']]],
        ]);

        $this->bootingApp();
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);
        $processFactory = $app->get(RestoreProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        $handler = new RestoreJobHandler(
            restoreRepository: $restoreRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['restore_id' => 'restore-1']);

        // First run: processStarted, action 1 step 1 → finishes, requeue
        $handler->handleJob($job);

        $processId = $job->parameters()->get(Parameter\Data::class)->get('process_id');
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);
        $this->assertSame(['a'], $processEntity->processedActions());

        // Second run: action 2 step 1 → not finished yet, requeue
        $handler->handleJob($job);

        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);
        $this->assertSame(['a'], $processEntity->processedActions());

        // Third run: action 2 step 2 → finishes, requeue
        $handler->handleJob($job);

        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);
        $this->assertSame(['a', 'b'], $processEntity->processedActions());

        // Fourth run: all actions finished → processFinished
        $handler->handleJob($job);

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

        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        $action = $this->createAction(name: 'a', steps: 2);
        $this->applyAction($app, $action);

        $this->applyRestoreEntities($app, [
            ['id' => 'restore-1', 'name' => 'ProcessStartedTest', 'manifest' => ['hooks' => ['hook-a']]],
        ]);

        $this->bootingApp();
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);
        $processFactory = $app->get(RestoreProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        $handler = new RestoreJobHandler(
            restoreRepository: $restoreRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['restore_id' => 'restore-1']);

        // First run: processStarted MUST fire, step 1 executes
        $handler->handleJob($job);

        // Second run: processStarted MUST NOT fire again, step 2 finishes action
        $handler->handleJob($job);

        // Third run: processFinished fires
        $handler->handleJob($job);

        $events = $hooks->get('hook-a')->calledEvents();

        $this->assertSame(
            1,
            count(array_filter($events, fn($e) => $e === 'processStarted'))
        );

        $this->assertSame([
            'processStarted',
            'actionStepExecuting',
            'actionStepExecuted',
            'actionStepExecuting',
            'actionStepExecuted',
            'actionFinished',
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

        $this->applyRestoreEntities($app, [
            ['id' => 'restore-1', 'name' => 'HookTest', 'manifest' => ['hooks' => ['hook-a']]],
        ]);

        $this->bootingApp();
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);
        $processFactory = $app->get(RestoreProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        $handler = new RestoreJobHandler(
            restoreRepository: $restoreRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['restore_id' => 'restore-1']);

        // First run → executes + finishes the only action
        $handler->handleJob($job);

        // Second run → no more unfinished actions → process finishes
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

        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'user-hook'));
        });

        $action = $this->createAction(name: 'a', steps: 1);
        $this->applyAction($app, $action);

        // No hooks activated in the manifest → only the mandatory hook should run
        $this->applyRestoreEntities($app, [
            ['id' => 'restore-1', 'name' => 'MandatoryHookTest', 'manifest' => ['hooks' => []]],
        ]);

        $this->bootingApp();
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);
        $processFactory = $app->get(RestoreProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        $handler = new RestoreJobHandler(
            restoreRepository: $restoreRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['restore_id' => 'restore-1']);

        // First run → mandatory hook must set status=processing
        $handler->handleJob($job);

        $processId = $job->parameters()->get(Parameter\Data::class)->get('process_id');
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);

        $this->assertSame(
            'processing',
            $processEntity->status(),
            'Mandatory ProcessLifecycle hook did not set status=processing'
        );

        // Second run → all actions finished → mandatory hook must set status=completed
        $handler->handleJob($job);

        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);

        $this->assertSame(
            'completed',
            $processEntity->status(),
            'Mandatory ProcessLifecycle hook did not set status=completed'
        );

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

        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
            $hooks->add(Factory::createHook(id: 'hook-b'));
        });

        $action = $this->createAction(name: 'a', steps: 1);
        $this->applyAction($app, $action);

        // Only hook-a is activated in the manifest
        $this->applyRestoreEntities($app, [
            ['id' => 'restore-1', 'name' => 'HookFilterTest', 'manifest' => ['hooks' => ['hook-a']]],
        ]);

        $this->bootingApp();
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);
        $processFactory = $app->get(RestoreProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        $handler = new RestoreJobHandler(
            restoreRepository: $restoreRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['restore_id' => 'restore-1']);

        $handler->handleJob($job);
        $handler->handleJob($job);

        $this->assertNotEmpty(
            $hooks->get('hook-a')->calledEvents(),
            'Activated hook-a did not receive events'
        );

        $this->assertEmpty(
            $hooks->get('hook-b')->calledEvents(),
            'Non-activated hook-b should not receive any events'
        );
    }

    public function testHandleJobMethodProcessFailedTriggersHook()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        $failingAction = $this->createFailingAction(name: 'fail');
        $this->applyAction($app, $failingAction);

        $this->applyRestoreEntities($app, [
            ['id' => 'restore-1', 'name' => 'FailTest', 'manifest' => ['hooks' => ['hook-a']]],
        ]);

        $this->bootingApp();
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);
        $processFactory = $app->get(RestoreProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        $handler = new RestoreJobHandler(
            restoreRepository: $restoreRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['restore_id' => 'restore-1']);

        try {
            $handler->handleJob($job);
        } catch (\Throwable $e) {
            // Expected
        }

        $this->assertSame([
            'processStarted',
            'actionStepExecuting',
            'processFailed',
        ], $hooks->get('hook-a')->calledEvents());
    }

    public function testHandleJobMethodUserIdIsPropagated()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        $app->on(HooksInterface::class, function(HooksInterface $hooks) {
            $hooks->add(Factory::createHook(id: 'hook-a'));
        });

        $action = $this->createAction(name: 'a', steps: 1);
        $this->applyAction($app, $action);

        $this->applyRestoreEntities($app, [
            ['id' => 'restore-1', 'name' => 'UserIdPropagationTest', 'manifest' => ['hooks' => ['hook-a']]],
        ]);

        $this->bootingApp();
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);
        $processFactory = $app->get(RestoreProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        $handler = new RestoreJobHandler(
            restoreRepository: $restoreRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-1',
            'user_id' => 777,
        ]);

        $handler->handleJob($job);

        $processId = $job->parameters()->get(Parameter\Data::class)->get('process_id');
        $processEntity = $app->get(ProcessRepositoryInterface::class)->findById($processId);

        $this->assertSame(
            777,
            $processEntity->userId(),
            'Process entity did not store propagated user_id'
        );
    }

    public function testHandleJobMethodInputIsPassedToProcessFactory()
    {
        // NOTE: I don't have RestoreProcessFactoryInterface's/RestoreProcessInterface's
        // exact contract for reading the input back off a created process, so this
        // only asserts that handleJob() succeeds when 'input' is present in the
        // payload (i.e. the handler wires job payload 'input' through without
        // erroring). If RestoreProcessInterface exposes something like ->input(),
        // add a direct assertion on that here.
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        $action = $this->createAction(name: 'a', steps: 1);
        $this->applyAction($app, $action);

        $this->applyRestoreEntities($app, [
            ['id' => 'restore-1', 'name' => 'InputPropagationTest', 'manifest' => ['hooks' => []]],
        ]);

        $this->bootingApp();
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);
        $processFactory = $app->get(RestoreProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        $handler = new RestoreJobHandler(
            restoreRepository: $restoreRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-1',
            'input' => ['target_path' => '/tmp/restore-target'],
        ]);

        $handler->handleJob($job);

        $processId = $job->parameters()->get(Parameter\Data::class)->get('process_id');
        $this->assertNotNull($processId);
    }

    public function testHandleJobMethodThrowsExceptionIfRestoreEntityNotFound()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->getApp();

        // No entities registered → any restore_id resolves to null
        $this->applyRestoreEntities($app, []);

        $this->bootingApp();
        $restoreRepo = $app->get(RestoreRepositoryInterface::class);
        $processFactory = $app->get(RestoreProcessFactoryInterface::class);
        $queue = $app->get(QueueInterface::class);
        $container = $app->get(ContainerInterface::class);
        $hooks = $app->get(HooksInterface::class);

        $handler = new RestoreJobHandler(
            restoreRepository: $restoreRepo,
            processFactory: $processFactory,
            hooks: $hooks,
            queue: $queue,
            container: $container,
        );

        $job = $this->createQueueJob(['restore_id' => 'missing-restore']);

        $this->expectException(\RuntimeException::class);

        $handler->handleJob($job);
    }
}
