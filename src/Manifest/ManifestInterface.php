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

interface ManifestInterface
{
    /**
     * Returns the process ID.
     *
     * @return int
     */
    public function processId(): int;

    /**
     * Returns the creation timestamp.
     *
     * @return DateTimeImmutable
     */
    public function createdAt(): DateTimeImmutable;

    /**
     * Returns the actions array.
     *
     * @return array
     */
    public function actions(): array;

    /**
     * Returns the name (if any).
     *
     * @return null|string
     */
    public function name(): null|string;
    
    /**
     * Returns the user ID (if any).
     *
     * @return null|int|string
     */
    public function userId(): null|int|string;
    
    /**
     * Returns the domain (if any).
     *
     * @return null|string
     */
    public function domain(): null|string;
    
    /**
     * Returns meta data.
     *
     * @return array<string, string>
     */
    public function meta(): array;

    /**
     * Returns the manifest as an array.
     *
     * @return array
     */
    public function toArray(): array;
    
    /**
     * Returns the manifest as a JSON string.
     *
     * @return string
     */
    public function toJson(): string;
}