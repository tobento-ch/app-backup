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

namespace Tobento\App\Backup\Test\App;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Registry\Artifact\ReadableArtifactRegistryInterface;
use Tobento\App\Backup\Registry\Artifact\WritableArtifactRegistryInterface;
use Tobento\App\Backup\Repo\ProcessEntityInterface;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\Service\Repository\RepositoryInterface;

class InMemoryArtifactRegistry implements WritableArtifactRegistryInterface, ReadableArtifactRegistryInterface
{
    private null|WritableInMemoryArtifact $writableArtifact = null;
    
    private null|ReadableInMemoryArtifact $readableArtifact = null;
    
    private null|RepositoryInterface $repository = null;
    
    final public function __construct(
        protected string $id = 'inmemory',
        protected string $name = 'InMemory',
        protected bool $newInstance = true,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }
    
    public function setRepository(RepositoryInterface $repository): static
    {
        $this->repository = $repository;
        return $this;
    }

    public function createWritableArtifact(
        ContainerInterface $container,
        ProcessEntityInterface $entity
    ): WritableArtifactInterface {
        if (! $this->newInstance && $this->writableArtifact) {
            return $this->writableArtifact;
        }
        
        return $this->writableArtifact = new WritableInMemoryArtifact();
    }

    public function writableArtifact(): null|WritableInMemoryArtifact
    {
        return $this->writableArtifact;
    }

    public function createReadableArtifact(
        ContainerInterface $container,
        ProcessEntityInterface $entity
    ): ReadableArtifactInterface {
        if (! $this->newInstance && $this->readableArtifact) {
            return $this->readableArtifact;
        }
        
        return $this->readableArtifact = new ReadableInMemoryArtifact(
            streamFactory: $container->get(StreamFactoryInterface::class),
        );
    }
    
    public function readableArtifact(): null|ReadableInMemoryArtifact
    {
        return $this->readableArtifact;
    }

    public function createRepository(ContainerInterface $container): RepositoryInterface
    {
        if ($this->repository) {
            return $this->repository;
        }
        
        throw new \RuntimeException('Not implemented for testing.');
    }

    public function mapEntity(mixed $raw, ContainerInterface $container): RestoreEntityInterface
    {
        if ($raw instanceof RestoreEntityInterface) {
            return $raw;
        }
        
        throw new \RuntimeException('Not implemented for testing.');
    }
}