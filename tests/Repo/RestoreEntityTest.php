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
use Tobento\App\Backup\Repo\RestoreEntity;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\Backup\Test\App\InMemoryArtifactRegistry;

class RestoreEntityTest extends TestCase
{
    private function registry(): InMemoryArtifactRegistry
    {
        return new InMemoryArtifactRegistry();
    }

    public function testThatImplementsInterface(): void
    {
        $reg = $this->registry();

        $this->assertInstanceOf(
            RestoreEntityInterface::class,
            new RestoreEntity('id', 'name', 'type', 'raw', $reg)
        );
    }

    public function testId(): void
    {
        $reg = $this->registry();

        $this->assertSame('abc', new RestoreEntity('abc', 'n', 't', 'r', $reg)->id());
        $this->assertSame('', new RestoreEntity('', 'n', 't', 'r', $reg)->id());
    }

    public function testName(): void
    {
        $reg = $this->registry();

        $this->assertSame('Backup A', new RestoreEntity('id', 'Backup A', 't', 'r', $reg)->name());
        $this->assertSame('', new RestoreEntity('id', '', 't', 'r', $reg)->name());
    }

    public function testType(): void
    {
        $reg = $this->registry();

        $this->assertSame('Zip', new RestoreEntity('id', 'n', 'Zip', 'r', $reg)->type());
        $this->assertSame('', new RestoreEntity('id', 'n', '', 'r', $reg)->type());
    }

    public function testRaw(): void
    {
        $reg = $this->registry();

        $this->assertSame('raw-data', new RestoreEntity('id', 'n', 't', 'raw-data', $reg)->raw());
        $this->assertSame(['x' => 1], new RestoreEntity('id', 'n', 't', ['x' => 1], $reg)->raw());
    }

    public function testRegistry(): void
    {
        $reg = $this->registry();

        $entity = new RestoreEntity('id', 'n', 't', 'r', $reg);

        $this->assertSame($reg, $entity->registry());
    }

    public function testUserId(): void
    {
        $reg = $this->registry();

        $this->assertSame(55, new RestoreEntity('id', 'n', 't', 'r', $reg, 55)->userId());
        $this->assertNull(new RestoreEntity('id', 'n', 't', 'r', $reg, null)->userId());
    }

    public function testDomain(): void
    {
        $reg = $this->registry();

        $this->assertSame('example.com', new RestoreEntity('id', 'n', 't', 'r', $reg, null, 'example.com')->domain());
        $this->assertNull(new RestoreEntity('id', 'n', 't', 'r', $reg, null, null)->domain());
    }

    public function testCreatedAt(): void
    {
        $reg = $this->registry();
        $date = new DateTimeImmutable('2024-01-01 12:00:00');

        $this->assertSame($date, new RestoreEntity('id', 'n', 't', 'r', $reg, null, null, $date)->createdAt());
        $this->assertNull(new RestoreEntity('id', 'n', 't', 'r', $reg, null, null, null)->createdAt());
    }

    public function testToArray(): void
    {
        $reg = $this->registry();
        $date = new DateTimeImmutable('2024-01-01 12:00:00');

        $entity = new RestoreEntity(
            'folder-123',
            'My Backup',
            'Zip',
            'raw-item',
            $reg,
            99,
            'example.com',
            $date
        );

        $this->assertSame([
            'id' => 'folder-123',
            'name' => 'My Backup',
            'type' => 'Zip',
            'user_id' => 99,
            'domain' => 'example.com',
            'created_at' => $date->format(DateTimeImmutable::ATOM),
        ], $entity->toArray());
    }
}