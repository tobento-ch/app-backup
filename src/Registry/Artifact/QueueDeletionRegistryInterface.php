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

namespace Tobento\App\Backup\Registry\Artifact;

use Psr\Container\ContainerInterface;
use Tobento\App\Backup\Process\DeleteProcessInterface;
use Tobento\App\Backup\Repo\RestoreEntityInterface;

/**
 * Registry capable of scheduling and creating deletion processes.
 */
interface QueueDeletionRegistryInterface
{
    /**
     * Queue deletion for the specified restore entity.
     *
     * @param string|int $id The restore entity ID whose artifact should be deleted.
     * @param ContainerInterface $container The application container.
     * @return void
     */
    public function queueDeletion(string|int $id, ContainerInterface $container): void;
    
    /**
     * Create a deletion process for the specified restore entity.
     *
     * @param RestoreEntityInterface $restoreEntity
     * @param ContainerInterface $container The application container.
     * @return DeleteProcessInterface The deletion process instance.
     */
    public function createDeleteProcess(
        RestoreEntityInterface $restoreEntity,
        ContainerInterface $container
    ): DeleteProcessInterface;
}