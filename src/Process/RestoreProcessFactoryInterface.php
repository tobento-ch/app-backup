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

use Tobento\App\Backup\Repo\RestoreEntityInterface;

/**
 * RestoreProcessFactoryInterface
 *
 * Responsible for creating or loading a restore process instance.
 * The factory abstracts how restore processes are persisted, restored,
 * and initialized on first run.
 *
 * A process is created when a restore job starts for the first time,
 * or loaded when a queued job resumes execution.
 *
 * Implementations MUST:
 * - Create a new process when $processId is null.
 * - Load an existing process when $processId is provided.
 * - Ensure the process is persisted immediately on creation.
 * - Ensure the process contains all configured restore actions
 *   required for execution.
 */
interface RestoreProcessFactoryInterface
{
    /**
     * Create or load a restore process.
     *
     * @param RestoreEntityInterface $restoreEntity
     *     The restore definition containing manifest, artifacts, hooks,
     *     and metadata.
     *
     * @param null|int $processId
     *     The existing process ID, or null when the job runs for the first time.
     *
     * @param null|int|string $userId
     *
     * @param array $input
     *     The validated restore-time input used to configure restore actions.
     *
     * @return RestoreProcessInterface
     *     The fully initialized process instance ready for execution.
     */
    public function createProcess(
        RestoreEntityInterface $restoreEntity,
        null|int $processId = null,
        null|int|string $userId = null,
        array $input = [],
    ): RestoreProcessInterface;
}