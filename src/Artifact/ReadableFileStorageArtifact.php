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

use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Exception\FileNotFoundException;
use Tobento\Service\FileStorage\FileNotFoundException as FileStorageNotFoundException;
use Tobento\App\Backup\File\File;
use Tobento\App\Backup\File\FileInterface;
use Tobento\App\Backup\File\Files;
use Tobento\App\Backup\File\FilesInterface;
use Tobento\Service\FileStorage\StorageInterface;

final class ReadableFileStorageArtifact implements ReadableArtifactInterface
{
    public function __construct(
        private StorageInterface $storage,
        private string $folder,
        private int $throughputInBytes = 50_000_000,
    ) {
        $this->folder = trim($folder, '/');
    }
    
    public function id(): string
    {
        return $this->folder;
    }
    
    public function storage(): StorageInterface
    {
        return $this->storage;
    }
    
    public function folder(): string
    {
        return $this->folder;
    }

    public function throughputInBytes(): int
    {
        return $this->throughputInBytes;
    }

    public function canReadFile(
        string $filePath,
        int $fileSize,
        float $remainingSeconds,
        float $timeBudgetInSeconds
    ): FileAcceptResultInterface {

        $maxReadableBytes = (float)$this->throughputInBytes * $timeBudgetInSeconds;

        if ($fileSize > $maxReadableBytes) {
            return new FileAcceptResult(
                decision: FileAcceptDecision::NeverFits,
                reason: sprintf('File %s cannot be read within the configured time budget.', $filePath),
                throw: true
            );
        }

        $maxReadableNow = (float)$this->throughputInBytes * $remainingSeconds;

        if ($fileSize > $maxReadableNow) {
            return new FileAcceptResult(FileAcceptDecision::Defer);
        }

        return new FileAcceptResult(FileAcceptDecision::Yes);
    }

    public function files(string $path = '', bool $recursive = false): FilesInterface
    {
        $fullPath = $this->folder.'/'.$path;

        $rawFiles = $this->storage
            ->with('stream', 'mimeType', 'size')
            ->files(path: $fullPath, recursive: $recursive);

        $files = [];

        foreach ($rawFiles as $raw) {
            // Convert absolute storage path → relative artifact path
            $relativePath = ltrim(substr($raw->path(), strlen($this->folder)), '/');
            
            $files[] = new File(
                path: $relativePath,
                stream: $raw->stream(),
                size: $raw->size(),
                mimeType: $raw->mimeType(),
            );
        }

        return new Files(new \ArrayIterator($files));
    }

    public function file(string $path): FileInterface
    {
        $fullPath = $this->folder.'/'.$path;
        
        try {
            $raw = $this->storage
                ->with('stream', 'mimeType', 'size')
                ->file(path: $fullPath);
        } catch (FileStorageNotFoundException $e) {
            throw new FileNotFoundException(
                path: $path,
                message: sprintf('File not found in artifact: %s', $path),
            );
        }

        return new File(
            path: $path,
            stream: $raw->stream(),
            size: $raw->size(),
            mimeType: $raw->mimeType(),
        );
    }
}