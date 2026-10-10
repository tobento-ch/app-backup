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
 
namespace Tobento\App\Backup\File;

use IteratorAggregate;
use Psr\Http\Message\StreamInterface;

/**
 * @extends IteratorAggregate<int, FileInterface>
 */
interface FilesInterface extends IteratorAggregate
{
    /**
     * Returns a new instance with the filtered files.
     *
     * @param callable $callback
     * @return static
     */
    public function filter(callable $callback): static;
}