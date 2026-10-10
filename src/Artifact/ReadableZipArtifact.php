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

use Generator;
use League\MimeTypeDetection\GeneratedExtensionToMimeTypeMap;
use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Exception\FileNotFoundException;
use Tobento\App\Backup\File\File;
use Tobento\App\Backup\File\FileInterface;
use Tobento\App\Backup\File\Files;
use Tobento\App\Backup\File\FilesInterface;
use ZipArchive;

final class ReadableZipArtifact implements ReadableArtifactInterface
{
    private ZipArchive $zip;
    
    public function __construct(
        private StreamFactoryInterface $streamFactory,
        private string $zipPath,
        private string $id,
        private int $throughputInBytes = 50_000_000,
    ) {
        $this->zip = new ZipArchive();
        $result = $this->zip->open($zipPath);

        if ($result !== true) {
            throw new \RuntimeException(sprintf('Unable to open ZIP: %s', $zipPath));
        }
    }
    
    public function id(): string
    {
        return $this->id;
    }
    
    public function zipPath(): string
    {
        return $this->zipPath;
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
    ): FileAcceptResultInterface
    {
        // Permanent impossibility: file can never fit in the full window.
        $maxReadableBytes = (float)$this->throughputInBytes * $timeBudgetInSeconds;
        if ($fileSize > $maxReadableBytes) {
            return new FileAcceptResult(
                decision: FileAcceptDecision::NeverFits,
                reason: sprintf('File %s cannot be read within the configured time budget.', $filePath),
                throw: true
            );
        }

        // Temporary impossibility: not enough time left in this window.
        $maxReadableNow = (float)$this->throughputInBytes * $remainingSeconds;
        if ($fileSize > $maxReadableNow) {
            return new FileAcceptResult(FileAcceptDecision::Defer);
        }

        // Safe to read now.
        return new FileAcceptResult(FileAcceptDecision::Yes);
    }

    public function files(string $path = '', bool $recursive = false): FilesInterface
    {
        return new Files(
            $this->iterateZipEntries($path, $recursive)
        );
    }
    
    public function file(string $path): FileInterface
    {
        $index = $this->zip->locateName($path);

        if ($index === false) {
            throw new FileNotFoundException(
                path: $path,
                message: sprintf('File not found in artifact: %s', $path),
            );
        }

        $stat = $this->zip->statIndex($index);

        $map = new GeneratedExtensionToMimeTypeMap();
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = $map->lookupMimeType($extension) ?? 'application/octet-stream';

        return new File(
            path: $path,
            stream: fn() => $this->streamFactory->createStreamFromResource(
                $this->zip->getStream($path)
            ),
            size: $stat['size'] ?? 0,
            mimeType: $mimeType,
        );
    }

    private function iterateZipEntries(string $path, bool $recursive): Generator
    {
        $map = new GeneratedExtensionToMimeTypeMap();
        $prefix = trim($path, '/');

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            $filePath = $stat['name'];

            if (str_ends_with($filePath, '/')) {
                continue;
            }

            if ($prefix !== '' && !str_starts_with($filePath, $prefix . '/')) {
                continue;
            }

            if (!$recursive) {
                $relative = substr($filePath, strlen($prefix) + 1);
                if (str_contains($relative, '/')) {
                    continue;
                }
            }
            
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            $mimeType = $map->lookupMimeType($extension) ?? 'application/octet-stream';

            yield new File(
                path: $filePath,
                stream: fn() => $this->streamFactory->createStreamFromResource(
                    $this->zip->getStream($filePath)
                ),
                size: $stat['size'] ?? 0,
                mimeType: $mimeType,
            );
        }
    }

    public function __destruct()
    {
        $this->zip->close();
    }
}