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

namespace Tobento\App\Backup\Test;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\Hook\HookInterface;
use Tobento\App\Backup\Hook\HookHandlerInterface;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Process\ProcessInterface;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Backup\Registry\Action\ActionRegistryInterface;
use Tobento\App\Backup\Repo;
use Tobento\App\Backup\Test\App;
use Tobento\App\Crud\Action\ActionInterface as CrudActionInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Input\InputInterface;
use Tobento\Service\Container\Container;
use Tobento\Service\Encryption\Crypto\KeyGenerator;
use Tobento\Service\Encryption\Crypto\EncrypterFactory;
use Tobento\Service\Encryption\EncrypterInterface;
use Tobento\Service\Repository\RepositoryCreateException;
use Tobento\Service\Repository\RepositoryDeleteException;
use Tobento\Service\Repository\RepositoryUpdateException;
use Tobento\Service\Storage\InMemoryStorage;
use Tobento\Service\Storage\Tables\Tables;

class Factory
{
    public static function createContainer(): ContainerInterface
    {
        return new Container();
    }
    
    /**
     * Create a new stream factory.
     */
    public static function createStreamFactory(): StreamFactoryInterface
    {
        return new Psr17Factory();
    }
    
    /**
     * Create a new encrypter.
     */
    public static function createEncrypter(): EncrypterInterface
    {
        $key = new KeyGenerator()->generateKey();
        $encrypterFactory = new EncrypterFactory();
        
        return $encrypterFactory->createEncrypter(
            name: 'crypto',
            config: ['key' => $key],
        );
    }
    
    public static function createProcessRepository(): Repo\ProcessRepositoryInterface
    {
        $tables = new Tables();
        $tables->add('backup_processes', [
            'id',
            'type',
            'type_id',
            'user_id',
            'status',
            'name',
            'artifacts',
            'actions',
            'processed_actions',
            'action_states',
            'hooks',
            'meta',
            'runtime_seconds',
            'memory_peak_bytes',
            'created_at',
        ], 'id');

        return new Repo\ProcessStorageRepository(
            storage: new InMemoryStorage([], $tables),
            table: 'backup_processes',
            processEntityFactory: new Repo\ProcessEntityFactory(),
            request: null,
        );
    }
    
    /**
     * Create a minimal, valid BackupEntity for tests that don't care
     * about specific options beyond what's structurally required.
     */
    public static function createBackupEntity(array $overrides = []): Repo\BackupEntityInterface
    {
        return new Repo\BackupEntity(array_replace([
            'id' => 1,
            'name' => 'Test Backup',
            'status' => 'ready',
            'user_id' => 0,
            'created_at' => time(),
            'options' => [
                'artifacts' => [],
                'app' => ['dir' => ['root' => []]],
                'hooks' => [],
            ],
        ], $overrides));
    }
    
    /**
     * Create a minimal, valid RestorEntity for tests that don't care
     * about specific options beyond what's structurally required.
     */
    public static function createRestoreEntity(array $overrides = []): Repo\RestoreEntityInterface
    {
        $defaults = [
            'id' => '10',
            'name' => 'RestoreJob',
            'type' => 'restore',
            'raw' => 'raw-source',
            'userId' => 99,
            'domain' => 'example.com',
            'createdAt' => null,
        ];

        $data = array_replace($defaults, $overrides);

        return new Repo\RestoreEntity(
            id: $data['id'],
            name: $data['name'],
            type: $data['type'],
            raw: $data['raw'],
            registry:  new App\InMemoryArtifactRegistry(),
            userId: $data['userId'],
            domain: $data['domain'],
            createdAt: $data['createdAt'],
        );
    }
    
