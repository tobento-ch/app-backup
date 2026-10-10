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

use Psr\Container\ContainerInterface;
use Tobento\App\Backup\Action\Actions;
use Tobento\App\Backup\Action\ActionsFactoryInterface;
use Tobento\App\Backup\Artifact\ReadableArtifactFactoryInterface;
use Tobento\App\Backup\Manifest\ManifestFactoryInterface;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\Crud\Input\Input;

class RestoreProcessFactory implements RestoreProcessFactoryInterface
{
    public function __construct(
        protected ProcessRepositoryInterface $processRepository,
        protected ActionsFactoryInterface $actionsFactory,
        protected ReadableArtifactFactoryInterface $artifactFactory,
        protected ManifestFactoryInterface $manifestFactory,
        protected ContainerInterface $container,
    ) {}

    public function createProcess(
        RestoreEntityInterface $restoreEntity,
        null|int $processId = null,
        null|int|string $userId = null,
        array $input = [],
    ): RestoreProcessInterface {
        
        // Load existing process
        if (!is_null($processId)) {
            $processEntity = $this->processRepository->findById($processId);

            if (!is_null($processEntity)) {
                // Set raw artifact source
                $processEntity = $processEntity->withArtifactSource($restoreEntity->raw());
                
                // Create readable artifact from existing process entity
                $artifact = $this->artifactFactory->createArtifact($restoreEntity, $processEntity);

                return new RestoreProcess(
                    processId: $processEntity->id(),
                    actions: $this->actionsFactory->createActionsFromArray(
                        $processEntity->actions()
                    ),
                    processedActions: $processEntity->processedActions(),
                    actionStates: $processEntity->actionStates(),
                    hooks: $processEntity->hooks(),
                    artifact: $artifact,
                    processRepository: $this->processRepository,
                    name: $processEntity->name(),
                    userId: $processEntity->userId(),
                    meta: $processEntity->meta(),
                );
            }
        }

        // Create new restore process

        // Build manifest from restore entity
        $manifest = $this->manifestFactory->createManifestFromRestoreEntity($restoreEntity);
        
        // Build restore actions from manifest
        $actions = $this->actionsFactory->createActionsFromArray($manifest->actions());
        
        $newActions = [];

        // Apply validated restore input to each action
        $inputObj = new Input($input);
        foreach ($actions as $action) {
            $newActions[] = $action->configureForRestore($inputObj);
        }
        
        $actions = new Actions(...$newActions);
        
        // Persist process entity with configured restore actions
        $processEntity = $this->processRepository->create([
            'type' => 'restore',
            'type_id' => $restoreEntity->id(),
            'actions' => $actions->jsonSerialize(),
            'processed_actions' => [],
            'action_states' => [],
            'hooks' => $inputObj->get('hooks', []),
            'status' => 'pending',
            'name' => $restoreEntity->name(),
            'user_id' => $userId,
            'meta' => $manifest->meta(),
        ]);
        
        // Set raw artifact source
        $processEntity = $processEntity->withArtifactSource($restoreEntity->raw());

        // Create readable artifact from new process entity
        $artifact = $this->artifactFactory->createArtifact($restoreEntity, $processEntity);

        return new RestoreProcess(
            processId: $processEntity->id(),
            actions: $actions,
            processedActions: [],
            actionStates: [],
            hooks: $inputObj->get('hooks', []),
            artifact: $artifact,
            processRepository: $this->processRepository,
            name: $restoreEntity->name(),
            userId: $userId,
            meta: $manifest->meta(),
        );
    }
}