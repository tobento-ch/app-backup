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
use Tobento\App\Backup\Repo\RestoreEntityInterface;

interface ReadableArtifactFactoryInterface
{
    /**
     * Create a writable artifact for the given restore and process entity.
     *
     * @param RestoreEntityInterface $restoreEntity
     * @param ProcessEntityInterface $processEntity
     * @return ReadableArtifactInterface
     */
    public function createArtifact(
        RestoreEntityInterface $restoreEntity,
        ProcessEntityInterface $processEntity
    ): ReadableArtifactInterface;
}