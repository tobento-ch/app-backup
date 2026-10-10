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

namespace Tobento\App\Backup\Test\Registry\Artifact;

use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\Exception\ArtifactRegistryNotFoundException;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistries;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistryInterface;
use Tobento\App\Backup\Test\App\InMemoryArtifactRegistry;
use Tobento\App\Backup\Test\Factory;

class ArtifactRegistriesTest extends TestCase
{
    public function testImplementsArtifactRegistriesInterface()
    {
        $registries = new ArtifactRegistries();

        $this->assertInstanceOf(
            \Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface::class,
            $registries
        );
    }

    public function testAddAndHasMethod()
    {
        $registries = new ArtifactRegistries();

        $regA = new InMemoryArtifactRegistry(id: 'a');
        $regB = new InMemoryArtifactRegistry(id: 'b');

        $registries->add($regA);
        $registries->add($regB);

        $this->assertTrue($registries->has('a'));
        $this->assertTrue($registries->has('b'));
        $this->assertFalse($registries->has('c'));
    }

    public function testGetMethodReturnsRegistry()
    {
        $registries = new ArtifactRegistries();

        $regA = new InMemoryArtifactRegistry(id: 'a');
        $registries->add($regA);

        $this->assertSame($regA, $registries->get('a'));
    }

    public function testGetMethodThrowsExceptionIfNotFound()
    {
        $this->expectException(ArtifactRegistryNotFoundException::class);

        $registries = new ArtifactRegistries();
        $registries->get('missing');
    }

    public function testFirstMethodReturnsFirstRegistry()
    {
        $registries = new ArtifactRegistries();

        $regA = new InMemoryArtifactRegistry(id: 'a');
        $regB = new InMemoryArtifactRegistry(id: 'b');

        $registries->add($regA);
        $registries->add($regB);

        $this->assertSame($regA, $registries->first());
    }

    public function testFirstMethodReturnsNullIfEmpty()
    {
        $registries = new ArtifactRegistries();
        $this->assertNull($registries->first());
    }

    public function testFirstIdMethodReturnsFirstRegistryId()
    {
        $registries = new ArtifactRegistries();

        $registries->add(new InMemoryArtifactRegistry(id: 'a'));
        $registries->add(new InMemoryArtifactRegistry(id: 'b'));

        $this->assertSame('a', $registries->firstId());
    }

    public function testFirstIdMethodReturnsNullIfEmpty()
    {
        $registries = new ArtifactRegistries();
        $this->assertNull($registries->firstId());
    }

    public function testFilterMethodReturnsNewInstanceWithFilteredRegistries()
    {
        $registries = new ArtifactRegistries();

        $regA = new InMemoryArtifactRegistry(id: 'a');
        $regB = new InMemoryArtifactRegistry(id: 'b');
        $regC = new InMemoryArtifactRegistry(id: 'c');

        $registries->add($regA);
        $registries->add($regB);
        $registries->add($regC);

        $filtered = $registries->filter(fn(ArtifactRegistryInterface $r) => $r->id() !== 'b');

        $this->assertNotSame($registries, $filtered);

        // Original unchanged
        $this->assertTrue($registries->has('a'));
        $this->assertTrue($registries->has('b'));
        $this->assertTrue($registries->has('c'));

        // Filtered instance
        $this->assertTrue($filtered->has('a'));
        $this->assertFalse($filtered->has('b'));
        $this->assertTrue($filtered->has('c'));
    }

    public function testNamesMethodReturnsRegistryNamesIndexedById()
    {
        $registries = new ArtifactRegistries();

        $regA = new InMemoryArtifactRegistry(id: 'a', name: 'Artifact A');
        $regB = new InMemoryArtifactRegistry(id: 'b', name: 'Artifact B');

        $registries->add($regA);
        $registries->add($regB);

        $this->assertSame([
            'a' => 'Artifact A',
            'b' => 'Artifact B',
        ], $registries->names());
    }

    public function testAllMethodReturnsAllRegistries()
    {
        $registries = new ArtifactRegistries();

        $regA = new InMemoryArtifactRegistry(id: 'a');
        $regB = new InMemoryArtifactRegistry(id: 'b');

        $registries->add($regA);
        $registries->add($regB);

        $this->assertSame([
            'a' => $regA,
            'b' => $regB,
        ], $registries->all());
    }

    public function testIteratorMethodReturnsTraversable()
    {
        $registries = new ArtifactRegistries();

        $regA = new InMemoryArtifactRegistry(id: 'a');
        $regB = new InMemoryArtifactRegistry(id: 'b');

        $registries->add($regA);
        $registries->add($regB);

        $iterated = [];

        foreach ($registries as $id => $registry) {
            $iterated[$id] = $registry;
        }

        $this->assertSame([
            'a' => $regA,
            'b' => $regB,
        ], $iterated);
    }
}