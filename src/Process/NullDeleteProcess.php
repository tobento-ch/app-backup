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
 * A delete process with no items. Used when an artifact has nothing to delete.
 */
final class NullDeleteProcess implements DeleteProcessInterface
{
    /**
     * Determine if there are remaining items to delete.
     *
     * @return bool
     */
    public function hasRemainingItems(): bool
    {
        return false;
    }

    /**
     * Get the next item to delete.
     *
     * @return mixed The next item to delete.
     */
    public function nextItem(): mixed
    {
        return null;
    }

    /**
     * Delete the specified item.
     *
     * @param mixed $item The item to delete.
     * @return void
     */
    public function deleteItem(mixed $item): void
    {
        //
    }
}