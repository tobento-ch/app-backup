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
 
namespace Tobento\App\Backup\Registry\Artifact;

use IteratorAggregate;
use Tobento\App\Backup\Exception\ArtifactRegistryNotFoundException;

/**
 * @extends IteratorAggregate<string, ArtifactRegistryInterface>
 */
interface ArtifactRegistriesInterface extends IteratorAggregate
{
    /**
     * Add a registry.
     *
     * @param ArtifactRegistryInterface $registry
     * @return void
     */
    public function add(ArtifactRegistryInterface $registry): void;
    
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
     * @return ArtifactRegistryInterface
     * @throws ArtifactRegistryNotFoundException
     */
    public function get(string $id): ArtifactRegistryInterface;
    
    /**
     * Returns the first found registry or null if none found.
     *
     * @return null|ArtifactRegistryInterface
     */
    public function first(): null|ArtifactRegistryInterface;
    
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
     * @return array<string, ArtifactRegistryInterface>
     */
    public function all(): array;
}