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
 
namespace Tobento\App\Backup\Hook;

use IteratorAggregate;
use Tobento\App\Backup\Exception\HookNotFoundException;

/**
 * @extends IteratorAggregate<string, HookInterface>
 */
interface HooksInterface extends IteratorAggregate
{
    /**
     * Add a hook.
     *
     * @param HookInterface $hook
     * @return void
     */
    public function add(HookInterface $hook): void;
    
    /**
     * Returns true if hook exists, otherwise false.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool;
    
    /**
     * Returns a hook by id.
     *
     * @param string $id
     * @return HookInterface
     * @throws HookNotFoundException
     */
    public function get(string $id): HookInterface;
    
    /**
     * Returns a new instance with the group filtered.
     *
     * @param string $group
     * @return static
     */
    public function group(string $group): static;
    
    /**
     * Returns all hook group names.
     *
     * @return array<int, string>
     */
    public function groupNames(): array;
    
    /**
     * Returns a new instance with only hooks supporting backup jobs.
     *
     * @return static
     */
    public function backup(): static;

    /**
     * Returns a new instance with only hooks supporting restore jobs.
     *
     * @return static
     */
    public function restore(): static;
    
    /**
     * Returns a new instance with only the hooks specified.
     *
     * @param array<int, string> $hookIds
     * @return static
     */
    public function only(array $hookIds): static;
    
    /**
     * Returns all hook names indexed by id.
     *
     * @return array<string, string>
     */
    public function names(): array;
    
    /**
     * Returns all hooks.
     *
     * @return array<string, HookInterface>
     */
    public function all(): array;
}