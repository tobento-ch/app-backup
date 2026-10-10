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

namespace Tobento\App\Backup\Process;

use Tobento\App\Backup\Artifact\ReadableArtifactInterface;

/**
 * Restore process with access to a readable artifact.
 *
 * Extends the generic process context with the ability to read
 * data from the underlying artifact during restoration.
 */
interface RestoreProcessInterface extends ProcessInterface
{
    /**
     * Returns the readable artifact used by the restore process.
     *
     * @return ReadableArtifactInterface
     */
    public function artifact(): ReadableArtifactInterface;
}