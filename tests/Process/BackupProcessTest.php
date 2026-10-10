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

namespace Tobento\App\Backup\Test\Process;

use Psr\Http\Message\StreamInterface;
use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\Action\Actions;
use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\Artifact\FileAcceptResultInterface;
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Process\BackupProcess;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Test\Factory;

class BackupProcessTest extends TestCase
{
    protected function createArtifact(): WritableArtifactInterface
    {
        return new class() implements WritableArtifactInterface {

            public function canWriteFile(
                string $filePath,
                int $fileSize,
                float $remainingSeconds,
                float $timeBudgetInSeconds
            ): FileAcceptResultInterface {
                return new class implements FileAcceptResultInterface {
                    public function accepted(): bool { return true; }
                    public function reason(): ?string { return null; }
                };
            }
            
            public function id(): string { return ''; }
            public function writeFileFromStream(string $path, StreamInterface $stream, int $fileSize): void {}
            public function writeFileFromPath(string $path, string $sourcePath, int $fileSize): void {}
            public function partialPersist(): void {}
            public function finalize(BackupProcessInterface $process): void {}
        };
    }
    
    public function testThatImplementsInterface()
    {
        $process = new BackupProcess(
            processId: 2,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
        );

        $this->assertInstanceOf(BackupProcessInterface::class, $process);
    }

    public function testIdMethod()
    {
        $process = new BackupProcess(
            processId: 2,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
        );

        $this->assertSame(2, $process->id());
    }

    public function testNameMethod()
    {
        $process = new BackupProcess(
            processId: 1,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
            name: 'My Backup',
        );

        $this->assertSame('My Backup', $process->name());
    }

    public function testUserIdMethod()
    {
        $process = new BackupProcess(
            processId: 1,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
            name: null,
            userId: 99,
        );

        $this->assertSame(99, $process->userId());
    }    

    public function testActionsMethod()
    {
        $actions = new Actions(new NullAction('a'));

        $process = new BackupProcess(
            processId: 1,
            actions: $actions,
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
            name: null,
            userId: 99,
        );

        $this->assertSame($actions, $process->actions());
        $this->assertSame(['a'], $process->actions()->ids());
    }
    
    public function testHooksMethod()
    {
        $process = new BackupProcess(
            processId: 1,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: ['h1', 'h2'],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
        );

        $this->assertSame(['h1', 'h2'], $process->hooks());
    }

    public function testMetaMethod()
    {
        $process = new BackupProcess(
            processId: 1,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
            name: null,
            userId: null,
            meta: ['foo' => 'bar'],
        );

        $this->assertSame(['foo' => 'bar'], $process->meta());
    }

    public function testArtifactMethod()
    {
        $artifact = $this->createArtifact();
        
        $process = new BackupProcess(
            processId: 2,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $artifact,
            processRepository: Factory::createProcessRepository(),
        );

        $this->assertSame($artifact, $process->artifact());
    }
    
    public function testMarkActionFinishedMethodPersists()
    {
        $repo = Factory::createProcessRepository();
        $repo->create(['id' => 1]);
        
        $action = new NullAction('a');
        $actions = new Actions($action);

        $process = new BackupProcess(
            processId: 1,
            actions: $actions,
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: $repo,
        );

        $this->assertFalse($process->isActionFinished($action));

        $process->markActionFinished($action);

        $this->assertTrue($process->isActionFinished($action));

        $stored = $repo->findById(1);

        $this->assertSame(['a'], $stored->processedActions());
    }
    
    public function testIsActionFinishedMethod()
    {
        $repo = Factory::createProcessRepository();
        $repo->create(['id' => 1]);

        $action = new NullAction('a');
        $actions = new Actions($action);

        $process = new BackupProcess(
            processId: 1,
            actions: $actions,
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: $repo,
        );

        // Initially not finished
        $this->assertFalse($process->isActionFinished($action));

        // Mark finished
        $process->markActionFinished($action);

        // Now finished
        $this->assertTrue($process->isActionFinished($action));
    }

    public function testAllActionsFinishedMethod()
    {
        $repo = Factory::createProcessRepository();
        $repo->create(['id' => 1]);
        
        $a = new NullAction('a');
        $b = new NullAction('b');

        $actions = new Actions($a, $b);

        $process = new BackupProcess(
            processId: 1,
            actions: $actions,
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: $repo,
        );

        $this->assertFalse($process->allActionsFinished());

        $process->markActionFinished($a);
        $this->assertFalse($process->allActionsFinished());

        $process->markActionFinished($b);
        $this->assertTrue($process->allActionsFinished());
    }

    public function testStateForMethodReturnsEmptyIfNotSet()
    {
        $process = new BackupProcess(
            processId: 1,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
        );

        $this->assertSame([], $process->stateFor(new NullAction('a')));
    }

    public function testUpdateStateForMethodMergesAndPersists()
    {
        $repo = Factory::createProcessRepository();
        $repo->create(['id' => 1]);
        
        $action = new NullAction('a');
        $actions = new Actions($action);

        $process = new BackupProcess(
            processId: 1,
            actions: $actions,
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: $repo,
        );

        $process->updateStateFor($action, ['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], $process->stateFor($action));

        $process->updateStateFor($action, ['baz' => 'qux']);
        $this->assertSame(['foo' => 'bar', 'baz' => 'qux'], $process->stateFor($action));

        $stored = $repo->findById(1);

        $this->assertSame(
            ['a' => ['foo' => 'bar', 'baz' => 'qux']],
            $stored->actionStates()
        );
    }
}