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
use Tobento\App\Backup\Artifact\WritableZipArtifact;
use Tobento\App\Backup\Manifest\ManifestFactoryInterface;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Testing\Database\RefreshDatabases;
use ZipArchive;

class WritableZipArtifactTest extends \Tobento\App\Testing\TestCase
{
    use RefreshDatabases;

    protected string $tmpZipPath;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        return $app;
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpZipPath) && is_file($this->tmpZipPath)) {
            @unlink($this->tmpZipPath);
        }

        parent::tearDown();
    }

    protected function createArtifact(AppInterface $app, int $throughputInBytes = 50_000_000): WritableZipArtifact
    {
        $this->tmpZipPath = sys_get_temp_dir().'/tobento-wza-test-'.uniqid().'.zip';

        return new WritableZipArtifact(
            manifestFactory: $app->get(ManifestFactoryInterface::class),
            zipPath: $this->tmpZipPath,
            id: 'backup.zip',
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

    protected function readZipEntry(string $zipPath, string $entryName): string
    {
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $content = $zip->getFromName($entryName);
        $zip->close();

        return $content === false ? '' : $content;
    }

    public function testGetterMethods()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact(app: $app, throughputInBytes: 1000);
        
        $this->assertSame('backup.zip', $artifact->id());
        $this->assertSame($this->tmpZipPath, $artifact->zipPath());
        $this->assertSame(1000, $artifact->throughputInBytes());
    }

    public function testCanWriteFileMethodReturnsYesWhenWithinBudget()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact(app: $app, throughputInBytes: 1000);

        $result = $artifact->canWriteFile('a.txt', 500, 10.0, 10.0);

        $this->assertSame(FileAcceptDecision::Yes, $result->decision());
        $this->assertFalse($result->shouldThrow());
    }

    public function testCanWriteFileMethodReturnsDeferWhenNotEnoughTimeRemainingInWindow()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact(app: $app, throughputInBytes: 1000);

        $result = $artifact->canWriteFile('a.txt', 5000, 1.0, 10.0);

        $this->assertSame(FileAcceptDecision::Defer, $result->decision());
        $this->assertFalse($result->shouldThrow());
    }

    public function testCanWriteFileMethodReturnsNeverFitsWhenExceedsFullTimeBudget()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact(app: $app, throughputInBytes: 1000);

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

    public function testWriteFileFromStreamMethodWritesFileIntoZip()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app);

        $stream = $app->get(StreamFactoryInterface::class)->createStream('aaa');
        $artifact->writeFileFromStream('backup/a.txt', $stream, 3);

        $process = $this->createBackupProcess($app);
        $artifact->finalize($process);

        $this->assertSame('aaa', $this->readZipEntry($this->tmpZipPath, 'backup/a.txt'));
    }

    public function testWriteFileFromStreamMethodCreatesNestedDirectoryEntries()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app);

        $stream = $app->get(StreamFactoryInterface::class)->createStream('nested');
        $artifact->writeFileFromStream('backup/sub/deep/n.txt', $stream, 6);

        $process = $this->createBackupProcess($app);
        $artifact->finalize($process);

        $zip = new ZipArchive();
        $zip->open($this->tmpZipPath);

        $this->assertNotFalse($zip->locateName('backup/'));
        $this->assertNotFalse($zip->locateName('backup/sub/'));
        $this->assertNotFalse($zip->locateName('backup/sub/deep/'));
        $this->assertNotFalse($zip->locateName('backup/sub/deep/n.txt'));

        $zip->close();
    }

    public function testWriteFileFromPathMethodWritesFileIntoZip()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app);

        $tmpFile = tempnam(sys_get_temp_dir(), 'wza_src_');
        file_put_contents($tmpFile, 'bbb');

        $artifact->writeFileFromPath('backup/b.txt', $tmpFile, filesize($tmpFile));

        $process = $this->createBackupProcess($app);
        $artifact->finalize($process);

        @unlink($tmpFile);

        $this->assertSame('bbb', $this->readZipEntry($this->tmpZipPath, 'backup/b.txt'));
    }

    public function testWriteFileFromStreamMethodIgnoresPassedFileSizeAndUsesActualContentLength()
    {
        // Note: unlike WritableInMemoryArtifact/WritableFileStorageArtifact,
        // this implementation ignores the passed $fileSize entirely and
        // recomputes it from the actual stream content (strlen($contents)).
        // This test pins down that behavior so a future change is caught.
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app, throughputInBytes: 1000);

        $stream = $app->get(StreamFactoryInterface::class)->createStream(str_repeat('a', 3000));

        // Claim a tiny fileSize (10 bytes) that would never trigger a
        // defer on its own, even though real content is 3000 bytes.
        $artifact->writeFileFromStream('big.txt', $stream, 10);

        // If the real content length (3000) was tracked in $bytesThisJob,
        // a further 8000-byte file should now defer (3000+8000=11000
        // bytes needs 11s, exceeding the 10s remaining window). If the
        // claimed size (10) had been trusted instead, this would still
        // return Yes.
        $result = $artifact->canWriteFile('second.txt', 8000, 10.0, 10.0);

        $this->assertSame(FileAcceptDecision::Defer, $result->decision());
    }

    public function testFinalizeMethodWritesManifest()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app);

        $process = $this->createBackupProcess($app);
        $artifact->finalize($process);

        $manifestContent = $this->readZipEntry($this->tmpZipPath, 'manifest.json');
        $this->assertNotEmpty($manifestContent);
    }

    public function testPartialPersistMethodDoesNotThrow()
    {
        $app = $this->bootingApp();
        $artifact = $this->createArtifact($app);

        $artifact->partialPersist();

        $this->addToAssertionCount(1);
    }
}