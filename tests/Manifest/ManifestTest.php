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

namespace Tobento\App\Backup\Test\Manifest;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\Manifest\Manifest;
use Tobento\App\Backup\Manifest\ManifestInterface;

class ManifestTest extends TestCase
{
    public function testThatImplementsManifestInterface()
    {
        $manifest = new Manifest(
            processId: 1,
            createdAt: new DateTimeImmutable(),
            actions: []
        );

        $this->assertInstanceOf(ManifestInterface::class, $manifest);
    }
    
    public function testManifestStoresValuesCorrectly()
    {
        $createdAt = new DateTimeImmutable('2024-01-01T12:00:00+00:00');

        $manifest = new Manifest(
            processId: 123,
            createdAt: $createdAt,
            actions: ['a1', 'a2'],
            name: 'My Backup',
            userId: 99,
            domain: 'example.com',
            meta: ['foo' => 'bar']
        );

        $this->assertSame(123, $manifest->processId());
        $this->assertSame($createdAt, $manifest->createdAt());
        $this->assertSame(['a1', 'a2'], $manifest->actions());
        $this->assertSame('My Backup', $manifest->name());
        $this->assertSame(99, $manifest->userId());
        $this->assertSame('example.com', $manifest->domain());
        $this->assertSame(['foo' => 'bar'], $manifest->meta());
    }

    public function testToArrayReturnsCorrectStructure()
    {
        $createdAt = new DateTimeImmutable('2024-01-01T12:00:00+00:00');

        $manifest = new Manifest(
            processId: 1,
            createdAt: $createdAt,
            actions: ['x'],
            name: null,
            userId: null,
            domain: null,
            meta: []
        );

        $array = $manifest->toArray();

        $this->assertSame(1, $array['process_id']);
        $this->assertSame($createdAt->format('c'), $array['created_at']);
        $this->assertSame(['x'], $array['actions']);
        $this->assertNull($array['name']);
        $this->assertNull($array['user_id']);
        $this->assertNull($array['domain']);
        $this->assertSame([], $array['meta']);
    }

    public function testToJsonReturnsPrettyPrintedJson()
    {
        $createdAt = new DateTimeImmutable('2024-01-01T12:00:00+00:00');

        $manifest = new Manifest(
            processId: 5,
            createdAt: $createdAt,
            actions: ['a'],
            name: 'Backup',
            userId: 'u1',
            domain: 'domain.test',
            meta: ['k' => 'v']
        );

        $json = $manifest->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame(5, $decoded['process_id']);
        $this->assertSame($createdAt->format('c'), $decoded['created_at']);
        $this->assertSame(['a'], $decoded['actions']);
        $this->assertSame('Backup', $decoded['name']);
        $this->assertSame('u1', $decoded['user_id']);
        $this->assertSame('domain.test', $decoded['domain']);
        $this->assertSame(['k' => 'v'], $decoded['meta']);
    }
}