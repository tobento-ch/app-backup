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
use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\AppInterface;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Process\DeleteProcessInterface;
use Tobento\App\Backup\Queue\DeleteArtifactJobHandler;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\QueueDeletionRegistryInterface;
use Tobento\App\Backup\Registry\Artifact\ReadableArtifactRegistryInterface;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\ProcessEntityInterface;
use Tobento\App\Backup\Repo\RestoreEntity;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\Backup\Repo\RestoreRepositoryInterface;
use Tobento\App\Backup\Test\App;
use Tobento\App\Backup\Test\Factory;
use Tobento\Service\Queue\Job;
use Tobento\Service\Queue\Parameter;
use Tobento\Service\Queue\QueueInterface;
use Tobento\App\User\UserRepositoryInterface;
use Tobento\Service\Repository\RepositoryDeleteException;
use Tobento\Service\Repository\RepositoryInterface;
use Tobento\Service\Notifier\ChannelMessagesInterface;
use Tobento\Service\Notifier\Notification;

class DeleteArtifactJobHandlerTest extends \Tobento\App\Testing\TestCase
{
    use App\Traits\InMemoryArtifactHelpers;
    use \Tobento\App\Testing\Database\RefreshDatabases;
    use \Tobento\App\Testing\FileStorage\RefreshFileStorages;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');

        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        // Register InMemory artifact registry
        $app->on(
            ArtifactRegistriesInterface::class,
            function($registries): void {
                $registries->add($this->createRegistry());
            }
        );

