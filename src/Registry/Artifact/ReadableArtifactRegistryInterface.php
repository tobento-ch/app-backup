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
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Exception\ArtifactCreateException;
use Tobento\App\Backup\Repo\ProcessEntityInterface;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\Service\Repository\RepositoryInterface;

interface ReadableArtifactRegistryInterface extends ArtifactRegistryInterface
{
    /**
     * Create a readable artifact for restore.
     *
     * @param ContainerInterface $container
     * @param ProcessEntityInterface $entity
     * @return ReadableArtifactInterface
     * @throws ArtifactCreateException
     */
    public function createReadableArtifact(
        ContainerInterface $container,
        ProcessEntityInterface $entity
    ): ReadableArtifactInterface;
    
    /**
     * Create the underlying repository used to list restore candidates.
     *
     * @param ContainerInterface $container
     * @return RepositoryInterface
     */
    public function createRepository(ContainerInterface $container): RepositoryInterface;

    /**
     * Map a raw repository item to a restore entity.
     *
     * @param mixed $raw
     * @param ContainerInterface $container
     * @return RestoreEntityInterface
     */
    public function mapEntity(mixed $raw, ContainerInterface $container): RestoreEntityInterface;
}