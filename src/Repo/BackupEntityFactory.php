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

use Tobento\Service\Repository\Storage\EntityFactory;

class BackupEntityFactory extends EntityFactory
{
    /**
     * Create an entity from array.
     *
     * @param array $attributes
     * @return BackupEntityInterface
     * @throws \Throwable If cannot create block entity
     */
    public function createEntityFromArray(array $attributes): BackupEntityInterface
    {
        // Process the columns reading:
        $attributes = $this->columns->processReading($attributes);

        return new BackupEntity($attributes);
    }
}