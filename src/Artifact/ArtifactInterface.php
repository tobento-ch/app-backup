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

interface ArtifactInterface
{
    /**
     * Returns the artifact identifier used for restore and deletion.
     *
     * The identifier is a registry-specific relative path such as
     * a filename (ZIP), folder name (file storage).
     *
     * @return string The relative artifact identifier.
     */
    public function id(): string;
}