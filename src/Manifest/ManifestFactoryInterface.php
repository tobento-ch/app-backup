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

use Tobento\App\Backup\Exception\ManifestException;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Repo\RestoreEntityInterface;

interface ManifestFactoryInterface
{
    /**
     * Creates a manifest from a backup process.
     *
     * @param BackupProcessInterface $process
     * @return ManifestInterface
     * @throws ManifestException
     */
    public function createManifestFromBackupProcess(BackupProcessInterface $process): ManifestInterface;

    /**
     * Creates a manifest from a restore entity.
     *
     * @param RestoreEntityInterface $entity
     * @return ManifestInterface
     * @throws ManifestException
     */
    public function createManifestFromRestoreEntity(RestoreEntityInterface $entity): ManifestInterface;
    
    /**
     * Creates a manifest from an array.
     *
     * @param array<string, mixed> $data
     * @return ManifestInterface
     * @throws ManifestException
     */
    public function createManifestFromArray(array $data): ManifestInterface;

    /**
     * Creates a manifest from a JSON string.
     *
     * @param string $json
     * @return ManifestInterface
     * @throws ManifestException
     */
    public function createManifestFromJsonString(string $json): ManifestInterface;
}