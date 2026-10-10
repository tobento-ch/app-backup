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
use Psr\Http\Message\StreamInterface;
use Tobento\Service\Encryption\EncrypterInterface;

final class DecryptedFile implements FileInterface
{
    public function __construct(
        private FileInterface $file,
        private EncrypterInterface $encrypter,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function path(): string
    {
        return $this->file->path();
    }

    public function name(): string
    {
        return $this->file->name();
    }

    public function filename(): string
    {
        return $this->file->filename();
    }

    public function extension(): string
    {
        return $this->file->extension();
    }

    public function folderPath(): string
    {
        return $this->file->folderPath();
    }

    public function mimeType(): string
    {
        return $this->file->mimeType();
    }

    public function size(): int|float
    {
        // Returning encrypted size is fine; restore pipeline does not rely on exact plaintext size.
        return $this->file->size();
    }

    public function stream(): StreamInterface
    {
        // Skip manifest.json
        if ($this->file->path() === 'manifest.json') {
            return $this->file->stream();
        }
        
        $encryptedStream = $this->file->stream();

        // Read encrypted content fully (safe because only small files are encrypted)
        $encrypted = '';
        while (! $encryptedStream->eof()) {
            $encrypted .= $encryptedStream->read(65536);
        }

        // Decrypt using app-encryption
        $plain = $this->encrypter->decrypt($encrypted);

        // Create PSR-7 stream from decrypted content
        return $this->streamFactory->createStream($plain);
    }
}