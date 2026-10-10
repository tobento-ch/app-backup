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

namespace Tobento\App\Backup\Action;

use Tobento\App\Backup\Exception\ActionsException;
use Tobento\App\Backup\Repo\BackupEntityInterface;

interface ActionsFactoryInterface
{
    /**
     * Creates actions from backup entity.
     *
     * @param BackupEntityInterface $entity
     * @return ActionsInterface
     * @throws ActionsException
     */
    public function createActionsFromBackupEntity(BackupEntityInterface $entity): ActionsInterface;
    
    /**
     * Creates actions from array.
     *
     * @param array<string, mixed> $data
     * @return ActionsInterface
     * @throws ActionsException
     */
    public function createActionsFromArray(array $data): ActionsInterface;
    
    /**
     * Creates actions from JSON string.
     *
     * @param string $json
     * @return ActionsInterface
     * @throws ActionsException
     */
    public function createActionsFromJsonString(string $json): ActionsInterface;
}