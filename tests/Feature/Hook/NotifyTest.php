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
use Tobento\App\Backup\Hook\Notify;
use Tobento\App\Backup\Hook\HookInterface;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Testing\TestCase;
use Tobento\App\Testing\Database\RefreshDatabases;
use Tobento\Service\Notifier\ChannelMessagesInterface;
use Tobento\Service\Notifier\Recipient;
use Tobento\Service\Notifier\Notification;

class NotifyTest extends TestCase
{
    use RefreshDatabases;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        return $app;
    }

    public function testImplementsHookInterface()
    {
        $app = $this->bootingApp();

        $recipient = new Recipient(email: 'dev@example.com', channels: ['mail']);

        $hook = new Notify(
            id: 'notify.test',
            name: 'Notify Test',
            recipient: $recipient
        );

        $this->assertInstanceOf(HookInterface::class, $hook);
    }

    public function testIdMethod()
    {
        $app = $this->bootingApp();

        $recipient = new Recipient(email: 'dev@example.com', channels: ['mail']);

        $hook = new Notify('notify.test', 'Notify Test', $recipient);

        $this->assertSame('notify.test', $hook->id());
    }

    public function testNameMethod()
    {
        $app = $this->bootingApp();

        $recipient = new Recipient(email: 'dev@example.com', channels: ['mail']);

        $hook = new Notify('id', 'Notify Name', $recipient);

        $this->assertSame('Notify Name', $hook->name());
    }

    public function testGroupMethod()
    {
        $app = $this->bootingApp();

        $recipient = new Recipient(email: 'dev@example.com', channels: ['mail']);

        $this->assertSame('Notify', new Notify('id', 'foo', $recipient)->group());
        $this->assertSame('Custom', new Notify('id', 'foo', $recipient, group: 'Custom')->group());
    }

    public function testDefaultSelectedMethod()
    {
        $app = $this->bootingApp();

        $recipient = new Recipient(email: 'dev@example.com', channels: ['mail']);

        $this->assertFalse(new Notify('id', 'foo', $recipient)->defaultSelected());
        $this->assertTrue(new Notify('id', 'foo', $recipient, defaultSelected: true)->defaultSelected());
    }

    public function testSupportsBackupMethod()
    {
        $app = $this->bootingApp();

        $recipient = new Recipient(email: 'dev@example.com', channels: ['mail']);

        $hook = new Notify('id', 'foo', $recipient);

        $this->assertTrue($hook->supportsBackup());
    }

    public function testSupportsRestoreMethod()
    {
        $app = $this->bootingApp();

        $recipient = new Recipient(email: 'dev@example.com', channels: ['mail']);

        $hook = new Notify('id', 'foo', $recipient);

        $this->assertTrue($hook->supportsRestore());
    }

    public function testCreateHookHandlerMethodReturnsHookHandlerInterface()
    {
        $app = $this->bootingApp();

        $recipient = new Recipient(email: 'dev@example.com', channels: ['mail']);

        $hook = new Notify('id', 'foo', $recipient);

        $handler = $hook->createHookHandler($app->container());

        $this->assertInstanceOf(\Tobento\App\Backup\Hook\HookHandlerInterface::class, $handler);
    }

    public function testProcessStartedSendsNotification()
    {
        $fakeNotifier = $this->fakeNotifier();

        $app = $this->bootingApp();
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

        $recipient = new Recipient(email: 'dev@example.com', channels: ['mail']);

        $hook = new Notify(
            id: 'notify.test',
            name: 'Notify Test',
            recipient: $recipient,
            notifyOn: ['started']
        )->createHookHandler($app->container());

        $hook->processStarted($process);

        $fakeNotifier
            ->assertSent(
                Notification::class,
                static function(ChannelMessagesInterface $messages): bool {

                    // correct channel
                    if ($messages->successful()->channelNames() !== ['mail']) {
                        return false;
                    }

                    // correct recipient
                    return $messages
                        ->recipient()
                        ->getAddressForChannel('mail', $messages->notification())
                        ?->email() === 'dev@example.com';
                }
            )
            ->assertSentTimes(Notification::class, 1);
    }

    public function testProcessFinishedSendsNotification()
    {
        $fakeNotifier = $this->fakeNotifier();

        $app = $this->bootingApp();
        $repo = $app->get(ProcessRepositoryInterface::class);

        $entity = $repo->create([
            'type' => 'backup',
            'status' => 'completed',
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

        $recipient = new Recipient(email: 'dev@example.com', channels: ['mail']);

        $hook = new Notify(
            id: 'notify.test',
            name: 'Notify Test',
            recipient: $recipient,
            notifyOn: ['finished']
        )->createHookHandler($app->container());

        $hook->processFinished($process);

        $fakeNotifier
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

    public function testProcessFailedSendsNotification()
    {
        $fakeNotifier = $this->fakeNotifier();

        $app = $this->bootingApp();
        $repo = $app->get(ProcessRepositoryInterface::class);

        $entity = $repo->create([
            'type' => 'backup',
            'status' => 'failed',
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

        $recipient = new Recipient(email: 'dev@example.com', channels: ['mail']);

        $hook = new Notify(
            id: 'notify.test',
            name: 'Notify Test',
            recipient: $recipient,
            notifyOn: ['failed']
        )->createHookHandler($app->container());

        $hook->processFailed($process, new \Exception('fail'));

        $fakeNotifier
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
}