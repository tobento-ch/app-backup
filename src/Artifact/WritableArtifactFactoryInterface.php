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

use Tobento\App\Backup\Repo\ProcessEntityInterface;

interface WritableArtifactFactoryInterface
{
    /**
     * Create a writable artifact for the given process entity.
     *
     * @param ProcessEntityInterface $processEntity
     * @return WritableArtifactInterface
     */
    public function createArtifactFromProcessEntity(
        ProcessEntityInterface $processEntity
    ): WritableArtifactInterface;
}