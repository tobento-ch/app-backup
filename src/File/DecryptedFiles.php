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

use Psr\Http\Message\StreamFactoryInterface;
use Traversable;
use Tobento\Service\Encryption\EncrypterInterface;

/**
 * DecryptedFiles
 *
 * Wraps a FilesInterface and returns DecryptedFile instances
 * for all files except manifest.json, which must remain raw.
 */
final class DecryptedFiles implements FilesInterface
{
    /**
     * Create a new instance.
     *
     * @param FilesInterface $files
     * @param EncrypterInterface $encrypter
     * @param StreamFactoryInterface $streamFactory
     */
    public function __construct(
        private FilesInterface $files,
        private EncrypterInterface $encrypter,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Returns the iterator.
     *
     * @return Traversable<int, FileInterface>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->files as $file) {

            // Manifest.json must NOT be decrypted
            if ($file->path() === 'manifest.json') {
                yield $file;
                continue;
            }

            yield new DecryptedFile(
                file: $file,
                encrypter: $this->encrypter,
                streamFactory: $this->streamFactory,
            );
        }
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
            foreach ($this->files as $file) {
                if ($callback($file)) {
                    yield $file;
                }
            }
        };

        return new static(new Files($generator()), $this->encrypter, $this->streamFactory);
    }
}