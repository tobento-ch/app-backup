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
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Process\BackupProcessInterface;
use ZipArchive;

final class WritableArtifacts implements WritableArtifactInterface
{
    private array $artifacts = [];

    /**
     * Create a new instance.
     *
     * @param WritableArtifactInterface ...$artifacts
     */
    public function __construct(
        WritableArtifactInterface ...$artifacts
    ) {
        $this->artifacts = $artifacts;
    }
    
    /**
     * Returns the artifact identifier.
     *
     * @return string
     */
    public function id(): string
    {
        return '';
    }
    
    /**
     * Returns artifacts.
     *
     * @return array<array-key, WritableArtifactInterface>
     */
    public function artifacts(): array
    {
        return $this->artifacts;
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
        $results = [];
        foreach ($this->artifacts as $artifact) {
            $results[] = $artifact->canWriteFile(
                filePath: $filePath,
                fileSize: $fileSize,
                remainingSeconds: $remainingSeconds,
                timeBudgetInSeconds: $timeBudgetInSeconds
            );
        }
        // 1. Hard abort: any artifact throws
        foreach ($results as $result) {
            if ($result->shouldThrow()) {
                return $result;
            }
        }
        // 2. Defer: any artifact wants to defer
        foreach ($results as $result) {
            if ($result->decision() === FileAcceptDecision::Defer) {
                return $result;
            }
        }
        // 3. Skip: all artifacts skip
        $allSkip = true;
        foreach ($results as $result) {
            if (!$result->shouldSkip()) {
                $allSkip = false;
                break;
            }
        }
        if ($allSkip) {
            return $results[0]; // any skip result is fine
        }
        // 4. Yes: at least one artifact accepts (skips it, per branch 3 not all did)
        foreach ($results as $result) {
            if (!$result->shouldSkip() && !$result->shouldThrow() && $result->decision() !== FileAcceptDecision::Defer) {
                return $result;
            }
        }
        
        // 5. Fallback: no branch matched
        return $results[0] ?? new FileAcceptResult(
            decision: FileAcceptDecision::NeverFits,
            reason: 'No artifact accepted, deferred, skipped, or threw - composite fallback is NeverFits.',
            skip: false,
            throw: false,
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
        foreach($this->artifacts as $artifact) {
            $artifact->writeFileFromStream($path, $stream, $fileSize);
        }
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
        foreach($this->artifacts as $artifact) {
            $artifact->writeFileFromPath($path, $sourcePath, $fileSize);
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
        foreach($this->artifacts as $artifact) {
            $artifact->partialPersist();
        }
    }
    
    /**
     * Finalize the artifact (write manifest, close handles, etc.)
     *
     * @param BackupProcessInterface $process
     * @return void
     */
    public function finalize(BackupProcessInterface $process): void
    {
        foreach($this->artifacts as $artifact) {
            $artifact->finalize($process);
        }
    }
}