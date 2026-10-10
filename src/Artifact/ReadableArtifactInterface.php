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

use Tobento\App\Backup\Exception\FileNotFoundException;
use Tobento\App\Backup\File\FileInterface;
use Tobento\App\Backup\File\FilesInterface;

interface ReadableArtifactInterface extends ArtifactInterface
{
    /**
     * Decide whether the artifact can safely read a file at this moment.
     *
     * The caller provides both the remaining time in the current processing window
     * and the total time budget allocated for such a window. The artifact evaluates
     * whether the file can be read now, whether it could ever fit within the
     * given time budget, or whether reading should be deferred.
     *
     * @param string $filePath
     * @param int $fileSize Size of the file in bytes.
     * @param float $remainingSeconds Time left in the current processing window.
     * @param float $timeBudgetInSeconds Total allowed duration of a processing window.
     * @return FileAcceptResultInterface
     */
    public function canReadFile(
        string $filePath,
        int $fileSize,
        float $remainingSeconds,
        float $timeBudgetInSeconds
    ): FileAcceptResultInterface;

    /**
     * List files inside the artifact.
     *
     * @param string $path
     * @param bool $recursive
     * @return FilesInterface
     */
    public function files(string $path = '', bool $recursive = false): FilesInterface;

    /**
     * Get a file inside the artifact.
     *
     * @param string $path
     * @return FileInterface
     * @throws FileNotFoundException
     */
    public function file(string $path): FileInterface;
}