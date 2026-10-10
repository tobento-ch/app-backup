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

use Tobento\App\Backup\Artifact\WritableArtifactInterface;

/**
 * Backup process with access to a writeable artifact.
 *
 * Extends the generic process context with the ability to write
 * backup data to the underlying artifact.
 */
interface BackupProcessInterface extends ProcessInterface
{
    /**
     * Returns the writeable artifact used by the backup process.
     *
     * @return WritableArtifactInterface
     */
    public function artifact(): WritableArtifactInterface;
}