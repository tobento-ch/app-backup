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

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Manifest\ManifestFactoryInterface;
use ZipArchive;

/**
 * Writable ZIP artifact implementation.
 *
 * Note: ZipArchive automatically saves changes when the object is destroyed,
 * even without an explicit close() call. This means each job naturally
 * persists its writes when the WritableZipArtifact instance goes out of
 * scope. If the worker process is killed before destruction occurs (e.g.
 * timeout, OOM), the current job’s writes may be lost. ZIP is therefore not
 * used as a safety artifact — use FTP/S3/directory artifacts for large or
 * critical backups.
 *
 * See https://www.php.net/manual/en/ziparchive.close.php
 */
final class WritableZipArtifact implements WritableArtifactInterface
{
    private ZipArchive $zip;
    
    private int $bytesThisJob = 0;

    /**
     * Create a new writable ZIP artifact.
     *
     * @param ManifestFactoryInterface $manifestFactory Factory for creating manifest instances.
     * @param string $zipPath Absolute path where the ZIP file will be created.
     * @param string $id
     * @param int $throughputInBytes Estimated write throughput in bytes per second.
     *                               Used to determine whether a file can fit into the time window.
     */
    public function __construct(
        private ManifestFactoryInterface $manifestFactory,
        private string $zipPath,
        private string $id,
        private int $throughputInBytes = 50_000_000,
    ) {
        $this->zip = new ZipArchive();

        if ($this->zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException(sprintf(
                'Unable to create ZIP artifact at path: %s',
                $zipPath
            ));
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
        $path = ltrim($path, '/');

        $dir = dirname($path);
        if ($dir !== '.') {
            $this->ensureZipDirectory($dir);
        }

        $contents = $this->readStreamContents($stream);
        $this->zip->addFromString($path, $contents);

        $this->bytesThisJob += strlen($contents);
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
        $path = ltrim($path, '/');

        $dir = dirname($path);
        if ($dir !== '.') {
            $this->ensureZipDirectory($dir);
        }

        $this->zip->addFile($sourcePath, $path);

        $size = filesize($sourcePath);
        if ($size !== false) {
            $this->bytesThisJob += $size;
        }
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
         // ZIP artifacts do not require explicit partial persistence. All data
         // written via writeFileFromStream() or writeFileFromPath() is naturally
         // persisted when the ZipArchive instance is destroyed at the end of the
         // job’s execution. Because this implementation keeps the ZIP open until
         // finalize(), and each job creates a fresh artifact instance, no explicit
         // partial persistence step is needed here.
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
        $this->zip->addFromString('manifest.json', $manifest->toJson());

        $this->zip->close();
    }

    private function readStreamContents(StreamInterface $stream): string
    {
        $buffer = '';

        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);
        }

        return $buffer;
    }
    
    private function ensureZipDirectory(string $dir): void
    {
        $parts = explode('/', $dir);
        $path = '';

        foreach ($parts as $part) {
            $path = ($path === '') ? $part : $path . '/' . $part;
            $this->zip->addEmptyDir($path);
        }
    }
}