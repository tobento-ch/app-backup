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
 
namespace Tobento\App\Backup\Repo;

use DateTimeInterface;
use Tobento\Service\Support\Arrayable;

/**
 * BackupEntityInterface
 *
 * Represents a single backup record stored in the repository.
 */
interface BackupEntityInterface extends Arrayable
{
    /**
     * Returns the translation ID.
     *
     * @return int
     */
    public function id(): int;
    
    /**
     * Returns the name.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Returns the application identifier.
     *
     * @return string
     */
    public function appId(): string;

    /**
     * Returns the user ID who last edited the translation.
     *
     * @return null|int
     */
    public function userId(): null|int;

    /**
     * Returns the artifacts.
     *
     * @return array<int, string>
     */
    public function artifacts(): array;
    
    /**
     * Returns the hooks.
     *
     * @return array<int, string>
     */
    public function hooks(): array;
    
    /**
     * Returns metadata.
     *
     * @return array
     */
    public function meta(): array;

    /**
     * Returns the creation timestamp.
     *
     * @return null|DateTimeInterface
     */
    public function createdAt(): null|DateTimeInterface;
    
    /**
     * Returns whether an attribute exists or not.
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool;
    
    /**
     * Returns an attribute value by name.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed;    
}