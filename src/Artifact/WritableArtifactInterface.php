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
use Tobento\App\Backup\Process\BackupProcessInterface;

interface WritableArtifactInterface extends ArtifactInterface
{
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
    ): FileAcceptResultInterface;
    
    /**
     * Write a file into the artifact from stream.
     *
     * @param string $path
     * @param StreamInterface $stream
     * @param int $fileSize Size of the file in bytes.
     * @return void
     */
    public function writeFileFromStream(string $path, StreamInterface $stream, int $fileSize): void;
    
    /**
     * Write a file into the artifact from path.
     *
     * @param string $path
     * @param string $sourcePath
     * @param int $fileSize Size of the file in bytes.
     * @return void
     */
    public function writeFileFromPath(string $path, string $sourcePath, int $fileSize): void;
    
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
    public function partialPersist(): void;
    
    /**
     * Finalize the artifact (write manifest, close handles, etc.)
     *
     * @param BackupProcessInterface $process
     * @return void
     */
    public function finalize(BackupProcessInterface $process): void;
}