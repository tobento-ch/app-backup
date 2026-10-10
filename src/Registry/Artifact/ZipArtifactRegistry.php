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
use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Artifact\ReadableZipArtifact;
use Tobento\App\Backup\Artifact\WritableZipArtifact;
use Tobento\App\Backup\Exception\ArtifactCreateException;
use Tobento\App\Backup\Manifest\ManifestFactoryInterface;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\ProcessEntityInterface;
use Tobento\App\Backup\Repo\RestoreEntity;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\FileStorage\FilesystemStorageFactory;
use Tobento\Service\FileStorage\FileInterface;
use Tobento\Service\FileStorage\Repository\FileRepository;
use Tobento\Service\Filesystem\Dir;
use Tobento\Service\Repository\NullRepository;
use Tobento\Service\Repository\RepositoryInterface;
use function Tobento\App\Translation\trans;

class ZipArtifactRegistry implements WritableArtifactRegistryInterface, ReadableArtifactRegistryInterface
{
    /**
     * Create a new instance.
     *
     * @param string $id A unique identifier.
     * @param string $name Registry display name.
     * @param string $location
     * @param int $throughputInBytes Estimated write throughput in bytes per second.
     *    Used to determine whether a file can fit into the time window.     
     */
    final public function __construct(
        protected string $id,
        protected string $name,
        protected string $location,
        protected int $throughputInBytes = 50_000_000,
    ) {
        $this->location = rtrim($location, '/').'/';
    }
    
    /**
     * Unique identifier.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->id;
    }
    
    /**
     * Returns the registry name.
     *
     * @return string
     */
    public function name(): string
    {
        return trans($this->name);
    }
    
    /**
     * Returns the location.
     *
     * @return string
     */
    public function location(): string
    {
        return $this->location;
    }

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
    ): WritableArtifactInterface {
        $dir = new Dir();

        if (! $dir->has($this->location)) {
            $dir->create(directory: $this->location, mode: 0755, recursive: true);
        }

        $source = $entity->artifactSource();

        // If the artifact source is a string, treat it as the filename.
        $filename = is_string($source)
            ? $this->toFilename($source, $entity->id())
            : sprintf('backup-%d.zip', $entity->id());

        return new WritableZipArtifact(
            manifestFactory: $container->get(ManifestFactoryInterface::class),
            zipPath: sprintf('%s%s', $this->location, $filename),
            id: $filename,
            throughputInBytes: $this->throughputInBytes,
        );
    }
    
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
    ): ReadableArtifactInterface {
        $dir = new Dir();
        
        if (! $dir->has($this->location)) {
            $dir->create(directory: $this->location, mode: 0755, recursive: true);
        }
        
        $raw = $entity->artifactSource();

        if ($raw instanceof FileInterface) {
            return new ReadableZipArtifact(
                streamFactory: $container->get(StreamFactoryInterface::class),
                zipPath: sprintf('%s%s', $this->location, $raw->path()),
                id: $raw->path(),
                throughputInBytes: $this->throughputInBytes,
            );
        }
        
        throw new ArtifactCreateException('Invalid artifact source for restore process');
    }
    
    /**
     * Create the underlying repository used to list restore candidates.
     *
     * @param ContainerInterface $container
     * @return RepositoryInterface
     */
    public function createRepository(ContainerInterface $container): RepositoryInterface
    {
        $storageFactory = $container->get(FilesystemStorageFactory::class);
        $storage = $storageFactory->createStorage(name: 'backup', config: [
            'location' => $this->location,
            'storage_type' => 'private',
        ]);
        
        $repository = new FileRepository(storage: $storage);
        
        $repository = $repository->withAttributeAliases([
            'id' => 'path',
            'created_at' => 'lastModified',
        ]);
        
        $repository = $repository->withRecursive(false);
        
        return $repository;
    }

    /**
     * Map a raw repository item to a restore entity.
     *
     * @param mixed $raw
     * @param ContainerInterface $container
     * @return RestoreEntityInterface
     */
    public function mapEntity(mixed $raw, ContainerInterface $container): RestoreEntityInterface
    {
        $timestamp = $raw->lastModified();
        $name = $raw->filename();
        $userId = null;
        $domain = null;
        
        try {
            $artifact = $this->createReadableArtifact(
                container: $container,
                entity: new ProcessEntity([
                    'artifact_source' => $raw,
                ]),
            );
            
            // Retrieve the manifest file object
            $file = $artifact->file('manifest.json');
            
            // Read JSON from the file stream
            $json = $file->stream()->getContents();
            $data = json_decode($json, true);
            $name = $data['name'] ?? $name;
            $userId = $data['user_id'] ?? null;
            $domain = $data['domain'] ?? null;
        } catch (\Throwable $e) {
            // skip
        }
        
        return new RestoreEntity(
            id: $raw->path(),
            name: $name,
            type: 'Zip',
            raw: $raw,
            registry: $this,
            userId: $userId,
            domain: $domain,
            createdAt: $timestamp ? new \DateTimeImmutable('@'.$timestamp) : null,
        );
    }
    
    private function toFilename(string $name, int $id): string
    {
        // Trim whitespace
        $name = trim($name);

        // Remove illegal characters (keep letters, numbers, dot, dash, underscore)
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name);

        // Remove .zip if present (case-insensitive)
        $name = preg_replace('/\.zip$/i', '', $name);

        // Ensure no trailing separators
        $name = trim($name, "-_ ");

        // Append process ID to avoid collisions
        $name .= '-' . $id;

        // Re-add .zip extension
        $name .= '.zip';

        return $name;
    }
}