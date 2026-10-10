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
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Artifact\ReadableFileStorageArtifact;
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Artifact\WritableFileStorageArtifact;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Backup\Exception\ArtifactCreateException;
use Tobento\App\Backup\Manifest\ManifestFactoryInterface;
use Tobento\App\Backup\Process\DeleteProcessInterface;
use Tobento\App\Backup\Process\FileStorageDeleteProcess;
use Tobento\App\Backup\Process\NullDeleteProcess;
use Tobento\App\Backup\Queue\DeleteArtifactJobHandler;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\ProcessEntityInterface;
use Tobento\App\Backup\Repo\RestoreEntity;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\User\UserInterface;
use Tobento\Service\FileStorage\FolderInterface;
use Tobento\Service\FileStorage\Repository\FolderRepository;
use Tobento\Service\FileStorage\StoragesInterface;
use Tobento\Service\Queue\Job;
use Tobento\Service\Queue\Parameter\DisallowQueue;
use Tobento\Service\Queue\QueueInterface;
use Tobento\Service\Queue\SyncQueue;
use Tobento\Service\Repository\NullRepository;
use Tobento\Service\Repository\RepositoryInterface;
use function Tobento\App\Translation\trans;

class FileStorageArtifactRegistry implements
    WritableArtifactRegistryInterface,
    ReadableArtifactRegistryInterface,
    QueueDeletionRegistryInterface
{
    /**
     * Create a new instance.
     *
     * @param string $id A unique identifier.
     * @param string $name Registry display name.
     * @param string $storageName
     * @param null|string $folder
     * @param int $throughputInBytes Estimated write throughput in bytes per second.
     *    Used to determine whether a file can fit into the time window.
     */
    final public function __construct(
        protected string $id,
        protected string $name,
        protected string $storageName,
        protected null|string $folder = null,
        protected int $throughputInBytes = 50_000_000,
    ) {
        $this->folder = $folder ? rtrim($folder, '/') . '/' : null;
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
     * Returns the storage name.
     *
     * @return string
     */
    public function storageName(): string
    {
        return $this->storageName;
    }
    
    /**
     * Returns the folder.
     *
     * @return null|string
     */
    public function folder(): null|string
    {
        return $this->folder;
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
        $storages = $container->get(StoragesInterface::class);
        
        if (! $storages->has($this->storageName())) {
            throw new ArtifactCreateException(sprintf('File storage %s not found', $this->storageName()));
        }
        
        $storage = $storages->get($this->storageName());
        
        $source = $entity->artifactSource();

        // If the artifact source is a string, treat it as the foldername.
        $folderName = is_string($source)
            ? $this->toFoldername($source, $entity->id())
            : sprintf('backup-%d', $entity->id());

        $folder = $this->folder()
            ? $this->folder() . $folderName
            : $folderName;
        
        return new WritableFileStorageArtifact(
            streamFactory: $container->get(StreamFactoryInterface::class),
            manifestFactory: $container->get(ManifestFactoryInterface::class),
            storage: $storage,
            folder: $folder,
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

        $storages = $container->get(StoragesInterface::class);
        
        if (! $storages->has($this->storageName())) {
            throw new ArtifactCreateException(sprintf('File storage %s not found', $this->storageName()));
        }
        
        $storage = $storages->get($this->storageName());
        $raw = $entity->artifactSource();

        if (! $raw instanceof FolderInterface) {
            throw new ArtifactCreateException('Invalid artifact source for restore process');
        }

        return new ReadableFileStorageArtifact(
            storage: $storage,
            folder: $raw->path(),
            throughputInBytes: $this->throughputInBytes,
        );
    }
    
    /**
     * Create the underlying repository used to list restore candidates.
     *
     * @param ContainerInterface $container
     * @return RepositoryInterface
     */
    public function createRepository(ContainerInterface $container): RepositoryInterface
    {
        $storages = $container->get(StoragesInterface::class);
        
        if (! $storages->has($this->storageName())) {
            return new NullRepository();
        }
        
        $storage = $storages->get($this->storageName());
        
        $repository = new FolderRepository(storage: $storage);
        
        if ($this->folder()) {
            $repository = $repository->withRootFolder($this->folder());
        }
        
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
        $name = $raw->path();
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

        $id = $raw->path();
        
        if ($this->folder() && str_starts_with($id, $this->folder())) {
            $id = substr($id, strlen($this->folder()));
        }
        
        return new RestoreEntity(
            id: $id,
            name: $name,
            type: 'Folder',
            raw: $raw,
            registry: $this,
            userId: $userId,
            domain: $domain,
            createdAt: $timestamp ? new \DateTimeImmutable('@'.$timestamp) : null,
        );
    }
        
    /**
     * Queue deletion for the specified restore entity.
     *
     * @param string|int $id The restore entity ID whose artifact should be deleted.
     * @param ContainerInterface $container The application container.
     * @return void
     */
    public function queueDeletion(string|int $id, ContainerInterface $container): void
    {
        $queue = $container->get(QueueInterface::class);
        $runtimeConfig = $container->get(BackupRuntimeConfigInterface::class);
        $user = $container->get(ServerRequestInterface::class)->getAttribute(UserInterface::class);
        
        $job = new Job(
            name: DeleteArtifactJobHandler::class,
            payload: [
                'restore_id' => $id,
                'user_id' => $user instanceof UserInterface ? $user->id() : null,
            ],
        );

        if ($runtimeConfig->queueName()) {
            $job->queue($runtimeConfig->queueName());
        }
        
        $job->duration(seconds: $runtimeConfig->jobTimeBudgetInSeconds());
        
        $job->retry(3);
        
        $job->unique(id: sprintf('backup:delete:%s', (string)$id));

        $job->priority(-50);

        $job->parameter(new DisallowQueue(
            names: ['sync'],
            classNames: [SyncQueue::class],
            reason: 'Backups deletion cannot run on the sync queue.'
        ));

        $queue->push($job);
    }
    
    /**
     * Create a deletion process for the specified restore entity.
     *
     * @param RestoreEntityInterface $restoreEntity
     * @param ContainerInterface $container The application container.
     * @return DeleteProcessInterface The deletion process instance.
     */
    public function createDeleteProcess(
        RestoreEntityInterface $restoreEntity,
        ContainerInterface $container
    ): DeleteProcessInterface {
        $storages = $container->get(StoragesInterface::class);

        if (! $storages->has($this->storageName())) {
            return new NullDeleteProcess();
        }

        $storage = $storages->get($this->storageName());
        $folder = $restoreEntity->raw()->path();

        return new FileStorageDeleteProcess(
            storage: $storage,
            rootFolder: $folder
        );
    }
    
    private function toFoldername(string $name, int $id): string
    {
        // Trim whitespace
        $name = trim($name);

        // Remove illegal characters (keep letters, numbers, dash, underscore)
        $name = preg_replace('/[^A-Za-z0-9_-]/', '-', $name);

        // Ensure no trailing separators
        $name = trim($name, "-_ ");

        // Append process ID to avoid collisions
        $name .= '-' . $id;

        return $name;
    }
}