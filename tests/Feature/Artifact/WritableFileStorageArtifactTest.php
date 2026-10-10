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
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Artifact\WritableFileStorageArtifact;
use Tobento\App\Backup\Manifest\ManifestFactoryInterface;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Testing\Database\RefreshDatabases;

class WritableFileStorageArtifactTest extends \Tobento\App\Testing\TestCase
{
    use \Tobento\App\Testing\FileStorage\RefreshFileStorages;
    use RefreshDatabases;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        return $app;
    }

    protected function createArtifact(
        AppInterface $app,
        string $folder = 'backup',
        int $throughputInBytes = 50_000_000,
    ): WritableFileStorageArtifact {
        $fileStorage = $this->fakeFileStorage();

        return new WritableFileStorageArtifact(
            streamFactory: $app->get(StreamFactoryInterface::class),
            manifestFactory: $app->get(ManifestFactoryInterface::class),
            storage: $fileStorage->storage(name: 'uploads-private'),
            folder: $folder,
            throughputInBytes: $throughputInBytes,
        );
    }

    protected function createBackupProcess(AppInterface $app)
    {
        $backupEntity = new BackupEntity([
            'id' => 1,
            'name' => 'Test Backup',
            'status' => 'ready',
            'user_id' => 0,
            'created_at' => time(),
            'options' => [
                'artifacts' => [],
                'app' => ['dir' => ['root' => []]],
                'hooks' => [],
            ],
        ]);

        $factory = $app->get(BackupProcessFactoryInterface::class);

        return $factory->createProcess(backupEntity: $backupEntity, processId: null, userId: null);
    }

    public function testGetterMethods()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app, throughputInBytes: 1000);

        $this->assertSame(
            $app->get(\Tobento\Service\FileStorage\StoragesInterface::class)->get('uploads-private'),
            $artifact->storage()
        );
        $this->assertSame('backup', $artifact->id());
        $this->assertSame('backup', $artifact->folder());
        $this->assertSame(1000, $artifact->throughputInBytes());
    }
    
    public function testCanWriteFileMethodReturnsYesWhenWithinBudget()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app, throughputInBytes: 1000);

        $result = $artifact->canWriteFile('a.txt', 500, 10.0, 10.0);

        $this->assertSame(FileAcceptDecision::Yes, $result->decision());
        $this->assertFalse($result->shouldThrow());
    }

    public function testCanWriteFileMethodReturnsDeferWhenNotEnoughTimeRemainingInWindow()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app, throughputInBytes: 1000);

        $result = $artifact->canWriteFile('a.txt', 5000, 1.0, 10.0);

        $this->assertSame(FileAcceptDecision::Defer, $result->decision());
        $this->assertFalse($result->shouldThrow());
    }

    public function testCanWriteFileMethodReturnsNeverFitsWhenExceedsFullTimeBudget()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app, throughputInBytes: 1000);

        $result = $artifact->canWriteFile('a.txt', 50_000, 10.0, 10.0);

        $this->assertSame(FileAcceptDecision::NeverFits, $result->decision());
        $this->assertTrue($result->shouldThrow());
    }

    public function testCanWriteFileMethodReturnsNeverFitsWhenSizeNegative()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app);

        $result = $artifact->canWriteFile('a.txt', -1, 10.0, 10.0);

        $this->assertSame(FileAcceptDecision::NeverFits, $result->decision());
        $this->assertTrue($result->shouldThrow());
    }
    
    public function testCanWriteFileMethodAccountsForBytesAlreadyWrittenThisJob()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app, throughputInBytes: 1000);

        // Write 6000 bytes worth of content this job.
        $stream = $app->get(StreamFactoryInterface::class)->createStream(str_repeat('a', 6000));
        $artifact->writeFileFromStream('first.txt', $stream, 6000);

        // Remaining window is 10s (10_000 byte capacity), already used 6000.
        // A further 3000-byte file needs 9000 bytes total - still fits.
        $resultFits = $artifact->canWriteFile('second.txt', 3000, 10.0, 10.0);
        $this->assertSame(FileAcceptDecision::Yes, $resultFits->decision());

        // A further 5000-byte file needs 11000 bytes total - exceeds the
        // 10s/10_000-byte remaining window, so it should defer.
        $resultDefers = $artifact->canWriteFile('third.txt', 5000, 10.0, 10.0);
        $this->assertSame(FileAcceptDecision::Defer, $resultDefers->decision());
    }

    public function testWriteFileFromStreamMethodWritesUnderFolder()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();

        $artifact = new WritableFileStorageArtifact(
            streamFactory: $app->get(StreamFactoryInterface::class),
            manifestFactory: $app->get(ManifestFactoryInterface::class),
            storage: $fileStorage->storage(name: 'uploads-private'),
            folder: 'backup',
        );
        
        $stream = $app->get(StreamFactoryInterface::class)->createStream('aaa');
        $artifact->writeFileFromStream('a.txt', $stream, 3);

        $written = $fileStorage->storage(name: 'uploads-private')->with('stream')->file(path: 'backup/a.txt');
        $this->assertSame('aaa', (string) $written->stream());
    }
    
    public function testWriteFileFromPathMethodWritesUnderFolder()
    {
        $app = $this->bootingApp();
        $fileStorage = $this->fakeFileStorage();

        $artifact = new WritableFileStorageArtifact(
            streamFactory: $app->get(StreamFactoryInterface::class),
            manifestFactory: $app->get(ManifestFactoryInterface::class),
            storage: $fileStorage->storage(name: 'uploads-private'),
            folder: 'backup',
        );

        $tmpFile = tempnam(sys_get_temp_dir(), 'wfsa_');
        file_put_contents($tmpFile, 'aaa');

        $artifact->writeFileFromPath('a.txt', $tmpFile, filesize($tmpFile));

        @unlink($tmpFile);

        $written = $fileStorage->storage(name: 'uploads-private')
            ->with('stream')
            ->file(path: 'backup/a.txt');

        $this->assertSame('aaa', (string) $written->stream());
    }

    public function testFinalizeMethodWritesManifest()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();

        $artifact = new WritableFileStorageArtifact(
            streamFactory: $app->get(StreamFactoryInterface::class),
            manifestFactory: $app->get(ManifestFactoryInterface::class),
            storage: $fileStorage->storage(name: 'uploads-private'),
            folder: 'backup',
        );

        $process = $this->createBackupProcess($app);

        $artifact->finalize($process);

        $manifestFile = $fileStorage->storage(name: 'uploads-private')->with('stream')->file(path: 'backup/manifest.json');
        $this->assertNotEmpty((string) $manifestFile->stream());
    }
}