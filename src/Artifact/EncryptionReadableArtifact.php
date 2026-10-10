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

namespace Tobento\App\Backup\Artifact;

use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\Backup\Exception\FileNotFoundException;
use Tobento\App\Backup\File\DecryptedFile;
use Tobento\App\Backup\File\DecryptedFiles;
use Tobento\App\Backup\File\FileInterface;
use Tobento\App\Backup\File\FilesInterface;
use Tobento\Service\Encryption\EncrypterInterface;

final class EncryptionReadableArtifact implements ReadableArtifactInterface, ArtifactAdapterInterface
{
    public function __construct(
        private ReadableArtifactInterface $inner,
        private StreamFactoryInterface $streamFactory,
        private EncrypterInterface $encrypter,
    ) {}

    public function id(): string
    {
        return $this->adapted()->id();
    }
    
    public function adapted(): ArtifactInterface
    {
        return $this->inner;
    }
    
    public function canReadFile(
        string $filePath,
        int $fileSize,
        float $remainingSeconds,
        float $timeBudgetInSeconds
    ): FileAcceptResultInterface {
        // Encryption does not change timing; delegate.
        return $this->inner->canReadFile(
            $filePath,
            $fileSize,
            $remainingSeconds,
            $timeBudgetInSeconds
        );
    }

    public function files(string $path = '', bool $recursive = false): FilesInterface
    {
        $files = $this->inner->files($path, $recursive);
        
        return new DecryptedFiles(
            files: $files,
            encrypter: $this->encrypter,
            streamFactory: $this->streamFactory,
        );
    }

    public function file(string $path): FileInterface
    {
        $file = $this->inner->file($path);

        return new DecryptedFile(
            file: $file,
            encrypter: $this->encrypter,
            streamFactory: $this->streamFactory,
        );
    }
}