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
use Tobento\App\Backup\Registry\Artifact\ReadableArtifactRegistryInterface;
use Tobento\Service\Support\Arrayable;

/**
 * RestoreEntityInterface
 *
 * Represents a single restore record stored in the repository.
 */
interface RestoreEntityInterface extends Arrayable
{
    /**
     * Returns the entity id.
     *
     * @return string
     */
    public function id(): string;

    /**
     * Returns the entity name.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Returns the registry type.
     *
     * @return string
     */
    public function type(): string;

    /**
     * Returns the raw repository item.
     *
     * @return mixed
     */
    public function raw(): mixed;

    /**
     * Returns the registry that produced the entity.
     *
     * @return ReadableArtifactRegistryInterface
     */
    public function registry(): ReadableArtifactRegistryInterface;
    
    /**
     * Returns the user ID who made the backup.
     *
     * @return null|int
     */
    public function userId(): null|int;
    
    /**
     * Returns the domain which the backup was created from.
     *
     * @return null|string
     */
    public function domain(): null|string;

    /**
     * Returns the entity creation date.
     *
     * @return null|DateTimeInterface
     */
    public function createdAt(): null|DateTimeInterface;
}