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

namespace Tobento\App\Backup\Migration;

use Tobento\App\Backup\Repo\BackupRepositoryInterface;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\Service\Migration\Actions;
use Tobento\Service\Migration\ActionsInterface;
use Tobento\Service\Migration\MigrationInterface;
use Tobento\Service\Repository\Storage\Migration\RepositoryAction;
use Tobento\Service\Repository\Storage\Migration\RepositoryDeleteAction;

class Repositories implements MigrationInterface
{
    /**
     * Create a new instance.
     *
     * @param BackupRepositoryInterface $backupRepository
     * @param ProcessRepositoryInterface $processRepository
     */
    public function __construct(
        protected BackupRepositoryInterface $backupRepository,
        protected ProcessRepositoryInterface $processRepository,
    ) {}
    
    /**
     * Return a description of the migration.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Backup repositories.';
    }
        
    /**
     * Return the actions to be processed on install.
     *
     * @return ActionsInterface
     */
    public function install(): ActionsInterface
    {
        return new Actions(
            RepositoryAction::newOrNull(
                repository: $this->backupRepository,
                description: 'Backup repository.',
            ),
            RepositoryAction::newOrNull(
                repository: $this->processRepository,
                description: 'Process repository.',
            ),
        );
    }

    /**
     * Return the actions to be processed on uninstall.
     *
     * @return ActionsInterface
     */
    public function uninstall(): ActionsInterface
    {
        return new Actions(
            RepositoryDeleteAction::newOrNull(
                repository: $this->backupRepository,
                description: 'Backup repository.',
            ),
            RepositoryDeleteAction::newOrNull(
                repository: $this->processRepository,
                description: 'Process repository.',
            ),
        );
    }
}