        return $app;
    }

    protected function createRegistry()
    {
        return new class implements ReadableArtifactRegistryInterface {
            private null|RepositoryInterface $repository = null;
            public array $items = [
                'restore-1' => ['id' => 'restore-1', 'name' => 'A', 'type' => 'folder'],
            ];

            public function id(): string { return 'test-delete'; }
            public function name(): string { return 'TestDelete'; }

            public function createReadableArtifact(
                ContainerInterface $container,
                ProcessEntityInterface $entity
            ): ReadableArtifactInterface {
                return new App\ReadableInMemoryArtifact(
                    streamFactory: $container->get(StreamFactoryInterface::class),
                );
            }
            
            public function setRepository(RepositoryInterface $repository): static
            {
                $this->repository = $repository;
                return $this;
            }

            public function createRepository(ContainerInterface $container): RepositoryInterface
            {
                if ($this->repository) {
                    return $this->repository;
                }

                throw new \RuntimeException('Not implemented for testing.');
            }

            public function mapEntity(mixed $raw, ContainerInterface $container): RestoreEntityInterface
            {
                if ($raw instanceof RestoreEntityInterface) {
                    return $raw;
                }

                throw new \RuntimeException('Not implemented for testing.');
            }

            public function createDeleteProcess(
                RestoreEntityInterface $restoreEntity,
                ContainerInterface $container
            ) {
                $registry = $this; // capture outer registry

                return new class($restoreEntity, $registry, $container) implements DeleteProcessInterface {
                    private int $i = 0;

                    public function __construct(
                        private RestoreEntityInterface $entity,
                        private $registry,
                        private ContainerInterface $container,
                    ) {}

                    public function hasRemainingItems(): bool
                    {
                        return $this->i < 2; // simulate 2 deletion steps
                    }

                    public function nextItem(): mixed
                    {
                        return $this->i++;
                    }

                    public function deleteItem(mixed $item): void
                    {
                        // Perform actual deletion using the real container
                        $repo = $this->registry->createRepository($this->container);
                        
                        try {
                            $repo->deleteById($this->entity->id());
                        } catch (RepositoryDeleteException $e) {
                            
                        }
                    }
                };
            }
        };
    }
    
    protected function createQueueJob(array $payload): Job
    {
        return new Job(
            name: DeleteArtifactJobHandler::class,
            payload: $payload,
        );
    }

    protected function createRestore(
        AppInterface $app,
        string $id,
        string $folder,
        null|int $userId = null,
        null|string $domain = null,
    ): void {
        $registry = $app->get(ArtifactRegistriesInterface::class)->get('test-delete');

        $artifact = $registry->createReadableArtifact($app->container(), new ProcessEntity([]));

        // Seed folder with two items → deletion process will have 2 steps
        $artifact->seedFile($folder.'/a.txt', 'A');
        $artifact->seedFile($folder.'/b.txt', 'B');

        $registry->setRepository(
            Factory::createRestoreRepository(
                new RestoreEntity(
                    id: $id,
                    name: 'MyRestore',
                    type: 'Folder',
                    raw: null,
                    registry: $registry,
                    userId: $userId,
                    domain: $domain,
                    createdAt: new \DateTimeImmutable(),
                ),
            )
        );
    }

    public function testHandleJobMethodExecutesDeletionProcess()
    {
        $fakeQueue = $this->fakeQueue();
        $app = $this->bootingApp();

        $this->createRestore(
            app: $app,
            id: 'restore-1',
            folder: 'restore-folder',
        );

        $handler = new DeleteArtifactJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            container: $app->container(),
            queue: $fakeQueue->queue(name: 'file'),
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-1',
            'user_id' => null,
            'timeBudget' => 999, // ensure full deletion
        ]);

        $handler->handleJob($job);

        // No requeue expected
        $fakeQueue->queue(name: 'file')->assertPushedTimes(DeleteArtifactJobHandler::class, 0);

        // Restore should now be deleted
        $this->assertNull(
            $app->get(RestoreRepositoryInterface::class)->findById('restore-1')
        );
    }

    public function testHandleJobMethodRequeuesWhenTimeBudgetExceeded()
    {
        $this->onCreateApp(function(AppInterface $app) {
            $app->on(BackupRuntimeConfigInterface::class, function() {
                return new class implements BackupRuntimeConfigInterface {
                    public function jobTimeBudgetInSeconds(): int
                    {
                        return 0;
                    }

                    public function queueName(): ?string
                    {
                        return 'file';
                    }
                    
                    public function encrypter(): string
                    {
                        return 'default';
                    }

                    public function encryptThroughputInBytes(): int
                    {
                        return 0;
                    }
                };
            });
        });
        
        $notifier = $this->fakeNotifier();
        $fakeQueue = $this->fakeQueue();
        $app = $this->bootingApp();

        $userRepo = $app->get(UserRepositoryInterface::class);
        $user = $userRepo->create(['name' => 'Tobias']);
        
        $this->createRestore(
            app: $app,
            id: 'restore-2',
            folder: 'restore-folder',
        );

        $handler = new DeleteArtifactJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            container: $app->container(),
            queue: $fakeQueue->queue(name: 'file'),
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-2',
            'user_id' => $user->id(),
        ]);

        $handler->handleJob($job);

        // Must requeue itself
        $fakeQueue->queue(name: 'file')->assertPushedTimes(DeleteArtifactJobHandler::class, 1);
        
        // clear because of unique job
        $fakeQueue->clearQueue(queue: $fakeQueue->queue(name: 'file'));
        
        $notifier->assertSentTimes(Notification::class, 0);
    }

    public function testHandleJobMethodSendsBrowserNotification()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();

        $userRepo = $app->get(UserRepositoryInterface::class);
        $user = $userRepo->create(['name' => 'Tobias']);

        $this->createRestore(
            app: $app,
            id: 'restore-3',
            folder: 'restore-folder',
            userId: $user->id(),
        );

        $handler = new DeleteArtifactJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            container: $app->container(),
            queue: $app->get(QueueInterface::class),
        );

        $job = $this->createQueueJob([
            'restore_id' => 'restore-3',
            'user_id' => $user->id(),
        ]);

        $handler->handleJob($job);

        $notifier
            ->assertSent(
                Notification::class,
                function(ChannelMessagesInterface $messages): bool {
                    if ($messages->successful()->channelNames() !== ['browser']) {
                        return false;
                    }
                    
                    $message = $messages->get('browser')->message();
                    $this->assertSame('Backup deletion completed.', $message->data['title']);
                    
                    return true;
                }
            )
            ->assertSentTimes(Notification::class, 1);
    }
    
    public function testHandleJobMethodDoesntSendsBrowserNotificationIfMissingUser()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();
        
        $this->createRestore(
            app: $app,
            id: 'restore-3',
            folder: 'restore-folder',
            userId: null,
        );
        
        $handler = new DeleteArtifactJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            container: $app->container(),
            queue: $app->get(QueueInterface::class),
        );
        
        $job = $this->createQueueJob([
            'restore_id' => 'restore-3',
        ]);

        $handler->handleJob($job);

        $notifier->assertSentTimes(Notification::class, 0);
    }
    
    public function testHandleJobMethodThrowsIfRestoreNotFound()
    {
        $app = $this->bootingApp();

        $handler = new DeleteArtifactJobHandler(
            restoreRepository: $app->get(RestoreRepositoryInterface::class),
            container: $app->container(),
            queue: $app->get(QueueInterface::class),
        );

        $job = $this->createQueueJob([
            'restore_id' => 'missing-id',
            'user_id' => null,
        ]);

        $this->expectException(\RuntimeException::class);

        $handler->handleJob($job);
    }
}