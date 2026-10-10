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

/**
 * Restore entity representing a single restore candidate.
 */
class RestoreEntity implements RestoreEntityInterface
{
    /**
     * Create a new instance.
     *
     * @param string $id Unique identifier of the restore entity.
     * @param string $name Display name of the entity.
     * @param string $type Registry type identifier.
     * @param mixed $raw The raw item returned by the underlying repository.
     * @param ReadableArtifactRegistryInterface $registry The registry that produced the entity.
     * @param null|int $userId The user id who made the backup.
     * @param null|string The domain which the backup was created from.
     * @param null|DateTimeInterface $createdAt The creation date of the entity.
     */
    public function __construct(
        protected string $id,
        protected string $name,
        protected string $type,
        protected mixed $raw,
        protected ReadableArtifactRegistryInterface $registry,
        protected null|int $userId = null,
        protected null|string $domain = null,
        protected null|DateTimeInterface $createdAt = null,
    ) {}

    /**
     * Returns the entity id.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Returns the entity name.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Returns the registry type.
     *
     * @return string
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Returns the raw repository item.
     *
     * @return mixed
     */
    public function raw(): mixed
    {
        return $this->raw;
    }

    /**
     * Returns the registry that produced the entity.
     *
     * @return ReadableArtifactRegistryInterface
     */
    public function registry(): ReadableArtifactRegistryInterface
    {
        return $this->registry;
    }
    
    /**
     * Returns the user ID who made the backup.
     *
     * @return null|int
     */
    public function userId(): null|int
    {
        return $this->userId;
    }
    
    /**
     * Returns the domain which the backup was created from.
     *
     * @return null|string
     */
    public function domain(): null|string
    {
        return $this->domain;
    }

    /**
     * Returns the entity creation date.
     *
     * @return null|DateTimeInterface
     */
    public function createdAt(): null|DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * Object to array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'type' => $this->type(),
            'user_id' => $this->userId(),
            'domain' => $this->domain(),
            'created_at' => $this->createdAt()?->format(DateTimeInterface::ATOM),
        ];
    }
}