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
use Tobento\App\Backup\Hook\SendGeneratedBackupZipToEmail;
use Tobento\App\Backup\Hook\HookInterface;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Registry\Artifact\ZipArtifactRegistry;
use Tobento\App\Backup\Test\Factory;
use Tobento\App\Backup\Test\App\InMemoryArtifactRegistry;
use Tobento\App\Testing\Database\RefreshDatabases;
use Tobento\App\Testing\Logging\LogEntry;
use Tobento\App\Testing\TestCase;
use Tobento\Service\Mail\Message;
use Tobento\Service\Mail\Parameter;

class SendGeneratedBackupZipToEmailTest extends TestCase
{
    use RefreshDatabases;

    protected string $tmpLocation;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpLocation = sys_get_temp_dir().'/tobento-email-hook-test-'.uniqid('', true).'/';
        mkdir($this->tmpLocation, 0755, true);
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        $this->removeDir($this->tmpLocation);

        parent::tearDown();
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    protected function fakeZipArtifactRegistry(): ZipArtifactRegistry
    {
        return new ZipArtifactRegistry(
            id: 'zip.local',
            name: 'ZIP (Local Private Storage)',
            location: $this->tmpLocation,
        );
    }

    protected function createHook(string $email = 'backup@example.com'): SendGeneratedBackupZipToEmail
    {
        return new SendGeneratedBackupZipToEmail(
            id: 'send.backup.zip',
            name: 'Send Backup ZIP',
            email: $email,
            subject: 'Backup: {name}',
            message: 'See attached backup.',
        );
    }

    protected function createBackupProcess(AppInterface $app, array $artifacts = ['zip.local'])
    {
        $backupEntity = Factory::createBackupEntity([
            'name' => 'Nightly Backup',
            'options' => [
                'artifacts' => $artifacts,
                'app' => ['dir' => ['root' => []]],
                'hooks' => [],
            ],
        ]);

        $factory = $app->get(BackupProcessFactoryInterface::class);

        return $factory->createProcess(
            backupEntity: $backupEntity,
            processId: null,
            userId: null,
        );
    }

    public function testImplementsHookInterface()
    {
        $this->bootingApp();
        
        $this->assertInstanceOf(HookInterface::class, $this->createHook());
    }

    public function testIdMethod()
    {
        $this->bootingApp();
        
        $this->assertSame('send.backup.zip', $this->createHook()->id());
    }

    public function testNameMethod()
    {
        $this->bootingApp();

        $this->assertSame('Send Backup ZIP', $this->createHook()->name());
    }

    public function testGroupMethodDefaultsToDelivery()
    {
        $this->bootingApp();

        $this->assertSame('Delivery', $this->createHook()->group());
    }

    public function testGroupMethodCustom()
    {
        $this->bootingApp();

        $hook = new SendGeneratedBackupZipToEmail(
            id: 'my-id',
            name: 'My Name',
            email: 'a@b.com',
            subject: 'Subject',
            message: 'Message',
            group: 'Custom',
        );

        $this->assertSame('Custom', $hook->group());
    }

    public function testDefaultSelectedMethod()
    {
        $this->bootingApp();
        
        $this->assertFalse($this->createHook()->defaultSelected());
    }

    public function testSupportsBackupMethod()
    {
        $this->bootingApp();
        
        $this->assertTrue($this->createHook()->supportsBackup());
    }

    public function testSupportsRestoreMethod()
    {
        $this->bootingApp();
        
        $this->assertFalse($this->createHook()->supportsRestore());
    }

    public function testCreateHookHandlerReturnsHookHandlerInterface()
    {
        $app = $this->bootingApp();

        $handler = $this->createHook()->createHookHandler($app->container());

        $this->assertInstanceOf(\Tobento\App\Backup\Hook\HookHandlerInterface::class, $handler);
    }

    public function testProcessFinishedSendsZipAsAttachment()
    {
        $fakeMail = $this->fakeMail();

        $this->fakeConfig()
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()])
            ->with('backup.actions', [Factory::createActionRegistry(id: 'test.action')]);

        $app = $this->bootingApp();

        $process = $this->createBackupProcess($app);

        // Finalize writes manifest.json and closes the zip, making it
        // a real file on disk - required before processFinished() can
        // find and attach it.
        $process->artifact()->finalize($process);

        $hook = $this->createHook(email: 'backup@example.com')->createHookHandler($app->container());
        $hook->processFinished($process);

        $fakeMail->mailer(name: 'default')
            ->sent(Message::class)
            ->assertHasTo('backup@example.com')
            ->assertSubject('Backup: Nightly Backup')
            ->assertHasParameter(
                Parameter\File::class,
                fn (Parameter\File $f) => $f->file()->getBasename() === 'Nightly-Backup-1.zip'
            );
    }

    public function testProcessFinishedLogsAndSkipsWhenNoZipArtifactPresent()
    {
        $fakeMail = $this->fakeMail();
        $fakeLogging = $this->fakeLogging();

        $this->fakeConfig()
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()])
            ->with('backup.actions', [Factory::createActionRegistry(id: 'test.action')]);

        $app = $this->bootingApp();

        $process = $this->createBackupProcess(app: $app, artifacts: []);

        $hook = $this->createHook()->createHookHandler($app->container());
        $hook->processFinished($process);

        $fakeMail->mailer(name: 'default')->assertNothingSent();

        $fakeLogging->logger()
            ->assertLogged(fn (LogEntry $log): bool =>
                $log->level === 'info'
                && $log->message === 'No backup ZIP artifact to send email with ZIP'
            );
    }
    
    public function testProcessFinishedLogsErrorWhenMailerThrows()
    {
        $fakeMail = $this->fakeMail();
        $fakeLogging = $this->fakeLogging();

        $this->fakeConfig()
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()])
            ->with('backup.actions', [Factory::createActionRegistry(id: 'test.action')]);

        $app = $this->bootingApp();

        $process = $this->createBackupProcess($app);
        $process->artifact()->finalize($process);

        $fakeMail->mailer(name: 'default')->throwOnSend(
            new \Tobento\Service\Mail\MailerException('Simulated send failure')
        );

        $hook = $this->createHook()->createHookHandler($app->container());
        $hook->processFinished($process);

        $fakeLogging->logger()
            ->assertLogged(fn (LogEntry $log): bool =>
                $log->level === 'error'
                && $log->message === 'Failed to send backup ZIP via email'
            );
    }
}