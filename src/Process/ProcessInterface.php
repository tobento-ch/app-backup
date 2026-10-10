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

use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Action\ActionsInterface;

/**
 * Represents the execution context for a process consisting of multiple actions.
 *
 * Implementations track action progress and store per-action state used during
 * incremental execution.
 */
interface ProcessInterface
{
    /**
     * Returns the unique identifier for this process instance.
     *
     * @return int
     */
    public function id(): int;

    /**
     * Returns the human‑friendly name if any.
     *
     * @return null|string
     */
    public function name(): null|string;
    
    /**
     * Returns the identifier of the user who initiated the process.
     *
     * @return null|int|string
     */
    public function userId(): null|int|string;
    
    /**
     * Returns the list of actions that make up the process.
     *
     * @return ActionsInterface
     */
    public function actions(): ActionsInterface;
    
    /**
     * Returns the hooks.
     *
     * @return array<int, string>
     */
    public function hooks(): array;
    
    /**
     * Returns the meta data.
     *
     * @return array<string, mixed>
     */
    public function meta(): array;

    /**
     * Marks the given action as finished.
     *
     * @param ActionInterface $action The action being completed.
     * @return void
     */
    public function markActionFinished(ActionInterface $action): void;

    /**
     * Determines whether the given action has finished.
     *
     * @param ActionInterface $action The action to check.
     * @return bool True if the action is finished, otherwise false.
     */
    public function isActionFinished(ActionInterface $action): bool;

    /**
     * Determines whether all actions in the process have finished.
     *
     * @return bool True if all actions are finished, otherwise false.
     */
    public function allActionsFinished(): bool;

    /**
     * Returns the stored state for the given action, or initializes it
     * with the provided default if no state exists.
     *
     * @param ActionInterface $action The action whose state is requested.
     * @return array The current state for the action.
     */
    public function stateFor(ActionInterface $action): array;

    /**
     * Updates the stored state for the given action.
     *
     * @param ActionInterface $action The action whose state is updated.
     * @param array $state The new state to store.
     * @return void
     */
    public function updateStateFor(ActionInterface $action, array $state): void;
}