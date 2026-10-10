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
use Tobento\Service\Notifier\Message;
use Tobento\Service\Notifier\Notification;
use Tobento\Service\Notifier\NotifierInterface;
use Tobento\Service\Notifier\Parameter\Queue;
use Tobento\Service\Notifier\RecipientInterface;
use function Tobento\App\Translation\trans;

final class Notify implements HookInterface
{
    /**
     * Create a new instance.
     *
     * @param string $id A unique identifier.
     * @param string $name The display name of the hook.
     * @param RecipientInterface $recipient
     * @param string $notificationSubject Subject template, e.g. 'Job :name :event'
     * @param null|string $queueName Optional queue name for queued notifications
     * @param array<int, string> $notifyOn
     *     A list of process lifecycle events that should trigger a notification.
     *     Supported events include:
     *     - 'started'
     *     - 'stepExecuting'
     *     - 'stepExecuted'
     *     - 'actionFinished'
     *     - 'finished'
     *     - 'failed'
     * @param string $group The hook group shown in the UI.
     * @param bool $defaultSelected Whether the hook is selected by default.
     */
    public function __construct(
        private string $id,
        private string $name,
        private RecipientInterface $recipient,
        private string $notificationSubject = 'Job :name :event',
        private ?string $queueName = null,
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
        $notifier = $container->get(NotifierInterface::class);

        return new class(
            $notifier,
            $this->recipient,
            $this->notifyOn,
            $this->notificationSubject,
            $this->queueName,
        ) extends AbstractHookHandler {

            public function __construct(
                private NotifierInterface $notifier,
                private RecipientInterface $recipient,
                private array $notifyOn,
                private string $notificationSubject,
                private ?string $queueName,
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

            private function notifyIf(
                string $event,
                ProcessInterface $process,
                ?ActionInterface $action = null
            ): void {
                if (!in_array($event, $this->notifyOn, true)) {
                    return;
                }

                $notification = $this->createNotification($event, $process, $action);

                $this->notifier->send($notification, $this->recipient);
            }

            private function createNotification(
                string $event,
                ProcessInterface $process,
                ?ActionInterface $action
            ): Notification {

                $subject = trim(strtr($this->notificationSubject, [
                    ':event' => $event,
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