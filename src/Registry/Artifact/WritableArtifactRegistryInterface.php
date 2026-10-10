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

use Psr\Container\ContainerInterface;
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Exception\ArtifactCreateException;
use Tobento\App\Backup\Repo\ProcessEntityInterface;

interface WritableArtifactRegistryInterface extends ArtifactRegistryInterface
{
    /**
     * Create a writable artifact for backup.
     *
     * @param ContainerInterface $container
     * @param ProcessEntityInterface $entity
     * @return WritableArtifactInterface
     * @throws ArtifactCreateException
     */
    public function createWritableArtifact(
        ContainerInterface $container,
        ProcessEntityInterface $entity
    ): WritableArtifactInterface;
}