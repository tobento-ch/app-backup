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

interface ArtifactAdapterInterface
{
    /**
     * Returns the adapted artifact.
     *
     * @return ArtifactInterface
     */
    public function adapted(): ArtifactInterface;
}