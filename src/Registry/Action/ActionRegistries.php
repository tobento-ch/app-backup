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
 
namespace Tobento\App\Backup\Registry\Action;

use ArrayIterator;
use Tobento\App\Backup\Exception\ActionRegistryNotFoundException;
use Traversable;

final class ActionRegistries implements ActionRegistriesInterface
{
    /**
     * @var array<string, ActionRegistryInterface>
     */
    private array $registries = [];
    
    /**
     * Add a registry.
     *
     * @param ActionRegistryInterface $registry
     * @return void
     */
    public function add(ActionRegistryInterface $registry): void
    {
        $this->registries[$registry->id()] = $registry;
    }

    /**
     * Returns true if regsitry exists, otherwise false.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->registries);
    }
    
    /**
     * Resolves and returns a registry instance for the given ID. Static registries
     * are returned directly from the internal registry list. Dynamic registries
     * (identified by prefixes such as "crud_export_reader:", "crud_import_writer:" or the
     * special "reprocess.reader") are created on demand and cached for reuse.
     *
     * @param string $id Registry identifier
     * @return ActionRegistryInterface
     * @throws ActionRegistryNotFoundException If the registry cannot be resolved
     */
    public function get(string $id): ActionRegistryInterface
    {
        if (isset($this->registries[$id])) {
            return $this->registries[$id];
        }

        throw new ActionRegistryNotFoundException(registry: $id);
    }
    
    /**
     * Returns the first found registry or null if none found.
     *
     * @return null|ActionRegistryInterface
     */
    public function first(): null|ActionRegistryInterface
    {
        $key = array_key_first($this->registries);
        
        if (!is_null($key)) {
            return $this->registries[$key];
        }
        
        return null;
    }
    
    /**
     * Returns the first found registry id or null if none found.
     *
     * @return null|string
     */
    public function firstId(): null|string
    {
        return array_key_first($this->registries);
    }
    
    /**
     * Returns a new instance with the registries filtered.
     *
     * @param callable $callback
     * @return static
     */
    public function filter(callable $callback): static
    {
        $new = clone $this;
        $new->registries = array_filter($this->registries, $callback);
        return $new;
    }
    
    /**
     * Returns all registry names indexed by id.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        $names = [];
        
        foreach($this->all() as $id => $registry) {
            $names[$id] = $registry->name();
        }
        
        return $names;
    }
    
    /**
     * Returns all registries.
     *
     * @return array<string, ActionRegistryInterface>
     */
    public function all(): array
    {
        return $this->registries;
    }
    
    /**
     * Returns the iterator. 
     *
     * @return Traversable<string, ActionRegistryInterface>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->registries);
    }
}