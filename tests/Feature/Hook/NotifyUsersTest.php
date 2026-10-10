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
use Tobento\App\Backup\Hook\NotifyUsers;
use Tobento\App\Backup\Hook\HookInterface;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\User\UserRepositoryInterface;
use Tobento\App\Testing\TestCase;
use Tobento\App\Testing\Database\RefreshDatabases;
use Tobento\Service\Notifier\ChannelMessagesInterface;
use Tobento\Service\Notifier\Notification;

class NotifyUsersTest extends TestCase
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

        $hook = new NotifyUsers(
            id: 'notify.users',
            name: 'Notify Users',
            roles: ['administrator']
        );

        $this->assertInstanceOf(HookInterface::class, $hook);
    }

    public function testIdMethod()
    {
        $app = $this->bootingApp();

        $hook = new NotifyUsers('notify.users', 'Notify Users', ['administrator']);

        $this->assertSame('notify.users', $hook->id());
    }

    public function testNameMethod()
    {
        $app = $this->bootingApp();

        $hook = new NotifyUsers('id', 'Notify Users', ['administrator']);

        $this->assertSame('Notify Users', $hook->name());
    }

    public function testGroupMethod()
    {
        $app = $this->bootingApp();

        $this->assertSame('Notify', new NotifyUsers('id', 'foo', ['administrator'])->group());
        $this->assertSame('Custom', new NotifyUsers('id', 'foo', ['administrator'], group: 'Custom')->group());
    }

    public function testDefaultSelectedMethod()
    {
        $app = $this->bootingApp();

        $this->assertFalse(new NotifyUsers('id', 'foo', ['administrator'])->defaultSelected());
        $this->assertTrue(new NotifyUsers('id', 'foo', ['administrator'], defaultSelected: true)->defaultSelected());
    }

    public function testSupportsBackupMethod()
    {
        $app = $this->bootingApp();

        $hook = new NotifyUsers('id', 'foo', ['administrator']);

        $this->assertTrue($hook->supportsBackup());
    }

    public function testSupportsRestoreMethod()
    {
        $app = $this->bootingApp();

        $hook = new NotifyUsers('id', 'foo', ['administrator']);

        $this->assertTrue($hook->supportsRestore());
    }

    public function testCreateHookHandlerReturnsHookHandlerInterface()
    {
        $app = $this->bootingApp();

        $hook = new NotifyUsers('id', 'foo', ['administrator']);

        $handler = $hook->createHookHandler($app->container());

        $this->assertInstanceOf(\Tobento\App\Backup\Hook\HookHandlerInterface::class, $handler);
    }

    private function createProcess(AppInterface $app): \Tobento\App\Backup\Process\ProcessInterface
    {
        $repo = $app->get(ProcessRepositoryInterface::class);

        $entity = $repo->create([
            'type' => 'backup',
            'status' => 'pending',
            'name' => 'My Backup',
            'user_id' => null,
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
            userId: null
        );
    }

    public function testProcessStartedNotifiesAllMatchingUsers()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();

        // Create users with roles
        $userRepo = $app->get(UserRepositoryInterface::class);

        $admin1 = $userRepo->create(['email' => 'admin1@example.com', 'role_key' => 'administrator']);
        $admin2 = $userRepo->create(['email' => 'admin2@example.com', 'role_key' => 'administrator']);
        $userRepo->create(['email' => 'user@example.com', 'role_key' => 'user']); // should NOT be notified

        $process = $this->createProcess($app);

        $hook = new NotifyUsers(
            id: 'notify.users',
            name: 'Notify Users',
            roles: ['administrator'],
            channels: ['mail'],
            notifyOn: ['started']
        )->createHookHandler($app->container());

        $hook->processStarted($process);

        // Assert both admins received notifications
        $notifier
            ->assertSent(
                Notification::class,
                static function(ChannelMessagesInterface $messages): bool {

                    if ($messages->successful()->channelNames() !== ['mail']) {
                        return false;
                    }

                    $email = $messages
                        ->recipient()
                        ->getAddressForChannel('mail', $messages->notification())
                        ?->email();

                    return in_array($email, ['admin1@example.com', 'admin2@example.com'], true);
                }
            )
            ->assertSentTimes(Notification::class, 2);
    }

    public function testProcessStartedSkipsIfNoMatchingUsers()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();

        // Create users with wrong roles
        $userRepo = $app->get(UserRepositoryInterface::class);
        $userRepo->create(['email' => 'user@example.com', 'role_key' => 'user']);

        $process = $this->createProcess($app);

        $hook = new NotifyUsers(
            id: 'notify.users',
            name: 'Notify Users',
            roles: ['administrator'], // no admins exist
            channels: ['mail'],
            notifyOn: ['started']
        )->createHookHandler($app->container());

        $hook->processStarted($process);

        $notifier->assertNothingSent();
    }

    public function testLimitIsRespected()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();

        $userRepo = $app->get(UserRepositoryInterface::class);

        // Create 3 admins
        $userRepo->create(['email' => 'admin1@example.com', 'role_key' => 'administrator']);
        $userRepo->create(['email' => 'admin2@example.com', 'role_key' => 'administrator']);
        $userRepo->create(['email' => 'admin3@example.com', 'role_key' => 'administrator']);

        $process = $this->createProcess($app);

        $hook = new NotifyUsers(
            id: 'notify.users',
            name: 'Notify Users',
            roles: ['administrator'],
            channels: ['mail'],
            notifyOn: ['started'],
            limit: 2 // only notify 2 admins
        )->createHookHandler($app->container());

        $hook->processStarted($process);

        $notifier->assertSentTimes(Notification::class, 2);
    }

    public function testProcessFinishedNotifiesUsers()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();

        $userRepo = $app->get(UserRepositoryInterface::class);
        $userRepo->create(['email' => 'admin@example.com', 'role_key' => 'administrator']);

        $process = $this->createProcess($app);

        $hook = new NotifyUsers(
            id: 'notify.users',
            name: 'Notify Users',
            roles: ['administrator'],
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
                        ?->email() === 'admin@example.com';
                }
            )
            ->assertSentTimes(Notification::class, 1);
    }

    public function testProcessFailedNotifiesUsers()
    {
        $notifier = $this->fakeNotifier();
        $app = $this->bootingApp();

        $userRepo = $app->get(UserRepositoryInterface::class);
        $userRepo->create(['email' => 'admin@example.com', 'role_key' => 'administrator']);

        $process = $this->createProcess($app);

        $hook = new NotifyUsers(
            id: 'notify.users',
            name: 'Notify Users',
            roles: ['administrator'],
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
                        ?->email() === 'admin@example.com';
                }
            )
            ->assertSentTimes(Notification::class, 1);
    }
}