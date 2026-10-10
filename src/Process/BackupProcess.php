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
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;

class BackupProcess implements BackupProcessInterface
{
    /**
     * Create a new instance
     *
     * @param int $processId
     * @param ActionsInterface $actions
     * @param array<int, string> $processedActions
     * @param array<string, array> $actionStates
     * @param array<int, string> $hooks
     * @param WritableArtifactInterface $artifact
     * @param ProcessRepositoryInterface $processRepository
     * @param null|string $name
     * @param null|int|string $userId
     * @param array<string, string> $meta
     */
    public function __construct(
        protected int $processId,
        protected ActionsInterface $actions,
        protected array $processedActions,
        protected array $actionStates,
        protected array $hooks,
        protected WritableArtifactInterface $artifact,
        protected ProcessRepositoryInterface $processRepository,
        protected null|string $name = null,
        protected null|int|string $userId = null,
        protected array $meta = [],
    ) {}

    /**
     * Returns the unique identifier for this process instance.
     *
     * @return int
     */
    public function id(): int
    {
        return $this->processId;
    }
    
    /**
     * Returns the human‑friendly name if any.
     *
     * @return null|string
     */
    public function name(): null|string
    {
        return $this->name;
    }
    
    /**
     * Returns the identifier of the user who initiated the process.
     *
     * @return null|int|string
     */
    public function userId(): null|int|string
    {
        return $this->userId;
    }

    /**
     * Returns the list of actions that make up the process.
     *
     * @return ActionsInterface
     */
    public function actions(): ActionsInterface
    {
        return $this->actions;
    }
    
    /**
     * Returns the hooks.
     *
     * @return array<int, string>
     */
    public function hooks(): array
    {
        return $this->hooks;
    }
    
    /**
     * Returns the meta data.
     *
     * @return array<string, string>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * Returns the writeable artifact used by the backup process.
     *
     * @return WritableArtifactInterface
     */
    public function artifact(): WritableArtifactInterface
    {
        return $this->artifact;
    }

    /**
     * Marks the given action as finished.
     *
     * @param ActionInterface $action The action being completed.
     * @return void
     */
    public function markActionFinished(ActionInterface $action): void
    {
        if (!in_array($action->id(), $this->processedActions, true)) {
            $this->processedActions[] = $action->id();
            $this->persist();
        }
    }

    /**
     * Determines whether the given action has finished.
     *
     * @param ActionInterface $action The action to check.
     * @return bool True if the action is finished, otherwise false.
     */
    public function isActionFinished(ActionInterface $action): bool
    {
        return in_array($action->id(), $this->processedActions, true);
    }

    /**
     * Determines whether all actions in the process have finished.
     *
     * @return bool True if all actions are finished, otherwise false.
     */
    public function allActionsFinished(): bool
    {
        return empty(array_diff($this->actions->ids(), $this->processedActions));
    }

    /**
     * Returns the stored state for the given action, or initializes it
     * with the provided default if no state exists.
     *
     * @param ActionInterface $action The action whose state is requested.
     * @return array The current state for the action.
     */
    public function stateFor(ActionInterface $action): array
    {
        return $this->actionStates[$action->id()] ?? [];
    }

    /**
     * Updates the stored state for the given action.
     *
     * @param ActionInterface $action The action whose state is updated.
     * @param array $state The new state to store.
     * @return void
     */
    public function updateStateFor(ActionInterface $action, array $state): void
    {
        $current = $this->actionStates[$action->id()] ?? [];
        $this->actionStates[$action->id()] = array_merge($current, $state);
        $this->persist();
    }

    protected function persist(): void
    {
        $this->processRepository->updateById($this->processId, [
            'processed_actions' => $this->processedActions,
            'action_states' => $this->actionStates,
        ]);
    }
}