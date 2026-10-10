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
use Psr\Http\Message\StreamInterface;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\Service\Encryption\EncrypterInterface;

final class EncryptionWritableArtifact implements WritableArtifactInterface, ArtifactAdapterInterface
{
    public function __construct(
        private WritableArtifactInterface $inner,
        private StreamFactoryInterface $streamFactory,
        private EncrypterInterface $encrypter,
        private int $throughputInBytes = 50_000_000,
    ) {}

    public function id(): string
    {
        return $this->adapted()->id();
    }
    
    public function adapted(): ArtifactInterface
    {
        return $this->inner;
    }
    
    public function canWriteFile(
        string $filePath,
        int $fileSize,
        float $remainingSeconds,
        float $timeBudgetInSeconds
    ): FileAcceptResultInterface {

        if ($fileSize < 0) {
            return new FileAcceptResult(
                decision: FileAcceptDecision::NeverFits,
                reason: sprintf('Negative file size for %s', $filePath),
                throw: true,
                skip: false,
            );
        }
        
        // Empty file → always encryptable
        if ($fileSize === 0) {
            return $this->inner->canWriteFile(
                $filePath,
                $fileSize,
                $remainingSeconds,
                $timeBudgetInSeconds
            );
        }

        // Estimate encryption time
        $estimatedSeconds = $fileSize / $this->throughputInBytes;

        // Permanent impossibility: encryption can never fit in full window
        if ($estimatedSeconds > $timeBudgetInSeconds) {
            return new FileAcceptResult(
                decision: FileAcceptDecision::NeverFits,
                reason: sprintf(
                    'File %s cannot be encrypted within the configured time budget. '
                    .'Requires %.2f s, window is %.2f s.',
                    $filePath,
                    $estimatedSeconds,
                    $timeBudgetInSeconds
                ),
                throw: true,
                skip: false,
            );
        }

        // Temporary impossibility: encryption fits in full window but not now
        if ($estimatedSeconds > $remainingSeconds) {
            return new FileAcceptResult(
                decision: FileAcceptDecision::Defer,
                reason: sprintf(
                    'File %s encryption requires %.2f s, but only %.2f s remain.',
                    $filePath,
                    $estimatedSeconds,
                    $remainingSeconds
                ),
                throw: false,
                skip: false,
            );
        }

        // Encryption fits now → delegate to inner artifact
        return $this->inner->canWriteFile(
            $filePath,
            $fileSize,
            $remainingSeconds,
            $timeBudgetInSeconds
        );
    }

    public function writeFileFromStream(string $path, StreamInterface $stream, int $fileSize): void
    {
        // Do NOT encrypt the manifest
        if ($path === 'manifest.json') {
            $this->inner->writeFileFromStream($path, $stream, $fileSize);
            return;
        }
        
        $data = '';
        while (! $stream->eof()) {
            $data .= $stream->read(65536);
        }

        $encrypted = $this->encrypter->encrypt($data);
        $encryptedStream = $this->streamFactory->createStream($encrypted);

        $this->inner->writeFileFromStream($path, $encryptedStream, strlen($encrypted));
    }

    public function writeFileFromPath(string $path, string $sourcePath, int $fileSize): void
    {
        $stream = $this->streamFactory->createStreamFromFile(filename: $sourcePath, mode: 'r');
        $this->writeFileFromStream($path, $stream, $fileSize);
    }

    public function partialPersist(): void
    {
        $this->inner->partialPersist();
    }

    public function finalize(BackupProcessInterface $process): void
    {
        $this->inner->finalize($process);
    }
}