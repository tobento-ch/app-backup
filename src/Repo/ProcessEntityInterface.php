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
 * ProcessEntityInterface
 *
 * Represents a single process record stored in the repository.
 */
interface ProcessEntityInterface extends Arrayable
{
    /**
     * Returns the ID.
     *
     * @return int
     */
    public function id(): int;

    /**
     * Returns the type.
     *
     * @return string
     */
    public function type(): string;
    
    /**
     * Returns the type identifier.
     *
     * @return int
     */
    public function typeId(): int;

    /**
     * Returns the human‑friendly name assigned to this process entity.
     * May be null if no name was provided.
     *
     * @return null|string
     */
    public function name(): null|string;
    
    /**
     * Returns the user ID who created the process.
     *
     * @return null|int
     */
    public function userId(): null|int;

    /**
     * Returns the workflow status.
     *
     * @return string
     */
    public function status(): string;

    /**
     * Returns the actions.
     *
     * @return array<string, array>
     */
    public function actions(): array;
    
    /**
     * Returns the processed actions.
     *
     * @return array<int, string> List of processed action identifiers.
     */
    public function processedActions(): array;
    
    /**
     * Returns the action states.
     *
     * @return array<string, array>
     */
    public function actionStates(): array;
    
    /**
     * Returns a new instance with the given source.
     *
     * @param mixed $source
     * @return static
     */
    public function withArtifactSource(mixed $source): static;
    
    /**
     * Returns the raw source associated with this process.
     * May be a filename (string), a File, a Folder, or null.
     *
     * @return mixed
     */
    public function artifactSource(): mixed;
    
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
     * Returns the runtime in seconds.
     *
     * @return float
     */
    public function runtimeInSeconds(): float;
    
    /**
     * Returns the memory peak bytes.
     *
     * @return int
     */
    public function memoryPeakBytes(): int;
    
    /**
     * Returns the creation date.
     *
     * @return null|DateTimeInterface
     */
    public function createdAt(): null|DateTimeInterface;
}