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

namespace Tobento\App\Backup\Test\Repo;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Process\DeleteProcessInterface;
use Tobento\App\Backup\Repo\CompositeRestoreRepository;
use Tobento\App\Backup\Repo\ProcessEntityInterface;
use Tobento\App\Backup\Repo\RestoreEntity;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistries;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\QueueDeletionRegistryInterface;
use Tobento\App\Backup\Registry\Artifact\ReadableArtifactRegistryInterface;
use Tobento\Service\Repository\RepositoryInterface;
use Tobento\Service\Repository\RepositoryDeleteException;
use Tobento\Service\Repository\RepositoryReadException;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;
use Tobento\Service\Storage\Tables\Tables;

class CompositeRestoreRepositoryTest extends TestCase
{
    protected function createRepository(string $table): RepositoryInterface
    {
        return new class(
            storage: new InMemoryStorage(
                items: [],
                tables: new Tables()->add($table, ['id', 'name', 'type'], 'id')
            ),
            table: $table,
        ) extends StorageRepository {};
    }

    protected function createRegistry(string $table, array $items, bool $queueDeletion = false): ReadableArtifactRegistryInterface
    {
        $storage = new InMemoryStorage(
            items: [$table => $items],
            tables: new Tables()->add($table, ['id', 'name', 'type'], 'id')
        );

        // If queue deletion is enabled, return a registry that ALSO implements QueueDeletionRegistryInterface
        if ($queueDeletion) {
            return new class($storage, $table) implements ReadableArtifactRegistryInterface, QueueDeletionRegistryInterface {
                private array $queued = [];

                public function __construct(
                    private InMemoryStorage $storage,
                    private string $table,
                ) {}

                public function id(): string { return $this->table; }
                public function name(): string { return $this->id(); }

                public function createReadableArtifact(ContainerInterface $container, ProcessEntityInterface $entity): ReadableArtifactInterface {}

                public function createRepository(ContainerInterface $container): RepositoryInterface
                {
                    return new class(storage: $this->storage, table: $this->table) extends StorageRepository {};
                }

                public function mapEntity(mixed $raw, ContainerInterface $container): RestoreEntityInterface
                {
                    return new RestoreEntity(
                        id: (string)$raw['id'],
                        name: $raw['name'] ?? '',
                        type: $raw['type'] ?? '',
                        raw: $raw,
                        registry: $this,
                        userId: $raw['user_id'] ?? null,
                        domain: $raw['domain'] ?? null,
                        createdAt: $raw['created_at'] ?? null,
                    );
                }

                public function queueDeletion(string|int $id, ContainerInterface $container): void
                {
                    $this->queued[] = $id;
                }
                
                public function createDeleteProcess(
                    RestoreEntityInterface $restoreEntity,
                    ContainerInterface $container
                ): DeleteProcessInterface {
                    
                }

                public function queued(): array
                {
                    return $this->queued;
                }
            };
        }

        // Normal registry (no queue deletion)
        return new class($storage, $table) implements ReadableArtifactRegistryInterface {
            public function __construct(
                private InMemoryStorage $storage,
                private string $table,
            ) {}

            public function id(): string { return $this->table; }
            public function name(): string { return $this->id(); }

            public function createReadableArtifact(ContainerInterface $container, ProcessEntityInterface $entity): ReadableArtifactInterface {}

            public function createRepository(ContainerInterface $container): RepositoryInterface
            {
                return new class(storage: $this->storage, table: $this->table) extends StorageRepository {};
            }

            public function mapEntity(mixed $raw, ContainerInterface $container): RestoreEntityInterface
            {
                return new RestoreEntity(
                    id: (string)$raw['id'],
                    name: $raw['name'] ?? '',
                    type: $raw['type'] ?? '',
                    raw: $raw,
                    registry: $this,
                    userId: $raw['user_id'] ?? null,
                    domain: $raw['domain'] ?? null,
                    createdAt: $raw['created_at'] ?? null,
                );
            }
        };
    }

    protected function composite(array $registries): CompositeRestoreRepository
    {
        $artifactRegistries = new ArtifactRegistries();
        
        foreach($registries as $registry) {
            $artifactRegistries->add($registry);
        }

        $container = new class implements ContainerInterface {
            public function get(string $id) {}
            public function has(string $id): bool { return false; }
        };

        return new CompositeRestoreRepository($artifactRegistries, $container);
    }

    public function testFindById()
    {
        $r1 = $this->createRegistry('restore_a', [
            1 => ['id' => 1, 'name' => 'A', 'type' => 'zip'],
        ]);

        $r2 = $this->createRegistry('restore_b', [
            2 => ['id' => 2, 'name' => 'B', 'type' => 'folder'],
        ]);

        $repo = $this->composite([$r1, $r2]);

        $this->assertSame('A', $repo->findById(1)->name());
        $this->assertSame('B', $repo->findById(2)->name());
        $this->assertNull($repo->findById(999));
    }

