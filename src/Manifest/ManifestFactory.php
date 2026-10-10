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

namespace Tobento\App\Backup\Manifest;

use JsonException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Tobento\App\Backup\Exception\ManifestException;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\Service\Uri\BaseUriInterface;

class ManifestFactory implements ManifestFactoryInterface
{
    /**
     * Create a new instance
     *
     * @param ContainerInterface $container
     */
    public function __construct(
        protected ContainerInterface $container,
    ) {}
    
    /**
     * Creates a manifest from a backup process.
     *
     * @param BackupProcessInterface $process
     * @return ManifestInterface
     * @throws ManifestException
     */
    public function createManifestFromBackupProcess(
        BackupProcessInterface $process
    ): ManifestInterface {

        try {
            return new Manifest(
                processId: $process->id(),
                createdAt: $this->container->get(ClockInterface::class)->now(),
                actions: $process->actions()->jsonSerialize(),
                name: $process->name(),
                userId: $process->userId() ?? null,
                domain: $this->container->get(BaseUriInterface::class)->getHost(),
                meta: $process->meta(),
            );
        } catch (\Throwable $e) {
            throw new ManifestException(
                message: 'Failed creating manifest from backup process: '.$e->getMessage(),
                code: (int)$e->getCode(),
                previous: $e,
            );
        }
    }
    
    /**
     * Creates a manifest from a restore entity.
     *
     * @param RestoreEntityInterface $entity
     * @return ManifestInterface
     * @throws ManifestException
     */
    public function createManifestFromRestoreEntity(RestoreEntityInterface $entity): ManifestInterface
    {
        // Create a temporary process entity ONLY for artifact access
        $processEntity = new ProcessEntity([
            'artifact_source' => $entity->raw(),
        ]);

        try {
            // Create readable artifact using the process entity
            $artifact = $entity->registry()->createReadableArtifact(
                container: $this->container,
                entity: $processEntity,
            );
            
            // Retrieve the manifest file object
            $file = $artifact->file('manifest.json');
            
            // Read JSON from the file stream
            $json = $file->stream()->getContents();
        } catch (\Throwable $e) {
            throw new ManifestException(
                message: sprintf('Unable to read manifest.json from artifact: %s', $e->getMessage()),
                code: (int)$e->getCode(),
                previous: $e,
            );
        }
        
        // Parse manifest
        return $this->createManifestFromJsonString($json);
    }

    /**
     * Creates a manifest from an array.
     *
     * @param array<string, mixed> $data
     * @return ManifestInterface
     * @throws ManifestException
     */
    public function createManifestFromArray(array $data): ManifestInterface
    {
        try {
            return new Manifest(
                processId: $data['process_id'] ?? null,
                createdAt: new \DateTimeImmutable($data['created_at'] ?? 'now'),
                actions: $data['actions'] ?? [],
                name: $data['name'] ?? null,
                userId: $data['user_id'] ?? null,
                domain: $data['domain'] ?? null,
                meta: $data['meta'] ?? [],
            );
        } catch (\Throwable $e) {
            throw new ManifestException(
                message: 'Failed creating manifest from array: '.$e->getMessage(),
                code: (int)$e->getCode(),
                previous: $e,
            );
        }
    }

    /**
     * Creates a manifest from a JSON string.
     *
     * @param string $json
     * @return ManifestInterface
     * @throws ManifestException
     */
    public function createManifestFromJsonString(string $json): ManifestInterface
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ManifestException(
                message: 'Invalid JSON for manifest: '.$e->getMessage(),
                code: $e->getCode(),
                previous: $e,
            );
        }

        if (!is_array($data)) {
            throw new ManifestException('Manifest JSON must decode to an array');
        }

        return $this->createManifestFromArray($data);
    }
}