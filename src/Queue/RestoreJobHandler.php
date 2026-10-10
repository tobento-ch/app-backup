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
 
namespace Tobento\App\Backup\Queue;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Tobento\App\Backup\Hook;
use Tobento\App\Backup\Hook\HookInterface;
use Tobento\App\Backup\Hook\HooksInterface;
use Tobento\App\Backup\Process\RestoreProcessFactoryInterface;
use Tobento\App\Backup\Repo\RestoreRepositoryInterface;
use Tobento\Service\Queue\JobHandlerInterface;
use Tobento\Service\Queue\JobInterface;
use Tobento\Service\Queue\Parameter;
use Tobento\Service\Queue\QueueInterface;

/**
 * Handles execution of a restore job by orchestrating the restore process
 * in incremental, resumable steps.
 *
 * The handler:
 * - Loads the restore entity and rebuilds the manifest
 * - Reconstructs all restore actions from the manifest
 * - Applies validated user restore‑time input to each action
 * - Initializes or resumes the restore process state
 * - Executes one step of the next unfinished restore action
 * - Persists progress in job parameters for continuation
 * - Requeues itself until all restore actions are completed
 * - Triggers registered restore hooks during the process lifecycle
 *
 * This enables long‑running or multi‑step restore operations to be executed
 * safely, incrementally, and reliably through the job queue system, without
 * blocking the request/response cycle.
 */
class RestoreJobHandler implements JobHandlerInterface
{
    /**
     * Create a new instance.
     *
     * @param RestoreRepositoryInterface $restoreRepository
     * @param RestoreProcessFactoryInterface $processFactory
     * @param HooksInterface $hooks
     * @param QueueInterface $queue
     * @param ContainerInterface $container
     */
    public function __construct(
        protected RestoreRepositoryInterface $restoreRepository,
        protected RestoreProcessFactoryInterface $processFactory,
        protected HooksInterface $hooks,
        protected QueueInterface $queue,
        protected ContainerInterface $container,
    ) {}

    public function handleJob(JobInterface $job): void
    {
        $restoreId = $job->getPayload()['restore_id'] ?? 0;
        $restoreEntity = $this->restoreRepository->findById($restoreId);

        if ($restoreEntity === null) {
            throw new RuntimeException(sprintf(
                'Restore with the Id "%s" not found.',
                $restoreId
            ));
        }

        // Retrieve process id from data if exists
        $data = $job->parameters()->get(Parameter\Data::class)
            ?? new Parameter\Data(['process_id' => null]);

        $processId = $data->get('process_id');
        
        // Create process
        $process = $this->processFactory->createProcess(
            restoreEntity: $restoreEntity,
            processId: $processId,
            userId: $job->getPayload()['user_id'] ?? null,
            input: $job->getPayload()['input'] ?? [],
        );
        
        // Store process id
        $data->set('process_id', $process->id());
        $job->parameters()->add($data);

        // Filter hooks
        $hooks = $this->hooks->only($process->hooks());

        // Create hook handlers
        $hookHandlers = [];
        $hooks = array_merge(
            $this->mandatoryHooks(),
            $hooks->all(),
        );
        
        foreach ($hooks as $hook) {
            $hookHandler = $hook->createHookHandler(container: $this->container);

            if (is_null($processId)) {
                $hookHandler->processStarted($process);
            }

            $hookHandlers[] = $hookHandler;
        }
        
        // Execute actions incrementally
        foreach ($process->actions() as $action) {

            if ($process->isActionFinished($action)) {
                continue;
            }
            
            try {
                // Execute one step
                foreach ($hookHandlers as $handler) {
                    $handler->actionStepExecuting($action, $process);
                }
                
                $action->restore($process, $this->container);

                foreach ($hookHandlers as $handler) {
                    $handler->actionStepExecuted($action, $process);
                }

                // Not finished → requeue
                if (! $process->isActionFinished($action)) {
                    $this->queue->push($job);
                    return;
                }

                // Finished → notify hooks
                foreach ($hookHandlers as $handler) {
                    $handler->actionFinished($action, $process);
                }

                // Requeue for next action
                $this->queue->push($job);

                return;
            } catch (\Throwable $e) {
                // Failed process
                foreach ($hookHandlers as $handler) {
                    $handler->processFailed($process, $e);
                }

                throw $e;
            }
        }
        
        // Notify hooks
        foreach ($hookHandlers as $handler) {
            $handler->processFinished($process);
        }
    }
    
    /**
     * @return array<array-key, HookInterface>
     */
    protected function mandatoryHooks(): array
    {
        return [
            new Hook\ProcessLifecycle(name: 'Process Lifecycle'),
        ];
    }
}