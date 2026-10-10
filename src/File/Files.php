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

use Traversable;

final class Files implements FilesInterface
{
    /**
     * Create a new instance.
     *
     * @param Traversable $iterator
     */
    public function __construct(
        private Traversable $iterator
    ) {}

    /**
     * Returns the iterator.
     *
     * @return Traversable<int, FileInterface>
     */
    public function getIterator(): Traversable
    {
        return $this->iterator;
    }

    /**
     * Returns a new instance with the filtered files.
     *
     * @param callable $callback
     * @return static
     */
    public function filter(callable $callback): static
    {
        $generator = function () use ($callback) {
            foreach ($this->iterator as $file) {
                if ($callback($file)) {
                    yield $file;
                }
            }
        };

        return new static($generator());
    }
}