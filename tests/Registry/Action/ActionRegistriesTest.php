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

namespace Tobento\App\Backup\Test\Registry\Action;

use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\Exception\ActionRegistryNotFoundException;
use Tobento\App\Backup\Registry\Action\ActionRegistries;
use Tobento\App\Backup\Registry\Action\ActionRegistryInterface;
use Tobento\App\Backup\Test\Factory;

class ActionRegistriesTest extends TestCase
{
    public function testImplementsActionRegistriesInterface()
    {
        $registries = new ActionRegistries();

        $this->assertInstanceOf(
            \Tobento\App\Backup\Registry\Action\ActionRegistriesInterface::class,
            $registries
        );
    }
    
    public function testAddAndHasMethod()
    {
        $registries = new ActionRegistries();

        $regA = Factory::createActionRegistry(id: 'a');
        $regB = Factory::createActionRegistry(id: 'b');

        $registries->add($regA);
        $registries->add($regB);

        $this->assertTrue($registries->has('a'));
        $this->assertTrue($registries->has('b'));
        $this->assertFalse($registries->has('c'));
    }

    public function testGetMethodReturnsRegistry()
    {
        $registries = new ActionRegistries();

        $regA = Factory::createActionRegistry(id: 'a');
        $registries->add($regA);

        $this->assertSame($regA, $registries->get('a'));
    }

    public function testGetMethodThrowsExceptionIfNotFound()
    {
        $this->expectException(ActionRegistryNotFoundException::class);

        $registries = new ActionRegistries();
        $registries->get('missing');
    }

    public function testFirstMethodReturnsFirstRegistry()
    {
        $registries = new ActionRegistries();

        $regA = Factory::createActionRegistry(id: 'a');
        $regB = Factory::createActionRegistry(id: 'b');

        $registries->add($regA);
        $registries->add($regB);

        $this->assertSame($regA, $registries->first());
    }

    public function testFirstMethodReturnsNullIfEmpty()
    {
        $registries = new ActionRegistries();
        $this->assertNull($registries->first());
    }

    public function testFirstIdMethodReturnsFirstRegistryId()
    {
        $registries = new ActionRegistries();

        $registries->add(Factory::createActionRegistry(id: 'a'));
        $registries->add(Factory::createActionRegistry(id: 'b'));

        $this->assertSame('a', $registries->firstId());
    }

    public function testFirstIdMethodReturnsNullIfEmpty()
    {
        $registries = new ActionRegistries();
        $this->assertNull($registries->firstId());
    }

    public function testFilterMethodReturnsNewInstanceWithFilteredRegistries()
    {
        $registries = new ActionRegistries();

        $regA = Factory::createActionRegistry(id: 'a');
        $regB = Factory::createActionRegistry(id: 'b');
        $regC = Factory::createActionRegistry(id: 'c');

        $registries->add($regA);
        $registries->add($regB);
        $registries->add($regC);

        $filtered = $registries->filter(fn(ActionRegistryInterface $r) => $r->id() !== 'b');
        
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
        $registries = new ActionRegistries();

        $regA = Factory::createActionRegistry(id: 'a', name: 'Action A');
        $regB = Factory::createActionRegistry(id: 'b', name: 'Action B');

        $registries->add($regA);
        $registries->add($regB);

        $this->assertSame([
            'a' => 'Action A',
            'b' => 'Action B',
        ], $registries->names());
    }

    public function testAllMethodReturnsAllRegistries()
    {
        $registries = new ActionRegistries();

        $regA = Factory::createActionRegistry(id: 'a');
        $regB = Factory::createActionRegistry(id: 'b');

        $registries->add($regA);
        $registries->add($regB);

        $this->assertSame([
            'a' => $regA,
            'b' => $regB,
        ], $registries->all());
    }

    public function testIteratorMethodReturnsTraversable()
    {
        $registries = new ActionRegistries();

        $regA = Factory::createActionRegistry(id: 'a');
        $regB = Factory::createActionRegistry(id: 'b');

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