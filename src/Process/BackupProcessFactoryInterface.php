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

namespace Tobento\App\Backup\Process;

use Tobento\App\Backup\Repo\BackupEntityInterface;

/**
 * BackupProcessFactoryInterface
 *
 * Responsible for creating or loading a backup process instance.
 * The factory abstracts away how processes are persisted, restored,
 * and initialized on first run.
 *
 * A process is created when a backup job starts for the first time,
 * or loaded when a queued job resumes execution.
 *
 * Implementations MUST:
 * - Create a new process when $processId is null.
 * - Load an existing process when $processId is provided.
 * - Ensure the process is persisted immediately on creation.
 * - Ensure the process contains all actions required for execution.
 */
interface BackupProcessFactoryInterface
{
    /**
     * Create or load a backup process.
     *
     * @param BackupEntityInterface $backupEntity
     *     The backup definition containing actions, hooks, and metadata.
     *
     * @param null|int $processId
     *     The existing process ID, or null when the job runs for the first time.
     *
     * @param null|int|string $userId
     *
     * @return BackupProcessInterface
     *     The fully initialized process instance ready for execution.
     */
    public function createProcess(
        BackupEntityInterface $backupEntity,
        null|int $processId = null,
        null|int|string $userId = null,
    ): BackupProcessInterface;
}