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
use Tobento\App\Backup\Repo\ProcessEntityInterface;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\Service\Encryption\EncryptersInterface;

class ReadableArtifactFactory implements ReadableArtifactFactoryInterface
{
    /**
     * Create a new instance.
     *
     * @param ContainerInterface $container
     */
    public function __construct(
        protected ContainerInterface $container,
    ) {}
    
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
    ): ReadableArtifactInterface {
        
        $artifact = $restoreEntity->registry()->createReadableArtifact(
            container: $this->container,
            entity: $processEntity,
        );
        
        $meta = $processEntity->meta();

        if (($meta['encrypt'] ?? '0') === '1') {
            
            $runtime = $this->container->get(BackupRuntimeConfigInterface::class);
            $streamFactory = $this->container->get(StreamFactoryInterface::class);
            $encrypters = $this->container->get(EncryptersInterface::class);
            $encrypterName = $meta['encrypter'] ?? $runtime->encrypter();

            return new EncryptionReadableArtifact(
                inner: $artifact,
                streamFactory: $streamFactory,
                encrypter: $encrypters->get($encrypterName),
            );
        }

        return $artifact;
    }
}