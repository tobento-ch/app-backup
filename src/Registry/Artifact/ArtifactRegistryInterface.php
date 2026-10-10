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

namespace Tobento\App\Backup\Registry\Artifact;

interface ArtifactRegistryInterface
{
    /**
     * Unique identifier.
     *
     * @return string
     */
    public function id(): string;
    
    /**
     * Returns the registry name.
     *
     * @return string
     */
    public function name(): string;
}