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

use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\Action\Actions;
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Process\RestoreProcess;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Backup\Test\Factory;
use Tobento\App\Backup\Test\App;

class RestoreProcessTest extends TestCase
{
    protected function createArtifact(): ReadableArtifactInterface
    {
        return new App\ReadableInMemoryArtifact(
            streamFactory: Factory::createStreamFactory(),
        );
    }

    public function testThatImplementsInterface()
    {
        $process = new RestoreProcess(
            processId: 1,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
            name: 'RestoreJob',
            userId: 42,
            meta: ['foo' => 'bar'],
        );

        $this->assertInstanceOf(RestoreProcessInterface::class, $process);
    }
    
    public function testIdMethod()
    {
        $process = new RestoreProcess(
            processId: 5,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
        );

        $this->assertSame(5, $process->id());
    }

    public function testNameMethod()
    {
        $process = new RestoreProcess(
            processId: 1,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
            name: 'RestoreJob',
        );

        $this->assertSame('RestoreJob', $process->name());
    }

    public function testUserIdMethod()
    {
        $process = new RestoreProcess(
            processId: 1,
            actions: new Actions(new NullAction('a')),
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
            name: null,
            userId: 77,
        );

        $this->assertSame(77, $process->userId());
    }
    
    public function testActionsMethod()
    {
        $actions = new Actions(new NullAction('a'));

        $process = new RestoreProcess(
            processId: 1,
            actions: $actions,
            processedActions: [],
            actionStates: [],
            hooks: [],
            artifact: $this->createArtifact(),
            processRepository: Factory::createProcessRepository(),
        );

        $this->assertSame($actions, $process->actions());
        $this->assertSame(['a'], $process->actions()->ids());
    }

    public function testHooksMethod()
    {
        $process = new RestoreProcess(
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
        $process = new RestoreProcess(
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

        $process = new RestoreProcess(
            processId: 1,
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

        $process = new RestoreProcess(
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

        $process = new RestoreProcess(
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
    }

    public function testAllActionsFinishedMethod()
    {
        $repo = Factory::createProcessRepository();
        $repo->create(['id' => 1]);

        $a = new NullAction('a');
        $b = new NullAction('b');

        $actions = new Actions($a, $b);

        $process = new RestoreProcess(
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
        $process = new RestoreProcess(
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

        $process = new RestoreProcess(
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