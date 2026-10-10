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

use Psr\Http\Message\ServerRequestInterface;
use Tobento\App\User\UserInterface;
use Tobento\Service\Repository\RepositoryCreateException;
use Tobento\Service\Repository\Storage\Column\ColumnsInterface;
use Tobento\Service\Repository\Storage\Column\ColumnInterface;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageEntityFactoryInterface;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\Items;
use Tobento\Service\Storage\ItemsInterface;
use Tobento\Service\Storage\StorageInterface;
use Throwable;

class BackupStorageRepository extends StorageRepository implements BackupRepositoryInterface
{
    /**
     * Create a new instance.
     *
     * @param StorageInterface $storage
     * @param string $table
     * @param BackupEntityFactory $backupEntityFactory
     * @param null|ServerRequestInterface $request
     */
    public function __construct(
        protected StorageInterface $storage,
        protected string $table,
        protected BackupEntityFactory $backupEntityFactory,
        protected null|ServerRequestInterface $request = null,
    ) {
        parent::__construct(storage: $storage, table: $table, columns: null, entityFactory: $backupEntityFactory);
    }
    
    /**
     * Returns the configured columns.
     *
     * @return iterable<ColumnInterface>|ColumnsInterface
     */
    protected function configureColumns(): iterable|ColumnsInterface
    {
        return [
            // Primary identifier for the backup record
            new Column\Id(),

            // The app this backup belongs to (root, backend, frontend, api, etc.)
            new Column\Text('app_id')->type(index: ['name' => 'app_id', 'column' => 'app_id']),

            // Backend user who last edited the translation (nullable for AI/system edits)
            new Column\Integer(name: 'user_id', type: 'int')->type(unsigned: true, nullable: true),
            
            // Human-readable name
            new Column\Text('name'),
            
            // Selected backup actions and configuration
            new Column\Json('options'),
            
            // Additional metadata (e.g. runtime, size, snapshot info)
            new Column\Json('meta'),

            // Creation timestamp (required for purge commands)
            new Column\Datetime(name: 'created_at', type: 'timestamp')->autoCreate(),
        ];
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
        // Only set user_id if not explicitly provided
        if (
            !isset($attributes['user_id'])
            && $this->request
        ) {
            $user = $this->request->getAttribute(UserInterface::class);

            if ($user instanceof UserInterface) {
                $attributes['user_id'] = $user->id();
            }
        }
        
        return parent::create($attributes);
    }
    
    /**
     * Create entity from array.
     *
     * @param array $entity
     * @return BackupEntityInterface
     */
    public function createEntity(array $entity): BackupEntityInterface
    {
        return $this->entityFactory()->createEntityFromArray($entity);
    }

    /**
     * Returns distinct values for the given column as a key/value array.
     *
     * @param string $column The column name to extract unique values from.
     * @return array<string, string> The distinct values indexed by themselves.
     */
    public function distinctValues(string $column): array
    {
        $values = $this->query()
            ->select($column)
            ->groupBy($column)
            ->column($column);

        $unique = array_unique($values->all());

        return array_combine($unique, $unique);
    }
}