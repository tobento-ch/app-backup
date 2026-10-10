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

use ArrayIterator;
use Tobento\App\Backup\Exception\HookNotFoundException;
use Traversable;

final class Hooks implements HooksInterface
{
    /**
     * @var array<string, HookInterface>
     */
    private array $hooks = [];
    
    /**
     * Add a hook.
     *
     * @param HookInterface $hook
     * @return void
     */
    public function add(HookInterface $hook): void
    {
        $this->hooks[$hook->id()] = $hook;
    }
    
    /**
     * Returns true if hook exists, otherwise false.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->hooks);
    }
    
    /**
     * Returns a hook by id.
     *
     * @param string $id
     * @return HookInterface
     * @throws HookNotFoundException
     */
    public function get(string $id): HookInterface
    {
        if (isset($this->hooks[$id])) {
            return $this->hooks[$id];
        }
        
        throw new HookNotFoundException(hook: $id);
    }
    
    /**
     * Returns a new instance with the group filtered.
     *
     * @param string $group
     * @return static
     */
    public function group(string $group): static
    {
        $new = clone $this;
        $new->hooks = array_filter($this->hooks, fn(HookInterface $h): bool => $group === $h->group());
        return $new;
    }
    
    /**
     * Returns all hook group names.
     *
     * @return array<int, string>
     */
    public function groupNames(): array
    {
        $names = [];
        
        foreach($this->all() as $hook) {
            $names[] = $hook->group();
        }
        
        return array_unique($names);
    }
    
    /**
     * Returns a new instance with only hooks supporting backup jobs.
     *
     * @return static
     */
    public function backup(): static
    {
        $new = clone $this;
        $new->hooks = array_filter(
            $this->hooks,
            fn(HookInterface $h): bool => $h->supportsBackup()
        );
        return $new;
    }

    /**
     * Returns a new instance with only hooks supporting restore jobs.
     *
     * @return static
     */
    public function restore(): static
    {
        $new = clone $this;
        $new->hooks = array_filter(
            $this->hooks,
            fn(HookInterface $h): bool => $h->supportsRestore()
        );
        return $new;
    }
    
    /**
     * Returns a new instance with only the hooks specified.
     *
     * @param array<int, string> $hookIds
     * @return static
     */
    public function only(array $hookIds): static
    {
        $new = clone $this;

        $new->hooks = array_filter(
            $this->hooks,
            fn(HookInterface $hook, string $id) => in_array($id, $hookIds, true),
            ARRAY_FILTER_USE_BOTH,
        );

        return $new;
    }
    
    /**
     * Returns all hook names indexed by id.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        $names = [];
        
        foreach($this->all() as $id => $hook) {
            $names[$id] = $hook->name();
        }
        
        return $names;
    }
    
    /**
     * Returns all hooks.
     *
     * @return array<string, HookInterface>
     */
    public function all(): array
    {
        return $this->hooks;
    }
    
    /**
     * Returns the iterator. 
     *
     * @return Traversable<string, HookInterface>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->hooks);
    }
}