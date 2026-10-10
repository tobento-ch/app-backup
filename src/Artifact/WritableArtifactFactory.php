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

use Psr\Container\ContainerInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\WritableArtifactRegistryInterface;
use Tobento\App\Backup\Repo\ProcessEntityInterface;
use Tobento\Service\Encryption\EncryptersInterface;

class WritableArtifactFactory implements WritableArtifactFactoryInterface
{
    /**
     * Create a new instance.
     *
     * @param ArtifactRegistriesInterface $artifactRegistries
     * @param ContainerInterface $container
     */
    public function __construct(
        protected ArtifactRegistriesInterface $artifactRegistries,
        protected ContainerInterface $container,
    ) {}
    
    /**
     * Create a writable artifact for the given process entity.
     *
     * @param ProcessEntityInterface $processEntity
     * @return WritableArtifactInterface
     */
    public function createArtifactFromProcessEntity(
        ProcessEntityInterface $processEntity
    ): WritableArtifactInterface {
        
        $artifacts = [];
        
        foreach($processEntity->artifacts() as $id) {
            if (! $this->artifactRegistries->has($id)) {
                continue;
            }

            $registry = $this->artifactRegistries->get($id);

            if (! $registry instanceof WritableArtifactRegistryInterface) {
                continue;
            }

            $artifact = $registry->createWritableArtifact($this->container, $processEntity);
            
            // Apply encryption adapter if enabled
            if ($processEntity->meta()['encrypt'] ?? false) {
                
                $runtime = $this->container->get(BackupRuntimeConfigInterface::class);
                $encrypters = $this->container->get(EncryptersInterface::class);
                $encrypterName = $processEntity->meta()['encrypter'] ?? $runtime->encrypter();
                
                $artifact = new EncryptionWritableArtifact(
                    inner: $artifact,
                    streamFactory: $this->container->get(StreamFactoryInterface::class),
                    encrypter: $encrypters->get($encrypterName),
                    throughputInBytes: $runtime->encryptThroughputInBytes(),
                );
            }
            
            $artifacts[$id] = $artifact;
        }
        
        return new WritableArtifacts(...array_values($artifacts));
    }
}