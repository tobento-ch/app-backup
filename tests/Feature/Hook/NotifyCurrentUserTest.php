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

namespace Tobento\App\Backup\Test\Feature\Hook;

use Tobento\App\AppInterface;
use Tobento\App\Backup\Hook\NotifyCurrentUser;
use Tobento\App\Backup\Hook\HookInterface;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\User\UserRepositoryInterface;
use Tobento\App\Testing\TestCase;
use Tobento\App\Testing\Database\RefreshDatabases;
use Tobento\Service\Notifier\ChannelMessagesInterface;
use Tobento\Service\Notifier\Notification;
use Tobento\Service\Notifier\UserRecipient;

class NotifyCurrentUserTest extends TestCase
{
    use RefreshDatabases;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        $app->boot(\Tobento\App\User\Boot\User::class);
        return $app;
    }
    
    public function testImplementsHookInterface()
    {
        $app = $this->bootingApp();

        $hook = new NotifyCurrentUser(
            name: 'Notify',
            channels: ['mail']
        );

        $this->assertInstanceOf(HookInterface::class, $hook);
    }

    public function testIdMethod()
    {
        $app = $this->bootingApp();

        $hook = new NotifyCurrentUser('Notify', ['mail']);

        $this->assertSame('notify.current.user', $hook->id());
    }

    public function testNameMethod()
    {
        $app = $this->bootingApp();

        $hook = new NotifyCurrentUser('Notify', ['mail']);

        $this->assertSame('Notify', $hook->name());
    }

    public function testGroupMethod()
    {
        $app = $this->bootingApp();

        $this->assertSame('Notify', new NotifyCurrentUser('Notify', ['mail'])->group());
        $this->assertSame('Custom', new NotifyCurrentUser('Notify', ['mail'], group: 'Custom')->group());
    }

    public function testDefaultSelectedMethod()
    {
        $app = $this->bootingApp();

        $this->assertFalse(new NotifyCurrentUser('Notify', ['mail'])->defaultSelected());
        $this->assertTrue(new NotifyCurrentUser('Notify', ['mail'], defaultSelected: true)->defaultSelected());
    }

    public function testSupportsBackupMethod()
    {
        $app = $this->bootingApp();

        $hook = new NotifyCurrentUser('Notify', ['mail']);

        $this->assertTrue($hook->supportsBackup());
    }

    public function testSupportsRestoreMethod()
    {
        $app = $this->bootingApp();

        $hook = new NotifyCurrentUser('Notify', ['mail']);

        $this->assertTrue($hook->supportsRestore());
    }

    public function testCreateHookHandlerReturnsHookHandlerInterface()
    {
        $app = $this->bootingApp();

        $hook = new NotifyCurrentUser('Notify', ['mail']);

        $handler = $hook->createHookHandler($app->container());

        $this->assertInstanceOf(\Tobento\App\Backup\Hook\HookHandlerInterface::class, $handler);
    }

    private function createProcessWithUser(AppInterface $app, int $userId): \Tobento\App\Backup\Process\ProcessInterface
    {
        $repo = $app->get(ProcessRepositoryInterface::class);

        $entity = $repo->create([
            'type' => 'backup',
            'status' => 'pending',
            'name' => 'My Backup',
            'user_id' => $userId,
        ]);

        $backupEntity = new BackupEntity([
            'id' => 1,
            'name' => 'My Backup',
            'options' => ['artifacts' => [], 'hooks' => []],
            'meta' => [],
        ]);

        $factory = $app->get(BackupProcessFactoryInterface::class);

        return $factory->createProcess(
            backupEntity: $backupEntity,
            processId: $entity->id(),
            userId: $userId
        );
    }

    public function testProcessStartedNotifiesRecipient()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();

        // Create user
        $userRepo = $app->get(UserRepositoryInterface::class);
        $user = $userRepo->create(['email' => 'dev@example.com']);

        $process = $this->createProcessWithUser($app, $user->id());

        $hook = new NotifyCurrentUser(
            name: 'Notify',
            channels: ['mail'],
            notifyOn: ['started']
        )->createHookHandler($app->container());

        $hook->processStarted($process);

        $notifier
            ->assertSent(
                Notification::class,
                static function(ChannelMessagesInterface $messages): bool {

                    if ($messages->successful()->channelNames() !== ['mail']) {
                        return false;
                    }

                    return $messages
                        ->recipient()
                        ->getAddressForChannel('mail', $messages->notification())
                        ?->email() === 'dev@example.com';
                }
            )
            ->assertSentTimes(Notification::class, 1);
    }
    
    public function testProcessStartedSkipsIfNotInNotifyOn()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();

        $userRepo = $app->get(UserRepositoryInterface::class);
        $user = $userRepo->create(['email' => 'dev@example.com']);

        $process = $this->createProcessWithUser($app, $user->id());

        $hook = new NotifyCurrentUser(
            name: 'Notify',
            channels: ['mail'],
            notifyOn: ['finished', 'failed']
        )->createHookHandler($app->container());

        $hook->processStarted($process);

        $notifier->assertNothingSent();
    }

    public function testProcessFinishedNotifiesRecipient()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();

        $userRepo = $app->get(UserRepositoryInterface::class);
        $user = $userRepo->create(['email' => 'dev@example.com']);

        $process = $this->createProcessWithUser($app, $user->id());

        $hook = new NotifyCurrentUser(
            name: 'Notify',
            channels: ['mail'],
            notifyOn: ['finished']
        )->createHookHandler($app->container());

        $hook->processFinished($process);

        $notifier
            ->assertSent(
                Notification::class,
                static function(ChannelMessagesInterface $messages): bool {

                    if ($messages->successful()->channelNames() !== ['mail']) {
                        return false;
                    }

                    return $messages
                        ->recipient()
                        ->getAddressForChannel('mail', $messages->notification())
                        ?->email() === 'dev@example.com';
                }
            )
            ->assertSentTimes(Notification::class, 1);
    }

    public function testProcessFailedNotifiesRecipient()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();

        $userRepo = $app->get(UserRepositoryInterface::class);
        $user = $userRepo->create(['email' => 'dev@example.com']);

        $process = $this->createProcessWithUser($app, $user->id());

        $hook = new NotifyCurrentUser(
            name: 'Notify',
            channels: ['mail'],
            notifyOn: ['failed']
        )->createHookHandler($app->container());

        $hook->processFailed($process, new \RuntimeException('fail'));

        $notifier
            ->assertSent(
                Notification::class,
                static function(ChannelMessagesInterface $messages): bool {

                    if ($messages->successful()->channelNames() !== ['mail']) {
                        return false;
                    }

                    return $messages
                        ->recipient()
                        ->getAddressForChannel('mail', $messages->notification())
                        ?->email() === 'dev@example.com';
                }
            )
            ->assertSentTimes(Notification::class, 1);
    }

    public function testSkipsNotifyingIfProcessHasNoUser()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();

        // Create process with no userId
        $repo = $app->get(ProcessRepositoryInterface::class);

        $entity = $repo->create([
            'type' => 'backup',
            'status' => 'pending',
            'name' => 'My Backup',
        ]);

        $backupEntity = new BackupEntity([
            'id' => 1,
            'name' => 'My Backup',
            'options' => ['artifacts' => [], 'hooks' => []],
            'meta' => [],
        ]);

        $factory = $app->get(BackupProcessFactoryInterface::class);
        $process = $factory->createProcess($backupEntity, $entity->id(), null);

        $hook = new NotifyCurrentUser(
            name: 'Notify',
            channels: ['mail'],
            notifyOn: ['started', 'finished', 'failed']
        )->createHookHandler($app->container());

        $hook->processStarted($process);

        $notifier->assertNothingSent();
    }
}