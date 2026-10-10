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
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;

class RestoreProcess implements RestoreProcessInterface
{
    /**
     * Create a new instance.
     *
     * @param int $processId
     * @param ActionsInterface $actions
     * @param array<int, string> $processedActions
     * @param array<string, array> $actionStates
     * @param array<int, string> $hooks
     * @param ReadableArtifactInterface $artifact
     * @param ProcessRepositoryInterface $processRepository
     * @param null|int|string $userId
     * @param array<string, string> $meta
     */
    public function __construct(
        protected int $processId,
        protected ActionsInterface $actions,
        protected array $processedActions,
        protected array $actionStates,
        protected array $hooks,
        protected ReadableArtifactInterface $artifact,
        protected ProcessRepositoryInterface $processRepository,
        protected null|string $name = null,
        protected null|int|string $userId = null,
        protected array $meta = [],
    ) {}

    /**
     * Returns the unique identifier for this process instance.
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
     * Returns the list of restore actions.
     */
    public function actions(): ActionsInterface
    {
        return $this->actions;
    }

    /**
     * Returns the hooks.
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
     * Returns the readable artifact used by the restore process.
     */
    public function artifact(): ReadableArtifactInterface
    {
        return $this->artifact;
    }

    /**
     * Marks the given action as finished.
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
     */
    public function isActionFinished(ActionInterface $action): bool
    {
        return in_array($action->id(), $this->processedActions, true);
    }

    /**
     * Determines whether all actions in the process have finished.
     */
    public function allActionsFinished(): bool
    {
        return empty(array_diff($this->actions->ids(), $this->processedActions));
    }

    /**
     * Returns the stored state for the given action.
     */
    public function stateFor(ActionInterface $action): array
    {
        return $this->actionStates[$action->id()] ?? [];
    }

    /**
     * Updates the stored state for the given action.
     */
    public function updateStateFor(ActionInterface $action, array $state): void
    {
        $current = $this->actionStates[$action->id()] ?? [];
        $this->actionStates[$action->id()] = array_merge($current, $state);
        $this->persist();
    }

    /**
     * Persists updated process state.
     */
    protected function persist(): void
    {
        $this->processRepository->updateById($this->processId, [
            'processed_actions' => $this->processedActions,
            'action_states' => $this->actionStates,
        ]);
    }
}