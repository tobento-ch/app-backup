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
use RuntimeException;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Manifest\ManifestFactoryInterface;
use Tobento\Service\FileStorage\StorageInterface;

final class WritableFileStorageArtifact implements WritableArtifactInterface
{
    private int $bytesThisJob = 0;

    /**
     * Create a new instance.
     *
     * @param StreamFactoryInterface $streamFactory
     * @param ManifestFactoryInterface $manifestFactory Factory for creating manifest instances.
     * @param StorageInterface $storage
     * @param string $folder The folder to write the files.
     * @param int $throughputInBytes Estimated write throughput in bytes per second.
     *    Used to determine whether a file can fit into the time window.
     */
    public function __construct(
        private StreamFactoryInterface $streamFactory,
        private ManifestFactoryInterface $manifestFactory,
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
    
    /**
     * Decide whether the artifact can safely accept a file at this moment.
     *
     * The caller provides both the remaining time in the current processing window
     * and the total time budget allocated for such a window. The artifact evaluates
     * whether the file can be written now, whether it could ever fit within the
     * given time budget, or whether writing should be deferred.
     *
     * @param string $filePath
     * @param int $fileSize Size of the file in bytes.
     * @param float $remainingSeconds Time left in the current processing window.
     * @param float $timeBudgetInSeconds Total allowed duration of a processing window.
     * @return FileAcceptResultInterface
     */
    public function canWriteFile(
        string $filePath,
        int $fileSize,
        float $remainingSeconds,
        float $timeBudgetInSeconds
    ): FileAcceptResultInterface {

        // Negative size → invalid
        if ($fileSize < 0) {
            return new FileAcceptResult(
                decision: FileAcceptDecision::NeverFits,
                reason: sprintf('Negative file size for %s', $filePath),
                throw: true,
                skip: false,
            );
        }

        // Empty file is valid → accept
        // (no check needed)

        // Estimated write time
        $estimatedSeconds = $fileSize / $this->throughputInBytes;

        // Structural impossibility: file can never fit
        if ($estimatedSeconds > $timeBudgetInSeconds) {
            return new FileAcceptResult(
                decision: FileAcceptDecision::NeverFits,
                reason: sprintf(
                    'File %s requires %.2f s to write, exceeding full process window %.2f s.',
                    $filePath,
                    $estimatedSeconds,
                    $timeBudgetInSeconds
                ),
                throw: true,
                skip: false
            );
        }

        // Defer: fits in full window, but not in remaining window
        $estimatedThisJob = ($this->bytesThisJob + $fileSize) / $this->throughputInBytes;

        if ($estimatedThisJob > $remainingSeconds) {
            return new FileAcceptResult(
                decision: FileAcceptDecision::Defer,
                reason: sprintf(
                    'Writing file %s would require %.2f s, exceeding remaining window %.2f s.',
                    $filePath,
                    $estimatedThisJob,
                    $remainingSeconds
                ),
                throw: false,
                skip: false
            );
        }

        // Yes: safe to write now
        return new FileAcceptResult(
            decision: FileAcceptDecision::Yes,
            reason: '',
            throw: false,
            skip: false
        );
    }

    /**
     * Write a file into the artifact from stream.
     *
     * @param string $path
     * @param StreamInterface $stream
     * @param int $fileSize Size of the file in bytes.
     * @return void
     */
    public function writeFileFromStream(string $path, StreamInterface $stream, int $fileSize): void
    {
        $fullPath = $this->folder.'/'.$path;
        
        $this->storage->write(path: $fullPath, content: $stream);
        
        $this->bytesThisJob += $fileSize;
    }
    
    /**
     * Write a file into the artifact from path.
     *
     * @param string $path
     * @param string $sourcePath
     * @param int $fileSize Size of the file in bytes.
     * @return void
     */
    public function writeFileFromPath(string $path, string $sourcePath, int $fileSize): void
    {
        $stream = $this->streamFactory->createStreamFromFile(filename: $sourcePath, mode: 'r');

        $this->writeFileFromStream(path: $path, stream: $stream, fileSize: $fileSize);
    }
    
    /**
     * Persist the artifact’s current partial state.
     *
     * This ensures that data written via fileWrite() is durably stored
     * even though the artifact is not yet finalized. Implementations may
     * flush buffers, close and reopen underlying handles, or perform other
     * work needed to make the current state safe across job boundaries.
     *
     * @return void
     */
    public function partialPersist(): void
    {
         //
    }
    
    /**
     * Finalize the artifact (write manifest, close handles, etc.)
     *
     * @param BackupProcessInterface $process
     * @return void
     */
    public function finalize(BackupProcessInterface $process): void
    {
        // Build and write manifest
        $manifest = $this->manifestFactory->createManifestFromBackupProcess($process);
        
        $this->storage->write(
            path: $this->folder.'/manifest.json',
            content: $manifest->toJson(),
        );
    }
}