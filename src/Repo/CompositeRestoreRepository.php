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

namespace Tobento\App\Backup\Repo;

use Psr\Container\ContainerInterface;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\QueueDeletionRegistryInterface;
use Tobento\App\Backup\Registry\Artifact\ReadableArtifactRegistryInterface;
use Tobento\Service\Repository\RepositoryCreateException;
use Tobento\Service\Repository\RepositoryDeleteException;
use Tobento\Service\Repository\RepositoryReadException;
use Tobento\Service\Repository\RepositoryUpdateException;

class CompositeRestoreRepository implements RestoreRepositoryInterface
{
    /**
     * Create a new instance.
     *
     * @param ArtifactRegistriesInterface $registries
     * @param ContainerInterface $container
     */
    public function __construct(
        protected ArtifactRegistriesInterface $registries,
        protected ContainerInterface $container,
    ) {
        $this->registries = $registries->filter(fn($r) => $r instanceof ReadableArtifactRegistryInterface);
    }
    
    /**
     * Returns the found entity using the specified id (primary key)
     * or null if none found.
     *
     * @param int|string $id
     * @return null|object
     * @throws RepositoryReadException
     */
    public function findById(int|string $id): null|object
    {
        foreach ($this->registries as $registry) {
            
            if (! $registry instanceof ReadableArtifactRegistryInterface) {
                continue;
            }
            
            $repo = $registry->createRepository($this->container);

            try {
                $raw = $repo->findById($id);
            } catch (\Throwable $e) {
                throw new RepositoryReadException($e->getMessage(), 0, $e);
            }

            if ($raw !== null) {
                return $this->mapEntity($registry, $raw);
            }
        }

        return null;
    }
    
    /**
     * Returns the found entity using the specified id (primary key)
     * or null if none found.
     *
     * @param int|string ...$ids
     * @return iterable<object>
     * @throws RepositoryReadException
     */
    public function findByIds(int|string ...$ids): iterable
    {
        $items = $this->iterateRaw(
            fn($repo) => $repo->findByIds(...$ids)
        );

        return $this->dedupe($items);
    }

    /**
     * Returns the found entity using the specified where parameters
     * or null if none found.
     *
     * @param array $where
     * @return null|object
     * @throws RepositoryReadException
     */
    public function findOne(array $where = []): ?object
    {
        foreach ($this->registries as $registry) {
            
            if (! $registry instanceof ReadableArtifactRegistryInterface) {
                continue;
            }
            
            $repo = $registry->createRepository($this->container);

            try {
                $raw = $repo->findOne($where);
            } catch (\Throwable $e) {
                throw new RepositoryReadException($e->getMessage(), 0, $e);
            }

            if ($raw !== null) {
                return $this->mapEntity($registry, $raw);
            }
        }

        return null;
    }

    /**
     * Returns the found entities using the specified parameters.
     *
     * @param array $where Usually where parameters.
     * @param array $orderBy The order by parameters.
     * @param null|int|array $limit The limit e.g. 5 or [5(number), 10(offset)].
     * @return iterable<object>
     * @throws RepositoryReadException
     */
    public function findAll(array $where = [], array $orderBy = [], null|int|array $limit = null): iterable
    {
        $iterators = $this->createRawIteratorsPerRegistry(
            fn($repo) => $repo->findAll($where, $orderBy, $limit)
        );
        
        return $this->dedupe($this->mergeRoundRobin($iterators));
    }
    
    /**
     * Returns the found column values using the specified parameters.
     *
     * @param string $column The column name for the values.
     * @param null|string $key The column name for the index key.
     * @param array $where Usually where parameters.
     * @param array $orderBy The order by parameters.
     * @param null|int|array $limit The limit e.g. 5 or [5(number), 10(offset)].
     * @return array
     * @throws RepositoryReadException
     */
    public function findColumn(
        string $column,
        null|string $key = null,
        array $where = [],
        array $orderBy = [],
        null|int|array $limit = null
    ): array {
        $result = [];

        // iterate raw items from all registries
        $items = $this->iterateRaw(
            fn($repo) => $repo->findAll($where, $orderBy, $limit)
        );

        foreach ($items as [$registry, $raw]) {
            $entity = $this->mapEntity($registry, $raw);

            // map column name to entity property
            $value = match ($column) {
                'id' => $entity->id(),
                'name' => $entity->name(),
                'type' => $entity->type(),
                'domain' => $entity->domain(),
                'user_id' => $entity->userId(),
                'created_at' => $entity->createdAt(),
                default   => null,
            };

            if ($value === null) {
                continue;
            }

            if ($key === null) {
                $result[] = $value;
            } else {
                // key also from entity, not raw
                $keyValue = match ($key) {
                    'id' => $entity->id(),
                    'name' => $entity->name(),
                    'type' => $entity->type(),
                    'domain' => $entity->domain(),
                    'user_id' => $entity->userId(),
                    default => null,
                };

                if ($keyValue === null) {
                    continue;
                }

                $result[$keyValue] = $value;
            }
        }

        return $result;
    }
    
    /**
     * Returns the number of items using the specified where parameters.
     *
     * @param array $where
     * @return int
     * @throws RepositoryReadException
     */
    public function count(array $where = []): int
    {
        $count = 0;

        foreach ($this->registries as $registry) {
            
            if (! $registry instanceof ReadableArtifactRegistryInterface) {
                continue;
            }
            
            $repo = $registry->createRepository($this->container);

            $count += $repo->count($where);
        }

        return $count;
    }

    /**
     * Create an entity.
     *
     * @param array $attributes
     * @return object The created entity.
     * @throws RepositoryCreateException
     */
    public function create(array $attributes): object
    {
        throw new RepositoryCreateException(
            message: 'CompositeRestoreRepository does not support create().',
        );
    }
    