    public function testFindByIds()
    {
        $r1 = $this->createRegistry('restore_a', [
            1 => ['id' => 1, 'name' => 'A', 'type' => 'zip'],
            2 => ['id' => 2, 'name' => 'B', 'type' => 'zip'],
        ]);

        $r2 = $this->createRegistry('restore_b', [
            2 => ['id' => 2, 'name' => 'B', 'type' => 'zip'],
            3 => ['id' => 3, 'name' => 'C', 'type' => 'folder'],
        ]);

        $repo = $this->composite([$r1, $r2]);

        $items = iterator_to_array($repo->findByIds(1, 2, 3));

        $this->assertCount(3, $items);
        $this->assertSame('A', $items[0]->name());
        $this->assertSame('B', $items[1]->name());
        $this->assertSame('C', $items[2]->name());
    }

    public function testFindOne()
    {
        $r1 = $this->createRegistry('restore_a', [
            1 => ['id' => 1, 'name' => 'A', 'type' => 'zip'],
        ]);

        $r2 = $this->createRegistry('restore_b', [
            2 => ['id' => 2, 'name' => 'B', 'type' => 'zip'],
        ]);

        $repo = $this->composite([$r1, $r2]);

        $this->assertSame('B', $repo->findOne(['name' => 'B'])->name());
        $this->assertNull($repo->findOne(['name' => 'X']));
    }

    public function testFindAll()
    {
        $r1 = $this->createRegistry('restore_a', [
            1 => ['id' => 1, 'name' => 'A', 'type' => 'zip'],
        ]);

        $r2 = $this->createRegistry('restore_b', [
            2 => ['id' => 2, 'name' => 'B', 'type' => 'zip'],
            3 => ['id' => 3, 'name' => 'C', 'type' => 'folder'],
        ]);

        $repo = $this->composite([$r1, $r2]);

        $items = iterator_to_array($repo->findAll(['type' => 'zip']));

        $this->assertCount(2, $items);
        $this->assertSame('A', $items[0]->name());
        $this->assertSame('B', $items[1]->name());
    }

    public function testFindColumn()
    {
        $r1 = $this->createRegistry('restore_a', [
            1 => ['id' => 1, 'name' => 'A', 'type' => 'zip'],
        ]);

        $r2 = $this->createRegistry('restore_b', [
            2 => ['id' => 2, 'name' => 'B', 'type' => 'zip'],
            3 => ['id' => 3, 'name' => 'C', 'type' => 'folder'],
        ]);

        $repo = $this->composite([$r1, $r2]);

        $names = $repo->findColumn('name');
        $this->assertSame(['A', 'B', 'C'], $names);

        $map = $repo->findColumn('name', 'id');
        $this->assertSame([
            1 => 'A',
            2 => 'B',
            3 => 'C',
        ], $map);
    }

    public function testCount()
    {
        $r1 = $this->createRegistry('restore_a', [
            1 => ['id' => 1, 'name' => 'A', 'type' => 'zip'],
        ]);

        $r2 = $this->createRegistry('restore_b', [
            2 => ['id' => 2, 'name' => 'B', 'type' => 'zip'],
            3 => ['id' => 3, 'name' => 'C', 'type' => 'folder'],
        ]);

        $repo = $this->composite([$r1, $r2]);

        $this->assertSame(3, $repo->count());
        $this->assertSame(2, $repo->count(['type' => 'zip']));
    }

    public function testCreateThrows()
    {
        $repo = $this->composite([]);

        $this->expectException(\Tobento\Service\Repository\RepositoryCreateException::class);

        $repo->create(['id' => 10]);
    }

    public function testUpdateThrows()
    {
        $repo = $this->composite([]);

        $this->expectException(\Tobento\Service\Repository\RepositoryUpdateException::class);

        $repo->update(['id' => 1], ['name' => 'X']);
    }

    public function testUpdateByIdThrows()
    {
        $repo = $this->composite([]);

        $this->expectException(\Tobento\Service\Repository\RepositoryUpdateException::class);

        $repo->updateById(1, ['name' => 'X']);
    }

    public function testDeleteById()
    {
        $r1 = $this->createRegistry('restore_a', [
            1 => ['id' => 1, 'name' => 'A', 'type' => 'zip'],
        ]);

        $repo = $this->composite([$r1]);

        $deleted = $repo->deleteById(1);

        $this->assertSame('A', $deleted['name']);
        $this->assertNull($repo->findById(1));
    }
    
    public function testDeleteByIdQueuesDeletionIfRegistrySupportsQueueDeletion()
    {
        $registry = $this->createRegistry(table: 'restore_q', items: [
            1 => ['id' => 1, 'name' => 'Queued', 'type' => 'zip'],
        ], queueDeletion: true);

        $repo = $this->composite([$registry]);

        $raw = $repo->deleteById(1);

        // Composite must return raw entity
        $this->assertSame('Queued', $raw['name']);

        // Composite must NOT delete immediately
        $this->assertSame('Queued', $repo->findById(1)->name());

        // Composite must call queueDeletion
        $this->assertSame([1], $registry->queued());
    }

    public function testDeleteByIdThrowsIfNotFound()
    {
        $repo = $this->composite([]);

        $this->expectException(RepositoryDeleteException::class);

        $repo->deleteById(999);
    }

    public function testDeleteThrows()
    {
        $repo = $this->composite([]);

        $this->expectException(RepositoryDeleteException::class);

        $repo->delete(['id' => 1]);
    }
}