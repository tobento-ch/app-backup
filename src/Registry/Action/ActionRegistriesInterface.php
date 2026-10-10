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

use IteratorAggregate;
use Tobento\App\Backup\Exception\ActionRegistryNotFoundException;

/**
 * @extends IteratorAggregate<string, ActionRegistryInterface>
 */
interface ActionRegistriesInterface extends IteratorAggregate
{
    /**
     * Add a registry.
     *
     * @param ActionRegistryInterface $registry
     * @return void
     */
    public function add(ActionRegistryInterface $registry): void;
    
    /**
     * Returns true if regsitry exists, otherwise false.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool;
    
    /**
     * Returns a registry by id.
     *
     * @param string $id
     * @return ActionRegistryInterface
     * @throws ActionRegistryNotFoundException
     */
    public function get(string $id): ActionRegistryInterface;
    
    /**
     * Returns the first found registry or null if none found.
     *
     * @return null|ActionRegistryInterface
     */
    public function first(): null|ActionRegistryInterface;
    
    /**
     * Returns a new instance with the registries filtered.
     *
     * @param callable $callback
     * @return static
     */
    public function filter(callable $callback): static;
    
    /**
     * Returns all registry names indexed by id.
     *
     * @return array<string, string>
     */
    public function names(): array;
    
    /**
     * Returns all registries.
     *
     * @return array<string, ActionRegistryInterface>
     */
    public function all(): array;
}