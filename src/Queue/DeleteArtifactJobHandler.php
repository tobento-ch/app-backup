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
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Backup\Repo\RestoreRepositoryInterface;
use Tobento\App\User\UserRepositoryInterface;
use Tobento\Service\Notifier\Message;
use Tobento\Service\Notifier\Notification;
use Tobento\Service\Notifier\NotifierInterface;
use Tobento\Service\Notifier\Parameter\Queue;
use Tobento\Service\Notifier\UserRecipient;
use Tobento\Service\Queue\JobHandlerInterface;
use Tobento\Service\Queue\JobInterface;
use Tobento\Service\Queue\QueueInterface;
use function Tobento\App\Translation\trans;

class DeleteArtifactJobHandler implements JobHandlerInterface
{
    public function __construct(
        protected RestoreRepositoryInterface $restoreRepository,
        protected ContainerInterface $container,
        protected QueueInterface $queue,
        protected null|string $queueName = null,
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

        // Create deletion process (always fresh)
        $process = $restoreEntity->registry()->createDeleteProcess(
            restoreEntity: $restoreEntity,
            container: $this->container,
        );

        // Time budget
        $timeBudget = $this->container
            ->get(BackupRuntimeConfigInterface::class)
            ->jobTimeBudgetInSeconds();

        $start = microtime(true);

        // Deletion loop
        while ($process->hasRemainingItems()) {

            $item = $process->nextItem();
            $process->deleteItem($item);

            if ((microtime(true) - $start) >= $timeBudget) {
                $this->queue->push($job);
                return;
            }
        }

        $this->handleFinishedJob($job);
    }
    
    protected function handleFinishedJob(JobInterface $job): void
    {
        $userId = $job->getPayload()['user_id'] ?? null;

        if ($userId === null) {
            return;
        }

        $userRepository = $this->container->get(UserRepositoryInterface::class);
        $notifier = $this->container->get(NotifierInterface::class);

        $user = $userRepository->findById($userId);

        if ($user === null) {
            return;
        }

        $recipient = new UserRecipient($user);

        $subject = trans('Backup deletion completed.');

        $notification = new Notification(
            subject: $subject,
            channels: ['browser'],
        )
            ->addMessage('browser', new Message\Browser([
                'title' => $subject,
                'status' => 'success',
                'autotimeout' => 10000,
            ]));

        if ($this->queueName) {
            $notification->parameter(new Queue(name: $this->queueName));
        }

        $notifier->send($notification, $recipient);
    }
}