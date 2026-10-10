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

use Tobento\App\AppInterface;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Artifact\FileAcceptResult;
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Artifact\WritableArtifacts;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Test\App\WritableInMemoryArtifact;
use Tobento\App\Testing\Database\RefreshDatabases;

class WritableArtifactsTest extends \Tobento\App\Testing\TestCase
{
    use RefreshDatabases;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        return $app;
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

        return $factory->createProcess(
            backupEntity: $backupEntity,
            processId: null,
            userId: null,
        );
    }

    protected function acceptResult(): FileAcceptResult
    {
        return new FileAcceptResult(FileAcceptDecision::Yes, reason: '', throw: false, skip: false);
    }

    protected function deferResult(): FileAcceptResult
    {
        return new FileAcceptResult(FileAcceptDecision::Defer, reason: 'defer', throw: false, skip: false);
    }

    protected function throwResult(): FileAcceptResult
    {
        return new FileAcceptResult(FileAcceptDecision::NeverFits, reason: 'never fits', throw: true, skip: false);
    }

    protected function skipResult(): FileAcceptResult
    {
        return new FileAcceptResult(FileAcceptDecision::Yes, reason: 'skip', throw: false, skip: true);
    }

    public function testImplementsWritableArtifactInterface()
    {
        $app = $this->bootingApp();

        $this->assertInstanceOf(WritableArtifactInterface::class, new WritableArtifacts());
    }
    
    public function testIdMethod()
    {
        $app = $this->bootingApp();
        
        $artifacts = new WritableArtifacts();

        $this->assertSame('', $artifacts->id());
    }

    public function testArtifactsMethodReturnsGivenArtifacts()
    {
        $app = $this->bootingApp();
        
        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();

        $artifacts = new WritableArtifacts($a, $b);

        $this->assertSame([$a, $b], $artifacts->artifacts());
    }

    public function testCanWriteFileMethodReturnsYesWhenAllArtifactsAccept()
    {
        $app = $this->bootingApp();
        
        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();
        $a->forceCanWriteFileResult($this->acceptResult());
        $b->forceCanWriteFileResult($this->acceptResult());

        $artifacts = new WritableArtifacts($a, $b);

        $result = $artifacts->canWriteFile('a.txt', 10, 5.0, 10.0);

        $this->assertSame(FileAcceptDecision::Yes, $result->decision());
        $this->assertFalse($result->shouldThrow());
        $this->assertFalse($result->shouldSkip());
    }

    public function testCanWriteFileMethodReturnsThrowResultWhenAnyArtifactThrows()
    {
        $app = $this->bootingApp();
        
        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();
        $a->forceCanWriteFileResult($this->acceptResult());
        $b->forceCanWriteFileResult($this->throwResult());

        $artifacts = new WritableArtifacts($a, $b);

        $result = $artifacts->canWriteFile('a.txt', 10, 5.0, 10.0);

        $this->assertTrue($result->shouldThrow());
    }

    public function testCanWriteFileMethodReturnsDeferResultWhenAnyArtifactDefers()
    {
        $app = $this->bootingApp();
        
        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();
        $a->forceCanWriteFileResult($this->acceptResult());
        $b->forceCanWriteFileResult($this->deferResult());

        $artifacts = new WritableArtifacts($a, $b);

        $result = $artifacts->canWriteFile('a.txt', 10, 5.0, 10.0);

        $this->assertSame(FileAcceptDecision::Defer, $result->decision());
    }

    public function testCanWriteFileMethodPrioritizesThrowOverDefer()
    {
        $app = $this->bootingApp();
        
        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();
        $a->forceCanWriteFileResult($this->deferResult());
        $b->forceCanWriteFileResult($this->throwResult());

        $artifacts = new WritableArtifacts($a, $b);

        $result = $artifacts->canWriteFile('a.txt', 10, 5.0, 10.0);

        $this->assertTrue($result->shouldThrow());
    }

    public function testCanWriteFileMethodPrioritizesDeferOverSkip()
    {
        $app = $this->bootingApp();
        
        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();
        $a->forceCanWriteFileResult($this->skipResult());
        $b->forceCanWriteFileResult($this->deferResult());

        $artifacts = new WritableArtifacts($a, $b);

        $result = $artifacts->canWriteFile('a.txt', 10, 5.0, 10.0);

        $this->assertSame(FileAcceptDecision::Defer, $result->decision());
    }

    public function testCanWriteFileMethodReturnsSkipResultWhenAllArtifactsSkip()
    {
        $app = $this->bootingApp();
        
        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();
        $a->forceCanWriteFileResult($this->skipResult());
        $b->forceCanWriteFileResult($this->skipResult());

        $artifacts = new WritableArtifacts($a, $b);

        $result = $artifacts->canWriteFile('a.txt', 10, 5.0, 10.0);

        $this->assertTrue($result->shouldSkip());
    }

    public function testCanWriteFileMethodDoesNotReturnSkipWhenOnlySomeArtifactsSkip()
    {
        $app = $this->bootingApp();
        
        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();
        $a->forceCanWriteFileResult($this->skipResult());
        $b->forceCanWriteFileResult($this->acceptResult());

        $artifacts = new WritableArtifacts($a, $b);

        $result = $artifacts->canWriteFile('a.txt', 10, 5.0, 10.0);

        // Not all skip, and none defer/throw -> falls through to "Yes" branch.
        $this->assertFalse($result->shouldSkip());
        $this->assertFalse($result->shouldThrow());
    }

    public function testCanWriteFileMethodReturnsAcceptResultWhenAcceptComesFirst()
    {
        $app = $this->bootingApp();
        
        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();
        $a->forceCanWriteFileResult($this->acceptResult());
        $b->forceCanWriteFileResult($this->skipResult());

        $artifacts = new WritableArtifacts($a, $b);

        $result = $artifacts->canWriteFile('a.txt', 10, 5.0, 10.0);

        $this->assertFalse($result->shouldSkip());
    }

    public function testWriteFileFromPathMethodWritesToAllArtifacts()
    {
        $app = $this->bootingApp();
        
        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();

        $tmpFile = tempnam(sys_get_temp_dir(), 'wa_test_');
        file_put_contents($tmpFile, 'content');

        $artifacts = new WritableArtifacts($a, $b);
        $artifacts->writeFileFromPath('target/a.txt', $tmpFile, filesize($tmpFile));

        @unlink($tmpFile);

        $this->assertSame('content', $a->writtenFiles()['target/a.txt'] ?? null);
        $this->assertSame('content', $b->writtenFiles()['target/a.txt'] ?? null);
    }

    public function testPartialPersistMethodCallsAllArtifacts()
    {
        $app = $this->bootingApp();
        
        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();

        $artifacts = new WritableArtifacts($a, $b);
        $artifacts->partialPersist();

        $this->assertTrue($a->wasPartialPersistCalled());
        $this->assertTrue($b->wasPartialPersistCalled());
    }

    public function testFinalizeMethodCallsAllArtifacts()
    {
        $app = $this->bootingApp();

        $a = new WritableInMemoryArtifact();
        $b = new WritableInMemoryArtifact();

        $process = $this->createBackupProcess($app);

        $artifacts = new WritableArtifacts($a, $b);
        $artifacts->finalize($process);

        $this->assertTrue($a->wasFinalizeCalled());
        $this->assertTrue($b->wasFinalizeCalled());
    }
}