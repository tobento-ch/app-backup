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

namespace Tobento\App\Backup\Process;

/**
 * Represents a deletion process for an artifact.
 */
interface DeleteProcessInterface
{
    /**
     * Determine if there are remaining items to delete.
     *
     * @return bool
     */
    public function hasRemainingItems(): bool;

    /**
     * Get the next item to delete.
     *
     * @return mixed The next item to delete.
     */
    public function nextItem(): mixed;

    /**
     * Delete the specified item.
     *
     * @param mixed $item The item to delete.
     * @return void
     */
    public function deleteItem(mixed $item): void;
}