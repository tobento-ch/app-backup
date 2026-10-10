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
 
namespace Tobento\App\Backup\Controller;

use Psr\Http\Message\ResponseInterface;
use Tobento\App\Backup\Queue\BackupJobHandler;
use Tobento\App\Backup\Repo\BackupRepositoryInterface;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Http\Exception\NotFoundException;
use Tobento\App\User\UserInterface;
use Tobento\Service\Queue\Job;
use Tobento\Service\Queue\Parameter\DisallowQueue;
use Tobento\Service\Queue\QueueInterface;
use Tobento\Service\Queue\SyncQueue;
use Tobento\Service\Requester\RequesterInterface;
use Tobento\Service\Responser\ResponserInterface;
use Tobento\Service\Routing\RouterInterface;

class BackupAction
{
    /**
     * Create a new instance.
     *
     * @param BackupRuntimeConfigInterface $runtimeConfig
     */
    public function __construct(
        protected BackupRuntimeConfigInterface $runtimeConfig,
    ) {}
    
    /**
     * Backup action.
     *
     * @param int|string $id The backup id to backup.
     * @param RequesterInterface $requester
     * @param BackupRepositoryInterface $backupRepository
     * @param RouterInterface $router
     * @param ResponserInterface $responser
     * @return ResponseInterface
     */
    public function __invoke(
        int|string $id,
        RequesterInterface $requester,
        BackupRepositoryInterface $backupRepository,
        QueueInterface $queue,
        RouterInterface $router,
        ResponserInterface $responser,
    ): ResponseInterface {
        if (is_null($backupEntity = $backupRepository->findById($id))) {
            throw new NotFoundException();
        }
        
        $user = $requester->request()->getAttribute(UserInterface::class);

        // Queue backup job
        $this->pushToQueue(
            queue: $queue,
            payload: [
                'user_id' => $user instanceof UserInterface ? $user->id() : null,
                'backup_id' => $backupEntity->id(),
            ],
        );
        
        $responser->messages()->add(
            level: 'success',
            message: 'Backup has started and will continue in the background.',
        );
        
        return $responser->redirect($router->url('backups.index'));
    }
    
    /**
     * Pushes a collect job to the queue.
     *
     * @param QueueInterface $queue
     * @param array<string, mixed> $payload
     * @return void
     */
    protected function pushToQueue(
        QueueInterface $queue,
        array $payload,
    ): void {
        $job = new Job(
            name: BackupJobHandler::class,
            payload: $payload,
        );

        if ($this->runtimeConfig->queueName()) {
            $job->queue($this->runtimeConfig->queueName());
        }
        
        $job->duration(seconds: $this->runtimeConfig->jobTimeBudgetInSeconds());
        
        $job->retry(3);

        // Lower priority so it doesn't block important jobs
        $job->priority(-50);

        $job->parameter(new DisallowQueue(
            names: ['sync'],
            classNames: [SyncQueue::class],
            reason: 'Backups cannot run on the sync queue.'
        ));

        $queue->push($job);
    }
}