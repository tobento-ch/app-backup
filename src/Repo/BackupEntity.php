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
 * BackupEntity
 *
 * Represents a single backup record stored in the repository.
 */
class BackupEntity implements BackupEntityInterface
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
     * Returns the translation ID.
     *
     * @return int
     */
    public function id(): int
    {
        $id = $this->attributes['id'] ?? 0;
        return is_int($id) ? $id : 0;
    }
    
    /**
     * Returns the name.
     *
     * @return string
     */
    public function name(): string
    {
        $name = $this->attributes['name'] ?? 'backup';
        return is_string($name) ? $name : 'backup';
    }

    /**
     * Returns the application identifier.
     *
     * @return string
     */
    public function appId(): string
    {
        $value = $this->attributes['app_id'] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * Returns the user ID who last edited the translation.
     *
     * @return null|int
     */
    public function userId(): null|int
    {
        $value = $this->attributes['user_id'] ?? null;
        return is_int($value) ? $value : null;
    }

    /**
     * Returns the artifacts.
     *
     * @return array<int, string>
     */
    public function artifacts(): array
    {
        return Arr::flatten($this->attributes['options']['artifacts'] ?? []);
    }
    
    /**
     * Returns the hooks.
     *
     * @return array<int, string>
     */
    public function hooks(): array
    {
        return Arr::flatten($this->attributes['options']['hooks'] ?? []);
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
     * Returns the creation timestamp.
     *
     * @return null|DateTimeInterface
     */
    public function createdAt(): null|DateTimeInterface
    {
        $value = $this->attributes['created_at'] ?? null;

        return $value instanceof DateTimeInterface ? $value : null;
    }
    
    /**
     * Returns whether an attribute exists or not.
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return Arr::has($this->attributes, $name);
    }
    
    /**
     * Returns an attribute value by name.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return Arr::get($this->attributes, $name, $default);
    }
    
    /**
     * Object to array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return new Collection($this->attributes)->toArray();
    }
}