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

namespace Tobento\App\Backup\Process;

use Tobento\App\Backup\Artifact\WritableArtifactFactoryInterface;
use Tobento\App\Backup\Action\ActionsFactoryInterface;
use Tobento\App\Backup\Repo\BackupEntityInterface;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;

class BackupProcessFactory implements BackupProcessFactoryInterface
{
    public function __construct(
        protected ProcessRepositoryInterface $processRepository,
        protected ActionsFactoryInterface $actionsFactory,
        protected WritableArtifactFactoryInterface $artifactFactory,
    ) {}

    public function createProcess(
        BackupEntityInterface $backupEntity,
        null|int $processId = null,
        null|int|string $userId = null,
    ): BackupProcessInterface {
        // Load existing process
        if (!is_null($processId)) {
            $processEntity = $this->processRepository->findById($processId);

            if (!is_null($processEntity)) {
                // Set artifact source
                $processEntity = $processEntity->withArtifactSource($backupEntity->name());
                
                return new BackupProcess(
                    processId: $processEntity->id(),
                    actions: $this->actionsFactory->createActionsFromArray(
                        $processEntity->actions()
                    ),
                    processedActions: $processEntity->processedActions(),
                    actionStates: $processEntity->actionStates(),
                    hooks: $processEntity->hooks(),
                    artifact: $this->artifactFactory->createArtifactFromProcessEntity(
                        processEntity: $processEntity,
                    ),
                    processRepository: $this->processRepository,
                    name: $processEntity->name(),
                    userId: $processEntity->userId(),
                    meta: $processEntity->meta(),
                );
            }
        }

        // Otherwise, create new process
        //
        // Build actions from backup definition
        $actions = $this->actionsFactory->createActionsFromBackupEntity($backupEntity);

        // Persist entity with immutable action definitions
        $processEntity = $this->processRepository->create([
            'type' => 'backup',
            'type_id' => $backupEntity->id(),
            'actions' => $actions->jsonSerialize(),
            'processed_actions' => [],
            'action_states' => [],
            'artifacts' => $backupEntity->artifacts(),
            'hooks' => $backupEntity->hooks(),
            'status' => 'pending',
            'name' => $backupEntity->name(),
            'user_id' => $userId,
            'meta' => $backupEntity->meta(),
        ]);

        // Set artifact source
        $processEntity = $processEntity->withArtifactSource($backupEntity->name());

        return new BackupProcess(
            processId: $processEntity->id(),
            actions: $actions,
            processedActions: [],
            actionStates: [],
            hooks: $backupEntity->hooks(),
            artifact: $this->artifactFactory->createArtifactFromProcessEntity(
                processEntity: $processEntity,
            ),
            processRepository: $this->processRepository,
            name: $backupEntity->name(),
            userId: $userId,
            meta: $backupEntity->meta(),
        );
    }
}