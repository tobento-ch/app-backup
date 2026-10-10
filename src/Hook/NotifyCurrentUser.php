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

final class NotifyCurrentUser implements HookInterface
{
    /**
     * Create a new instance.
     *
     * @param string $name The display name of the hook.
     * @param array<array-key, string> $channels E.g. ['mail', 'storage']
     * @param string $notificationSubject
     * @param null|string $queueName
     * @param array<int, string> $notifyOn
     *     A list of lifecycle events that should trigger a notification.
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
        private string $name,
        private array $channels,
        private string $notificationSubject = 'Job :name :event',
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
        return 'notify.current.user';
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
            $this->channels,
            $this->notifyOn,
            $this->notificationSubject,
            $this->queueName,
        ) extends AbstractHookHandler {
            public function __construct(
                private UserRepositoryInterface $userRepository,
                private NotifierInterface $notifier,
                private array $channels,
                private array $notifyOn,
                private string $notificationSubject,
                private null|string $queueName,
            ) {}

            public function processStarted(ProcessInterface $process): void
            {
                $this->notify('started', $process);
            }

            public function processFinished(ProcessInterface $process): void
            {
                $this->notify('finished', $process);
            }

            public function processFailed(ProcessInterface $process, Throwable $exception): void
            {
                $this->notify('failed', $process);
            }

            public function actionStepExecuting(ActionInterface $action, ProcessInterface $process): void
            {
                $this->notify('stepExecuting', $process, $action);
            }

            public function actionStepExecuted(ActionInterface $action, ProcessInterface $process): void
            {
                $this->notify('stepExecuted', $process, $action);
            }

            public function actionFinished(ActionInterface $action, ProcessInterface $process): void
            {
                $this->notify('actionFinished', $process, $action);
            }

            private function notify(string $event, ProcessInterface $process, null|ActionInterface $action = null): void
            {
                if (!in_array($event, $this->notifyOn, true)) {
                    return;
                }

                if (is_null($recipient = $this->createRecipient($process))) {
                    return;
                }

                $notification = $this->createNotification($event, $process, $action);
                $this->notifier->send($notification, $recipient);
            }
            
            private function createRecipient(ProcessInterface $process): null|UserRecipient
            {
                if (is_null($userId = $process->userId())) {
                    return null;
                }
                
                $user = $this->userRepository->findById($userId);
                
                if (is_null($user)) {
                    return null;
                }
                
                return new UserRecipient(user: $user);
            }
            
            private function createNotification(
                string $event,
                ProcessInterface $process,
                null|ActionInterface $action
            ): Notification {
                $subject = trim(strtr($this->notificationSubject, [
                    ':event'  => $event,
                    ':id' => (string)$process->id(),
                    ':name' => $process->name() ?? '',
                    ':action' => (string)$action?->id(),
                ]));

                $lines = [
                    sprintf('Process ID: %s', $process->id()),
                    sprintf('Event: %s', $event),
                ];

                if ($process->name() !== null) {
                    $lines[] = sprintf('Process Name: %s', $process->name());
                }

                if ($action !== null) {
                    $lines[] = sprintf('Action ID: %s', $action->id());
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
                )
                    ->addMessage('browser', new Message\Browser([
                        'title' => $subject,
                        'message' => $content,
                        'status' => $browserStatus,
                        'autotimeout' => 10000,
                    ]));

                if ($this->queueName) {
                    $notification->parameter(new Queue(name: $this->queueName));
                }

                return $notification;
            }
        };
    }
}