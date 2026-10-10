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
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Repo\BackupEntityInterface;

class BackupEntityTest extends TestCase
{
    public function testThatImplementsInterface(): void
    {
        $this->assertInstanceOf(BackupEntityInterface::class, new BackupEntity([]));
    }

    public function testId(): void
    {
        $this->assertSame(5, new BackupEntity(['id' => 5])->id());
        $this->assertSame(0, new BackupEntity(['id' => 'foo'])->id());
        $this->assertSame(0, new BackupEntity([])->id());
    }

    public function testName(): void
    {
        $this->assertSame('My Backup', new BackupEntity(['name' => 'My Backup'])->name());
        $this->assertSame('backup', new BackupEntity(['name' => []])->name());
        $this->assertSame('backup', new BackupEntity([])->name());
    }

    public function testAppId(): void
    {
        $this->assertSame('frontend', new BackupEntity(['app_id' => 'frontend'])->appId());
        $this->assertSame('', new BackupEntity(['app_id' => []])->appId());
        $this->assertSame('', new BackupEntity([])->appId());
    }

    public function testUserId(): void
    {
        $this->assertSame(55, new BackupEntity(['user_id' => 55])->userId());
        $this->assertNull(new BackupEntity(['user_id' => 'foo'])->userId());
        $this->assertNull(new BackupEntity([])->userId());
    }

    public function testArtifacts(): void
    {
        $this->assertSame(
            ['db', 'files', 'zip'],
            new BackupEntity([
                'options' => [
                    'artifacts' => [
                        ['db'], ['files'], ['zip']
                    ]
                ]
            ])->artifacts()
        );

        $this->assertSame([], new BackupEntity([])->artifacts());
    }

    public function testHooks(): void
    {
        $this->assertSame(
            ['before.backup', 'after.backup'],
            new BackupEntity([
                'options' => [
                    'hooks' => [
                        ['before.backup'],
                        ['after.backup'],
                    ]
                ]
            ])->hooks()
        );

        $this->assertSame([], new BackupEntity([])->hooks());
    }

    public function testMeta(): void
    {
        $this->assertSame(['foo' => 'bar'], new BackupEntity(['meta' => ['foo' => 'bar']])->meta());
        $this->assertSame([], new BackupEntity(['meta' => 'invalid'])->meta());
        $this->assertSame([], new BackupEntity([])->meta());
    }

    public function testCreatedAt(): void
    {
        $date = new DateTimeImmutable('2024-01-01 12:00:00');

        $this->assertSame($date, new BackupEntity(['created_at' => $date])->createdAt());
        $this->assertNull(new BackupEntity(['created_at' => 'foo'])->createdAt());
        $this->assertNull(new BackupEntity([])->createdAt());
    }

    public function testHas(): void
    {
        $this->assertTrue(new BackupEntity(['foo' => 'bar'])->has('foo'));
        $this->assertFalse(new BackupEntity(['foo' => 'bar'])->has('missing'));
    }

    public function testHasSupportsDotNotation(): void
    {
        $entity = new BackupEntity([
            'options' => [
                'artifacts' => ['db']
            ]
        ]);

        $this->assertTrue($entity->has('options.artifacts'));
        $this->assertFalse($entity->has('options.missing'));
    }

    public function testGet(): void
    {
        $entity = new BackupEntity(['foo' => 'bar']);

        $this->assertSame('bar', $entity->get('foo'));
        $this->assertSame('default', $entity->get('missing', 'default'));
    }

    public function testGetSupportsDotNotation(): void
    {
        $entity = new BackupEntity([
            'options' => [
                'hooks' => ['before', 'after']
            ]
        ]);

        $this->assertSame(['before', 'after'], $entity->get('options.hooks'));
        $this->assertSame('fallback', $entity->get('options.missing', 'fallback'));
    }

    public function testToArray(): void
    {
        $data = [
            'id' => 10,
            'name' => 'Backup',
            'app_id' => 'frontend',
        ];

        $this->assertSame($data, new BackupEntity($data)->toArray());
    }
}