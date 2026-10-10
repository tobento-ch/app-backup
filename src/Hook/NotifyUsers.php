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
 
namespace Tobento\App\Backup\Hook;

use Psr\Container\ContainerInterface;
use Throwable;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Process\ProcessInterface;
use Tobento\App\User\UserRepositoryInterface;
use Tobento\Service\Notifier\Message;
use Tobento\Service\Notifier\Notification;
use Tobento\Service\Notifier\NotifierInterface;
use Tobento\Service\Notifier\Parameter\Queue;
use Tobento\Service\Notifier\UserRecipient;
use function Tobento\App\Translation\trans;

final class NotifyUsers implements HookInterface
{
    /**
     * Create a new instance.
     *
     * @param string $id A unique identifier.
     * @param string $name The display name of the hook.
     * @param array<int, string> $roles E.g. ['administrator']
     * @param array<int, string> $channels E.g. ['mail', 'storage']
     * @param string $notificationSubject
     * @param int $limit Max number of users to notify
     * @param null|string $queueName
     * @param array<int, string> $notifyOn
     *     A list of process lifecycle events that should trigger a notification.
     *     Supported events include:
     *     - 'started'
     *     - 'stepExecuting'
     *     - 'stepExecuted'
     *     - 'actionFinished'
     *     - 'finished'
     *     - 'failed'
     * @param string $group The group of the hook.
     * @param bool $defaultSelected Whether the hook should be selected by default in the UI.
     */
    public function __construct(
        protected string $id,
        private string $name,
        private array $roles,
        private array $channels = ['storage'],
        private string $notificationSubject = 'Job :name :event',
        private int $limit = 20,
        private null|string $queueName = null,
        private array $notifyOn = ['started', 'finished', 'failed'],
        private string $group = 'Notify',
        private bool $defaultSelected = false,
    ) {}
    
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
     * Returns the hook name.
     *
     * @return string
     */
    public function name(): string
    {
        return trans($this->name);
    }
    
    /**
     * Returns the hook group.
     *
     * @return string
     */
    public function group(): string
    {
        return trans($this->group);
    }
    
    /**
     * Whether the hook should be selected by default in the UI.
     *
     * @return bool
     */
    public function defaultSelected(): bool
    {
        return $this->defaultSelected;
    }
    
    /**
     * Whether this hook applies to backup processes.
     *
     * @return bool
     */
    public function supportsBackup(): bool
    {
        return true;
    }

    /**
     * Whether this hook applies to restore processes.
     *
     * @return bool
     */
    public function supportsRestore(): bool
    {
        return true;
    }

    /**
     * Create hook handler.
     *
     * @param ContainerInterface $container
     * @return HookHandlerInterface
     */
    public function createHookHandler(ContainerInterface $container): HookHandlerInterface
    {
        $userRepository = $container->get(UserRepositoryInterface::class);
        $notifier = $container->get(NotifierInterface::class);

        return new class(
            $userRepository,
            $notifier,
            $this->roles,
            $this->channels,
            $this->notifyOn,
            $this->notificationSubject,
            $this->limit,
            $this->queueName,
        ) extends AbstractHookHandler {

            public function __construct(
                private UserRepositoryInterface $userRepository,
                private NotifierInterface $notifier,
                private array $roles,
                private array $channels,
                private array $notifyOn,
                private string $notificationSubject,
                private int $limit,
                private null|string $queueName,
            ) {}

            public function processStarted(ProcessInterface $process): void
            {
                $this->notifyIf('started', $process);
            }

            public function processFinished(ProcessInterface $process): void
            {
                $this->notifyIf('finished', $process);
            }

            public function processFailed(ProcessInterface $process, Throwable $exception): void
            {
                $this->notifyIf('failed', $process);
            }

            public function actionStepExecuting(ActionInterface $action, ProcessInterface $process): void
            {
                $this->notifyIf('stepExecuting', $process, $action);
            }

            public function actionStepExecuted(ActionInterface $action, ProcessInterface $process): void
            {
                $this->notifyIf('stepExecuted', $process, $action);
            }

            public function actionFinished(ActionInterface $action, ProcessInterface $process): void
            {
                $this->notifyIf('actionFinished', $process, $action);
            }

            private function notifyIf(string $event, ProcessInterface $process, null|ActionInterface $action = null): void
            {
                if (!in_array($event, $this->notifyOn, true)) {
                    return;
                }

                $recipients = $this->createRecipients();

                if (empty($recipients)) {
                    return;
                }

                $notification = $this->createNotification($event, $process, $action);

                foreach ($recipients as $recipient) {
                    $this->notifier->send($notification, $recipient);
                }
            }

            private function createRecipients(): array
            {
                $users = $this->userRepository->findAll(
                    where: [
                        'role_key' => ['in' => $this->roles],
                    ],
                    limit: $this->limit,
                );

                $recipients = [];

                foreach ($users as $user) {
                    $recipients[] = new UserRecipient(user: $user);
                }

                return $recipients;
            }

            private function createNotification(string $event, ProcessInterface $process, null|ActionInterface $action): Notification
            {
                $subject = trim(strtr($this->notificationSubject, [
                    ':event' => $event,
                    ':id' => (string)$process->id(),
                    ':name' => $process->name() ?? '',
                    ':action' => (string)$action?->id(),
                ]));

                $lines = [
                    'Process ID: '.$process->id(),
                    'Event: '.$event,
                ];

                if ($process->name() !== null) {
                    $lines[] = 'Process Name: '.$process->name();
                }

                if ($action !== null) {
                    $lines[] = 'Action ID: '.$action->id();
                }

                $content = implode("\n\n", $lines);

                $browserStatus = match ($event) {
                    'started', 'stepExecuting', 'stepExecuted' => 'info',
                    'actionFinished', 'finished' => 'success',
                    'failed' => 'error',
                    default => 'info',
                };

                $notification = new Notification(
                    subject: $subject,
                    content: $content,
                    channels: $this->channels,
                );

                $notification->addMessage('browser', new Message\Browser([
                    'title' => $subject,
                    'message' => $content,
                    'status' => $browserStatus,
                    'autotimeout' => 10000,
                ]));

                if ($this->queueName !== null) {
                    $notification->parameter(new Queue(name: $this->queueName));
                }

                return $notification;
            }
        };
    }
}