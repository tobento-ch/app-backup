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

namespace Tobento\App\Backup\Test\Feature\Artifact;

use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\AppInterface;
use Tobento\App\Backup\Artifact\EncryptionWritableArtifact;
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Artifact\FileAcceptResult;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Test\App;
use Tobento\App\Backup\Test\App\WritableInMemoryArtifact;
use Tobento\Service\Encryption\EncrypterInterface;

class EncryptionWritableArtifactTest extends \Tobento\App\Testing\TestCase
{
    use App\Traits\InMemoryArtifactHelpers;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');

        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        $app->on(
            ArtifactRegistriesInterface::class,
            static function(ArtifactRegistriesInterface $registries): void {
                $registries->add(new App\InMemoryArtifactRegistry());
            }
        );

        return $app;
    }

    protected function createInner(AppInterface $app): WritableInMemoryArtifact
    {
        return new WritableInMemoryArtifact(id: 'folder');
    }

    protected function createEncryptionArtifact(AppInterface $app, WritableArtifactInterface $inner): EncryptionWritableArtifact
    {
        return new EncryptionWritableArtifact(
            inner: $inner,
            streamFactory: $app->get(StreamFactoryInterface::class),
            encrypter: $app->get(EncrypterInterface::class),
            throughputInBytes: 10_000, // small to trigger timing branches
        );
    }
    
    protected function createBackupProcess(AppInterface $app): BackupProcessInterface
    {
        $backupEntity = new BackupEntity([
            'id' => 1,
            'name' => 'Test Backup',
            'user_id' => 0,
            'created_at' => time(),
            'options' => [
                'artifacts' => ['inmemory'],
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

    public function testImplementsWritableArtifactInterface()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        $this->assertInstanceOf(WritableArtifactInterface::class, $artifact);
    }

    public function testIdMethodReturnsInnerArtifactId()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        $this->assertSame('folder', $artifact->id());
    }
    
    public function testAdaptedReturnsInnerArtifact()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        $this->assertSame($inner, $artifact->adapted());
    }

    public function testCanWriteFileRejectsNegativeSize()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        $result = $artifact->canWriteFile('bad.txt', -1, 5, 10);

        $this->assertSame(FileAcceptDecision::NeverFits, $result->decision());
        $this->assertTrue($result->shouldThrow());
    }

    public function testCanWriteFileDelegatesForEmptyFile()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        $result = $artifact->canWriteFile('empty.txt', 0, 5, 10);

        $this->assertEquals(
            $inner->canWriteFile('empty.txt', 0, 5, 10),
            $result
        );
    }

    public function testCanWriteFileNeverFitsWhenEncryptionTooSlow()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        // fileSize = 20000 bytes → 20000 / 10000 = 2 seconds
        // timeBudget = 1 second → never fits
        $result = $artifact->canWriteFile('big.txt', 20000, 1, 1);

        $this->assertSame(FileAcceptDecision::NeverFits, $result->decision());
        $this->assertTrue($result->shouldThrow());
    }

    public function testCanWriteFileDefersWhenNotEnoughRemainingTime()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        // fileSize = 10000 bytes → 1 second encryption
        // remainingSeconds = 0.5 → defer
        $result = $artifact->canWriteFile('later.txt', 10000, 0.5, 5);

        $this->assertSame(FileAcceptDecision::Defer, $result->decision());
        $this->assertFalse($result->shouldThrow());
    }

    public function testCanWriteFileDelegatesWhenFitsNow()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        // fileSize = 5000 bytes → 0.5 seconds encryption
        // remainingSeconds = 5 → fits
        $result = $artifact->canWriteFile('ok.txt', 5000, 5, 10);

        $this->assertEquals(
            $inner->canWriteFile('ok.txt', 5000, 5, 10),
            $result
        );
    }

    public function testWriteFileFromStreamEncryptsContent()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        $stream = $app->get(StreamFactoryInterface::class)->createStream('hello-world');

        $artifact->writeFileFromStream('test.txt', $stream, 11);

        $written = $inner->writtenFiles()['test.txt'] ?? null;

        $this->assertNotSame('hello-world', $written);

        $decrypted = $app->get(EncrypterInterface::class)->decrypt($written);

        $this->assertSame('hello-world', $decrypted);
    }

    public function testWriteFileFromStreamDoesNotEncryptManifest()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        $stream = $app->get(StreamFactoryInterface::class)->createStream('manifest-content');

        $artifact->writeFileFromStream('manifest.json', $stream, 17);

        $written = $inner->writtenFiles()['manifest.json'] ?? null;

        $this->assertSame('manifest-content', $written);
    }

    public function testWriteFileFromPathEncryptsContent()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        $tmp = tempnam(sys_get_temp_dir(), 'encw');
        file_put_contents($tmp, 'abc123');

        $artifact->writeFileFromPath('file.txt', $tmp, 6);

        $written = $inner->writtenFiles()['file.txt'] ?? null;

        $this->assertNotSame('abc123', $written);

        $decrypted = $app->get(EncrypterInterface::class)->decrypt($written);

        $this->assertSame('abc123', $decrypted);

        unlink($tmp);
    }

    public function testPartialPersistDelegates()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        $artifact->partialPersist();

        $this->assertTrue($inner->wasPartialPersistCalled());
    }

    public function testFinalizeDelegates()
    {
        $app = $this->bootingApp();
        $inner = $this->createInner($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        $process = $this->createBackupProcess($app);

        $artifact->finalize($process);

        $this->assertTrue($inner->wasFinalizeCalled());
    }
}