    /**
     * Update an entity by id.
     *
     * @param string|int $id
     * @param array $attributes The attributes to update the entity.
     * @return object The updated entity.
     * @throws RepositoryUpdateException
     */
    public function updateById(string|int $id, array $attributes): object
    {
        throw new RepositoryUpdateException(
            message: 'CompositeRestoreRepository does not support updateById().',
        );
    }
    
    /**
     * Update entities.
     *
     * @param array $where The where parameters.
     * @param array $attributes The attributes to update the entities.
     * @return iterable<object> The updated entities.
     * @throws RepositoryUpdateException
     */
    public function update(array $where, array $attributes): iterable
    {
        throw new RepositoryUpdateException(
            message: 'CompositeRestoreRepository does not support update().',
        );
    }
    
    /**
     * Delete an entity by id.
     *
     * @param string|int $id
     * @return object The deleted entity.
     * @throws RepositoryDeleteException
     */
    public function deleteById(string|int $id): object
    {
        foreach ($this->registries as $registry) {
            
            if (! $registry instanceof ReadableArtifactRegistryInterface) {
                continue;
            }
            
            $repo = $registry->createRepository($this->container);

            try {
                $raw = $repo->findById($id);
            } catch (RepositoryReadException $e) {
                throw new RepositoryDeleteException(message: $e->getMessage(), previous: $e);
            }
            
            if ($raw !== null) {
                if ($registry instanceof QueueDeletionRegistryInterface) {
                    $registry->queueDeletion(id: $id, container: $this->container);
                    return $raw;
                }
                
                return $repo->deleteById($id);
            }
        }

        throw new RepositoryDeleteException(
            id: $id,
            message: sprintf('CompositeRestoreRepository: entity with id "%s" not found in any registry.', $id),
        );
    }
    
    /**
     * Delete entities.
     *
     * @param array $where The where parameters.
     * @return iterable<object> The deleted entities.
     * @throws RepositoryDeleteException
     */
    public function delete(array $where): iterable
    {
        throw new RepositoryDeleteException(
            message: 'CompositeRestoreRepository does not support delete().',
        );
    }
    
    /**
     * Returns distinct values for the given column as a key/value array.
     *
     * @param string $column The column name to extract unique values from.
     * @return array<string, string> The distinct values indexed by themselves.
     */
    public function distinctValues(string $column): array
    {
        $values = $this->findColumn(column: $column);

        $unique = array_unique($values);

        return array_combine($unique, $unique);
    }
    
    /**
     * Iterate over all restore registries and yield raw items from each
     * underlying repository together with the registry that produced them.
     *
     * @param callable $callback A callback receiving the underlying repository and returning an iterable of raw items.
     * @return iterable<array{ReadableArtifactRegistryInterface, mixed}>
     * @throws RepositoryReadException
     */
    protected function iterateRaw(callable $callback): iterable
    {
        foreach ($this->registries as $registry) {
            
            if (! $registry instanceof ReadableArtifactRegistryInterface) {
                continue;
            }
            
            $repo = $registry->createRepository($this->container);

            try {
                foreach ($callback($repo) as $raw) {
                    yield [$registry, $raw];
                }
            } catch (\Throwable $e) {
                throw new RepositoryReadException(message: $e->getMessage(), previous: $e);
            }
        }
    }

    /**
     * Map a raw repository item to a RestoreEntity using the registry’s
     * mapping rules and the RestoreEntityFactory.
     *
     * @param ReadableArtifactRegistryInterface $registry The registry responsible for the item.
     * @param mixed $raw The raw item returned by the underlying repository.
     * @return RestoreEntityInterface
     */
    protected function mapEntity(ReadableArtifactRegistryInterface $registry, mixed $raw): RestoreEntityInterface
    {
        return $registry->mapEntity($raw, $this->container);
    }

    /**
     * Deduplicate mapped restore entities by their ID and yield each entity once.
     *
     * @param iterable<array{ReadableArtifactRegistryInterface, mixed}> $items Raw items paired with registries.
     * @return iterable<RestoreEntityInterface>
     */
    protected function dedupe(iterable $items): iterable
    {
        $seen = [];

        foreach ($items as [$registry, $raw]) {
            $entity = $this->mapEntity($registry, $raw);
            $id = $entity->id();

            if (!isset($seen[$id])) {
                $seen[$id] = true;
                yield $entity;
            }
        }
    }
    
    /**
     * One iterator per registry (not flattened), each yielding [registry, raw]
     * tuples - mirrors Search::search()'s per-searchable iterators.
     */
    protected function createRawIteratorsPerRegistry(callable $fn): array
    {
        $iterators = [];

        foreach ($this->registries as $registry) {
            if (! $registry instanceof ReadableArtifactRegistryInterface) {
                continue;
            }
            
            $repo = $registry->createRepository($this->container);

            $iterators[] = new \ArrayIterator(
                array_map(
                    fn($raw) => [$registry, $raw],
                    iterator_to_array($fn($repo), false)
                )
            );
        }

        return $iterators;
    }

    /**
     * Same round-robin as Search::search(): take one item from each source in
     * turn instead of exhausting one repo before moving to the next, so the
     * composite list isn't grouped block-by-block per repo.
     */
    protected function mergeRoundRobin(array $iterators): iterable
    {
        $numOfIts = count($iterators);

        while ($numOfIts > 0) {
            $iterator = array_shift($iterators);
            $item = $iterator->current();

            if ($item !== null) {
                yield $item;
            }

            $iterator->next();

            if ($iterator->valid()) {
                $iterators[] = $iterator;
            } else {
                $numOfIts--;
            }
        }
    }
}