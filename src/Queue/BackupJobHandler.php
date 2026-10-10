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
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Repo\BackupRepositoryInterface;
use Tobento\Service\Queue\JobHandlerInterface;
use Tobento\Service\Queue\JobInterface;
use Tobento\Service\Queue\Parameter;
use Tobento\Service\Queue\QueueInterface;

/**
 * Handles execution of a backup job by orchestrating the backup process
 * in incremental steps.  
 *
 * The handler:
 * - Loads the backup entity and initializes process state
 * - Executes one step of the next unfinished backup action
 * - Persists progress in job parameters for continuation
 * - Requeues itself until all actions are completed
 * - Triggers registered backup hooks during the process lifecycle
 *
 * This allows long-running or multi-step backup operations to be executed
 * safely and resumably through the job queue system.
 */
class BackupJobHandler implements JobHandlerInterface
{
    /**
     * Create a new instance.
     *
     * @param BackupRepositoryInterface $backupRepository
     * @param BackupProcessFactoryInterface $processFactory
     * @param HooksInterface $hooks
     * @param QueueInterface $queue
     * @param ContainerInterface $container
     */
    public function __construct(
        protected BackupRepositoryInterface $backupRepository,
        protected BackupProcessFactoryInterface $processFactory,
        protected HooksInterface $hooks,
        protected QueueInterface $queue,
        protected ContainerInterface $container,
    ) {}

    public function handleJob(JobInterface $job): void
    {
        $backupId = $job->getPayload()['backup_id'] ?? 0;
        $backupEntity = $this->backupRepository->findById($backupId);
        
        if ($backupEntity === null) {
            throw new RuntimeException(sprintf(
                'Backup with the Id "%s" not found.',
                $backupId
            ));
        }

        // Retrieve process id from data if exists
        $data = $job->parameters()->get(Parameter\Data::class)
            ?? new Parameter\Data(['process_id' => null]);

        $processId = $data->get('process_id');
        
        // Create process
        $process = $this->processFactory->createProcess(
            backupEntity: $backupEntity,
            processId: $processId,
            userId: $job->getPayload()['user_id'] ?? null,
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
                
                $action->backup($process, $this->container);

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

        // All actions finished, finalize artifact
        $process->artifact()->finalize($process);
        
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