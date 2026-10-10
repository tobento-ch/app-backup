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
use Tobento\Service\Collection\Arr;
use Tobento\Service\Collection\Collection;

/**
 * ProcessEntity
 *
 * Represents a single process record stored in the repository.
 */
class ProcessEntity implements ProcessEntityInterface
{
    /**
     * Create a new instance.
     *
     * @param array $attributes The raw attributes.
     */
    public function __construct(
        protected array $attributes = [],
    ) {}

    /**
     * Returns all raw attributes.
     *
     * @return array
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Returns the ID.
     *
     * @return int
     */
    public function id(): int
    {
        $id = $this->attributes['id'] ?? 0;
        return is_int($id) ? $id : 0;
    }

    /**
     * Returns the type.
     *
     * @return string
     */
    public function type(): string
    {
        $value = $this->attributes['type'] ?? '';
        return is_string($value) ? $value : '';
    }
    
    /**
     * Returns the type identifier.
     *
     * @return int
     */
    public function typeId(): int
    {
        $value = $this->attributes['type_id'] ?? 0;
        return is_int($value) ? $value : 0;
    }

    /**
     * Returns the human‑friendly name assigned to this process entity.
     * May be null if no name was provided.
     *
     * @return null|string
     */
    public function name(): null|string
    {
        $value = $this->attributes['name'] ?? null;
        return is_string($value) ? $value : null;
    }
    
    /**
     * Returns the user ID who created the process.
     *
     * @return null|int
     */
    public function userId(): null|int
    {
        $value = $this->attributes['user_id'] ?? null;
        return is_int($value) ? $value : null;
    }

    /**
     * Returns the workflow status.
     *
     * @return string
     */
    public function status(): string
    {
        $value = $this->attributes['status'] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * Returns the actions.
     *
     * @return array<string, array>
     */
    public function actions(): array
    {
        $value = $this->attributes['actions'] ?? [];
        return is_array($value) ? $value : [];
    }
    
    /**
     * Returns the processed actions.
     *
     * @return array<int, string> List of processed action identifiers.
     */
    public function processedActions(): array
    {
        $value = $this->attributes['processed_actions'] ?? [];
        return is_array($value) ? $value : [];
    }
    
    /**
     * Returns the action states.
     *
     * @return array<string, array>
     */
    public function actionStates(): array
    {
        $value = $this->attributes['action_states'] ?? [];
        return is_array($value) ? $value : [];
    }
    
    /**
     * Returns a new instance with the given source.
     *
     * @param mixed $source
     * @return static
     */
    public function withArtifactSource(mixed $source): static
    {
        $new = clone $this;
        $new->attributes['artifact_source'] = $source;
        return $new;
    }
    
    /**
     * Returns the raw source associated with this process.
     * May be a filename (string), a File, a Folder, or null.
     *
     * @return mixed
     */
    public function artifactSource(): mixed
    {
        return $this->attributes['artifact_source'] ?? null;
    }
    
    /**
     * Returns the artifacts.
     *
     * @return array<int, string>
     */
    public function artifacts(): array
    {
        return Arr::flatten($this->attributes['artifacts'] ?? []);
    }
    
    /**
     * Returns the hooks.
     *
     * @return array<int, string>
     */
    public function hooks(): array
    {
        return Arr::flatten($this->attributes['hooks'] ?? []);
    }
    
    /**
     * Returns metadata.
     *
     * @return array
     */
    public function meta(): array
    {
        $value = $this->attributes['meta'] ?? [];
        return is_array($value) ? $value : [];
    }

    /**
     * Returns the runtime in seconds.
     *
     * @return float
     */
    public function runtimeInSeconds(): float
    {
        $value = $this->attributes['runtime_seconds'] ?? 0.0;

        return is_float($value) ? $value : 0;
    }
    
    /**
     * Returns the memory peak bytes.
     *
     * @return int
     */
    public function memoryPeakBytes(): int
    {
        $value = $this->attributes['memory_peak_bytes'] ?? 0;

        return is_int($value) ? $value : 0;
    }
    
    /**
     * Returns the creation date.
     *
     * @return null|DateTimeInterface
     */
    public function createdAt(): null|DateTimeInterface
    {
        $value = $this->attributes['created_at'] ?? null;

        return $value instanceof DateTimeInterface ? $value : null;
    }
    
    /**
     * Object to array.
     *
     * @return array
     */
    public function toArray(): array
    {
        unset($this->attributes['artifact_source']);
        
        return new Collection($this->attributes)->toArray();
    }
}