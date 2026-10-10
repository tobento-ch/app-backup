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

use ArrayIterator;
use Countable;
use IteratorAggregate;

final class Actions implements ActionsInterface
{
    /**
     * @var array<string, ActionInterface>
     */
    private array $actions = [];

    /**
     * Create a new Actions collection.
     *
     * @param ActionInterface ...$actions
     */
    public function __construct(
        ActionInterface ...$actions
    ) {
        foreach($actions as $action) {
            $this->actions[$action->id()] = $action;
        }
    }

    /**
     * Returns a new instance with the filtered actions.
     *
     * @param callable $callback
     * @return static
     */
    public function filter(callable $callback): static
    {
        return new static(...array_filter($this->actions, $callback));
    }

    /**
     * Get all actions.
     *
     * @return array<string, ActionInterface>
     */
    public function all(): array
    {
        return $this->actions;
    }

    /**
     * Get all action ids.
     *
     * @return array<int, string>
     */
    public function ids(): array
    {
        return array_keys($this->actions);
    }

    /**
     * Check if the given action exists.
     *
     * @param string $actionId
     * @return bool
     */
    public function has(string $actionId): bool
    {
        return isset($this->actions[$actionId]);
    }

    /**
     * Returns the action if exists or null if none.
     *
     * @param string $actionId
     * @return null|ActionInterface
     */
    public function get(string $actionId): null|ActionInterface
    {
        return $this->actions[$actionId] ?? null;
    }

    /**
     * IteratorAggregate implementation.
     *
     * @return ArrayIterator<string, ActionInterface>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->actions);
    }

    /**
     * Countable implementation.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->actions);
    }
    
    /**
     * Returns data that can be serialized into the manifest e.g.
     *
     * @return array The actions serializable representation.
     */
    public function jsonSerialize(): array
    {
        $actions = [];
        
        foreach($this->all() as $action) {
            $actions[] = [
                'class' => $action::class,
                'data'  => $action->jsonSerialize(),
            ];
        }

        return $actions;
    }
}