    /**
     * Minimal in-memory RestoreRepositoryInterface implementation.
     * Only findById() is actually exercised by RestoreJobHandler; the rest
     * is filled in just to satisfy the interface.
     */
    public static function createRestoreRepository(Repo\RestoreEntityInterface ...$entities): Repo\RestoreRepositoryInterface
    {
        return new class(...$entities) implements Repo\RestoreRepositoryInterface {
            /** @var array<string, Repo\RestoreEntityInterface> */
            private array $entities = [];

            public function __construct(Repo\RestoreEntityInterface ...$entities)
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
                $entity = $this->entities[(string) $id] ?? null;

                if ($entity === null) {
                    throw new RepositoryDeleteException(
                        message: sprintf('Entity with id "%s" not found.', $id),
                    );
                }

                unset($this->entities[(string) $id]);

                return $entity;
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

    /**
     * Create a fake action registry that always produces a real,
     * non-null action with the given id - useful for tests that need
     * at least one real action without caring what it does.
     */
    public static function createActionRegistry(
        string $id = 'test.action',
        string $name = 'Test Action',
    ): ActionRegistryInterface {
        return new class($id, $name) implements ActionRegistryInterface {
            public function __construct(
                private string $id,
                private string $name,
            ) {}

            public function id(): string
            {
                return $this->id;
            }

            public function name(): string
            {
                return $this->name;
            }

            public function configureFields(CrudActionInterface $action): iterable|FieldsInterface
            {
                return [];
            }

            public function createAction(
                ContainerInterface $container,
                Repo\BackupEntityInterface $entity
            ): ActionInterface {
                return new NullAction(id: $this->id);
            }
        };
    }
    
    public static function createFailingAction(
        string $name = 'fail',
        ?\Throwable $exception = null,
    ): ActionInterface {
        return new class($name, $exception ?? new \RuntimeException('boom')) implements ActionInterface {

            public function __construct(
                private string $name,
                private \Throwable $exception,
            ) {}

            public function id(): string
            {
                return $this->name;
            }

            public function backup(
                BackupProcessInterface $process,
                ContainerInterface $container
            ): void {
                throw $this->exception;
            }

            public function restore(
                RestoreProcessInterface $process,
                ContainerInterface $container
            ): void {
                throw $this->exception;
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
     * Create a simple test hook that records all lifecycle events.
     *
     * @param string $id
     * @return HookInterface
     */
    public static function createHook(string $id): HookInterface
    {
        return new class($id) implements HookInterface {

            private array $called = [];

            public function __construct(private string $id) {}

            public function id(): string
            {
                return $this->id;
            }

            public function name(): string
            {
                return $this->id;
            }

            public function group(): string
            {
                return 'test';
            }

            public function defaultSelected(): bool
            {
                return false;
            }

            public function supportsBackup(): bool
            {
                return true;
            }

            public function supportsRestore(): bool
            {
                return true;
            }

            /**
             * Retrieve all recorded events.
             *
             * @return array<int, string>
             */
            public function calledEvents(): array
            {
                return $this->called;
            }

            public function createHookHandler(ContainerInterface $container): HookHandlerInterface
            {
                // Capture reference to $called so handler can append events
                $called =& $this->called;

                return new class($called) implements HookHandlerInterface {

                    public function __construct(private array &$called) {}

                    private function record(string $event): void
                    {
                        $this->called[] = $event;
                    }

                    public function processStarted(ProcessInterface $process): void
                    {
                        $this->record('processStarted');
                    }

                    public function actionStepExecuting(
                        ActionInterface $action,
                        ProcessInterface $process
                    ): void {
                        $this->record('actionStepExecuting');
                    }

                    public function actionStepExecuted(
                        ActionInterface $action,
                        ProcessInterface $process
                    ): void {
                        $this->record('actionStepExecuted');
                    }

                    public function actionFinished(
                        ActionInterface $action,
                        ProcessInterface $process
                    ): void {
                        $this->record('actionFinished');
                    }

                    public function processFinished(ProcessInterface $process): void
                    {
                        $this->record('processFinished');
                    }

                    public function processFailed(
                        ProcessInterface $process,
                        \Throwable $exception
                    ): void {
                        $this->record('processFailed');
                    }
                };
            }
        };
    }
}