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

namespace Tobento\App\Backup\Test\App;

use Psr\Http\Message\StreamInterface;
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Artifact\FileAcceptResult;
use Tobento\App\Backup\Artifact\FileAcceptResultInterface;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Process\BackupProcessInterface;

class WritableInMemoryArtifact implements WritableArtifactInterface
{
    /**
     * @var array<string, string> path => raw content actually written.
     */
    protected array $files = [];

    protected bool $finalized = false;

    protected bool $partiallyPersisted = false;
    
    protected ?FileAcceptResultInterface $forcedResult = null;

    public function __construct(
        protected string $id = '',
    ) {}
    
    public function id(): string
    {
        return $this->id;
    }
    
    public function canWriteFile(
        string $filePath,
        int $fileSize,
        float $remainingSeconds,
        float $timeBudgetInSeconds
    ): FileAcceptResultInterface {
        return $this->forcedResult ?? new FileAcceptResult(
            FileAcceptDecision::Yes,
            reason: '',
            throw: false,
            skip: false,
        );
    }

    public function writeFileFromStream(string $path, StreamInterface $stream, int $fileSize): void
    {
        $this->files[$path] = $stream->getContents();
    }

    public function writeFileFromPath(string $path, string $sourcePath, int $fileSize): void
    {
        $this->files[$path] = file_get_contents($sourcePath);
    }

    public function partialPersist(): void
    {
        $this->partiallyPersisted = true;
    }

    public function finalize(BackupProcessInterface $process): void
    {
        $this->finalized = true;
    }

    // --- test helpers, not part of the interface ---

    public function forceCanWriteFileResult(FileAcceptResultInterface $result): void
    {
        $this->forcedResult = $result;
    }
    
    public function writtenFiles(): array
    {
        return $this->files;
    }

    public function wasFinalized(): bool
    {
        return $this->finalized;
    }
    
    public function wasPartialPersistCalled(): bool
    {
        return $this->partiallyPersisted;
    }

    public function wasFinalizeCalled(): bool
    {
        return $this->finalized;
    }

    public function assertFileWritten(string $path): void
    {
        if (!array_key_exists($path, $this->files)) {
            throw new \RuntimeException(sprintf(
                'Expected file "%s" to have been written. Written: %s',
                $path,
                implode(', ', array_keys($this->files)) ?: '(none)',
            ));
        }
    }

    public function assertFileContentSame(string $path, string $expected): void
    {
        $this->assertFileWritten($path);

        if ($this->files[$path] !== $expected) {
            throw new \RuntimeException(sprintf(
                'File "%s" content mismatch. Expected %d bytes, got %d bytes.',
                $path,
                strlen($expected),
                strlen($this->files[$path]),
            ));
        }
    }
}