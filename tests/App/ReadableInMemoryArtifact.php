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

use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Artifact\FileAcceptResult;
use Tobento\App\Backup\Artifact\FileAcceptResultInterface;
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Exception\FileNotFoundException;
use Tobento\App\Backup\File\File;
use Tobento\App\Backup\File\FileInterface;
use Tobento\App\Backup\File\Files;
use Tobento\App\Backup\File\FilesInterface;

class ReadableInMemoryArtifact implements ReadableArtifactInterface
{
    private bool $filesCalled = false;
    private bool $fileCalled = false;
    private ?FileAcceptResultInterface $forcedResult = null;
    
    /**
     * @param array<string, string> $files path => raw content, seeded by the test.
     */
    public function __construct(
        private StreamFactoryInterface $streamFactory,
        protected array $files = [],
        protected string $id = '',
    ) {}

    public function id(): string
    {
        return $this->id;
    }
    
    public function canReadFile(
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

    public function files(string $path = '', bool $recursive = false): FilesInterface
    {
        $this->filesCalled = true;
        
        return new Files(
            $this->iterateFiles($path, $recursive)
        );
    }

    public function file(string $path): FileInterface
    {
        $this->fileCalled = true;
        
        if (!array_key_exists($path, $this->files)) {
            throw new FileNotFoundException(
                path: $path,
                message: sprintf('File not found in artifact: %s', $path),
            );
        }

        return $this->makeFile($path, $this->files[$path]);
    }

    private function iterateFiles(string $path, bool $recursive): \Generator
    {
        $prefix = trim($path, '/');

        foreach ($this->files as $filePath => $content) {
            if ($prefix !== '' && !str_starts_with($filePath, $prefix.'/')) {
                continue;
            }

            if (!$recursive) {
                $relative = $prefix === '' ? $filePath : substr($filePath, strlen($prefix) + 1);
                if (str_contains($relative, '/')) {
                    continue;
                }
            }

            yield $this->makeFile($filePath, $content);
        }
    }

    private function makeFile(string $path, string $content): FileInterface
    {
        return new File(
            path: $path,
            stream: fn() => $this->streamFactory->createStream($content),
            size: strlen($content),
            mimeType: 'application/octet-stream',
        );
    }

    // --- test helper, not part of the interface ---
    public function forceCanReadFileResult(FileAcceptResultInterface $result): void
    {
        $this->forcedResult = $result;
    }
    
    public function wasRead(): bool
    {
        return $this->filesCalled || $this->fileCalled;
    }
    
    public function seedFile(string $path, string $content): void
    {
        $this->files[$path] = $content;
    }
}