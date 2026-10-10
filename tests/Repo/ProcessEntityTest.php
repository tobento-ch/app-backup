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

namespace Tobento\App\Backup\Test\Repo;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\ProcessEntityInterface;

class ProcessEntityTest extends TestCase
{
    public function testThatImplementsInterface(): void
    {
        $this->assertInstanceOf(ProcessEntityInterface::class, new ProcessEntity([]));
    }

    public function testId(): void
    {
        $this->assertSame(7, new ProcessEntity(['id' => 7])->id());
        $this->assertSame(0, new ProcessEntity(['id' => []])->id());
        $this->assertSame(0, new ProcessEntity([])->id());
    }

    public function testType(): void
    {
        $this->assertSame('backup', new ProcessEntity(['type' => 'backup'])->type());
        $this->assertSame('', new ProcessEntity(['type' => []])->type());
        $this->assertSame('', new ProcessEntity([])->type());
    }

    public function testTypeId(): void
    {
        $this->assertSame(3, new ProcessEntity(['type_id' => 3])->typeId());
        $this->assertSame(0, new ProcessEntity(['type_id' => 'foo'])->typeId());
        $this->assertSame(0, new ProcessEntity([])->typeId());
    }

    public function testName(): void
    {
        $this->assertSame('My Process', new ProcessEntity(['name' => 'My Process'])->name());
        $this->assertNull(new ProcessEntity(['name' => []])->name());
        $this->assertNull(new ProcessEntity([])->name());
    }

    public function testUserId(): void
    {
        $this->assertSame(55, new ProcessEntity(['user_id' => 55])->userId());
        $this->assertNull(new ProcessEntity(['user_id' => 'foo'])->userId());
        $this->assertNull(new ProcessEntity([])->userId());
    }

    public function testStatus(): void
    {
        $this->assertSame('running', new ProcessEntity(['status' => 'running'])->status());
        $this->assertSame('', new ProcessEntity(['status' => []])->status());
        $this->assertSame('', new ProcessEntity([])->status());
    }

    public function testActions(): void
    {
        $actions = [
            'db' => ['dump'],
            'files' => ['copy'],
        ];

        $entity = new ProcessEntity(['actions' => $actions]);
        $this->assertSame($actions, $entity->actions());
        $this->assertSame([], new ProcessEntity([])->actions());
    }

    public function testProcessedActions(): void
    {
        $entity = new ProcessEntity(['processed_actions' => ['db', 'files']]);
        $this->assertSame(['db', 'files'], $entity->processedActions());
        $this->assertSame([], new ProcessEntity([])->processedActions());
    }

    public function testActionStates(): void
    {
        $states = [
            'db' => ['ok' => true],
            'files' => ['ok' => false],
        ];

        $entity = new ProcessEntity(['action_states' => $states]);
        $this->assertSame($states, $entity->actionStates());
        $this->assertSame([], new ProcessEntity([])->actionStates());
    }

    public function testWithArtifactSource(): void
    {
        $entity = new ProcessEntity(['id' => 1]);
        $new = $entity->withArtifactSource('my-source');

        $this->assertNotSame($entity, $new);
        $this->assertSame('my-source', $new->artifactSource());
        $this->assertNull($entity->artifactSource());
    }

    public function testArtifacts(): void
    {
        $entity = new ProcessEntity([
            'artifacts' => [
                ['db'],
                ['files'],
            ]
        ]);

        $this->assertSame(['db', 'files'], $entity->artifacts());
        $this->assertSame([], new ProcessEntity([])->artifacts());
    }

    public function testHooks(): void
    {
        $entity = new ProcessEntity([
            'hooks' => [
                ['before'],
                ['after'],
            ]
        ]);

        $this->assertSame(['before', 'after'], $entity->hooks());
        $this->assertSame([], new ProcessEntity([])->hooks());
    }

    public function testMeta(): void
    {
        $this->assertSame(['foo' => 'bar'], new ProcessEntity(['meta' => ['foo' => 'bar']])->meta());
        $this->assertSame([], new ProcessEntity(['meta' => 'invalid'])->meta());
        $this->assertSame([], new ProcessEntity([])->meta());
    }

    public function testRuntimeInSeconds(): void
    {
        $this->assertSame(1.5, new ProcessEntity(['runtime_seconds' => 1.5])->runtimeInSeconds());
        $this->assertSame(0.0, new ProcessEntity(['runtime_seconds' => 'foo'])->runtimeInSeconds());
        $this->assertSame(0.0, new ProcessEntity([])->runtimeInSeconds());
    }

    public function testMemoryPeakBytes(): void
    {
        $this->assertSame(2048, new ProcessEntity(['memory_peak_bytes' => 2048])->memoryPeakBytes());
        $this->assertSame(0, new ProcessEntity(['memory_peak_bytes' => 'foo'])->memoryPeakBytes());
        $this->assertSame(0, new ProcessEntity([])->memoryPeakBytes());
    }

    public function testCreatedAt(): void
    {
        $date = new DateTimeImmutable('2024-01-01 12:00:00');

        $this->assertSame($date, (new ProcessEntity(['created_at' => $date]))->createdAt());
        $this->assertNull(new ProcessEntity(['created_at' => 'foo'])->createdAt());
        $this->assertNull(new ProcessEntity([])->createdAt());
    }

    public function testToArrayRemovesArtifactSource(): void
    {
        $data = [
            'id' => 10,
            'name' => 'Process',
            'runtime_seconds' => 1.2,
        ];

        $entity = new ProcessEntity($data + ['artifact_source' => 'foo']);

        $this->assertSame($data, $entity->toArray());
    }
}