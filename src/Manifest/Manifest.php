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

namespace Tobento\App\Backup\Manifest;

use DateTimeImmutable;

class Manifest implements ManifestInterface
{
    /**
     * Manifest constructor.
     *
     * @param int $processId The backup process ID.
     * @param \DateTimeImmutable $createdAt The timestamp when the manifest was created.
     * @param array $actions The serialized backup actions.
     * @param null|string $name A human‑friendly name.
     * @param null|int|string $userId The user ID associated with the backup (optional).
     * @param null|string $domain The domain where the backup was created (optional).
     * @param array<string, string> $meta The meta data
     */
    public function __construct(
        protected int $processId,
        protected DateTimeImmutable $createdAt,
        protected array $actions,
        protected null|string $name = null,
        protected null|int|string $userId = null,
        protected null|string $domain = null,
        protected array $meta = [],
    ) {}

    /**
     * Returns the process ID.
     *
     * @return int
     */
    public function processId(): int
    {
        return $this->processId;
    }

    /**
     * Returns the creation timestamp.
     *
     * @return DateTimeImmutable
     */
    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the actions array.
     *
     * @return array
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * Returns the name (if any).
     *
     * @return null|string
     */
    public function name(): null|string
    {
        return $this->name;
    }
    
    /**
     * Returns the user ID (if any).
     *
     * @return null|int|string
     */
    public function userId(): null|int|string
    {
        return $this->userId;
    }
    
    /**
     * Returns the domain (if any).
     *
     * @return null|string
     */
    public function domain(): null|string
    {
        return $this->domain;
    }
    
    /**
     * Returns meta data.
     *
     * @return array<string, string>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * Returns the manifest as an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'process_id' => $this->processId(),
            'created_at' => $this->createdAt()->format('c'),
            'actions' => $this->actions(),
            'name' => $this->name(),
            'user_id' => $this->userId(),
            'domain' => $this->domain(),
            'meta' => $this->meta(),
        ];
    }

    /**
     * Returns the manifest as a JSON string.
     *
     * @return string
     */
    public function toJson(): string
    {
        return (string)json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}