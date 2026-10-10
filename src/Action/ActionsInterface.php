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

use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * @extends IteratorAggregate<string, ActionInterface>
 */
interface ActionsInterface extends IteratorAggregate, Countable, JsonSerializable
{
    /**
     * Returns a new instance with the filtered actions.
     *
     * @param callable $callback
     * @return static
     */
    public function filter(callable $callback): static;
    
    /**
     * Get all actions.
     *
     * @return array<string, ActionInterface>
     */    
    public function all(): array;
    
    /**
     * Get all action ids.
     *
     * @return array<int, string>
     */    
    public function ids(): array;
    
    /**
     * Check if the given action exists.
     *
     * @param string $actionId
     * @return bool
     */
    public function has(string $actionId): bool;
    
    /**
     * Returns the action if exists or null if none.
     *
     * @param string $actionId
     * @return null|ActionInterface
     */
    public function get(string $actionId): null|ActionInterface;
    
    /**
     * Returns data that can be serialized into the manifest e.g.
     *
     * @return array The actions serializable representation.
     */
    public function jsonSerialize(): array;
}