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

namespace Tobento\App\Backup\Test\Action;

use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Action\Actions;
use Tobento\App\Backup\Action\ActionsInterface;
use Tobento\App\Backup\Action\NullAction;

class ActionsTest extends TestCase
{
    public function testThatImplementsActionsInterface()
    {
        $this->assertInstanceOf(
            ActionsInterface::class,
            new Actions()
        );
    }

    public function testCreateActions()
    {
        $actions = new Actions(
            new NullAction('a1'),
            new NullAction('a2'),
        );

        $this->assertSame(['a1', 'a2'], $actions->ids());
        $this->assertSame(2, count($actions->all()));
    }

    public function testFilterMethod()
    {
        $actions = new Actions(
            new NullAction('keep'),
            new NullAction('drop'),
        );

        $filtered = $actions->filter(
            fn(ActionInterface $a): bool => $a->id() === 'keep'
        );

        $this->assertNotSame($actions, $filtered);
        $this->assertSame(['keep'], $filtered->ids());
    }

    public function testAllMethod()
    {
        $a = new NullAction('a');
        $b = new NullAction('b');
        
        $actions = new Actions($a, $b);

        $this->assertSame(['a' => $a, 'b' => $b], $actions->all());
        $this->assertSame([], new Actions()->all());
    }
    
    public function testIdsMethod()
    {
        $actions = new Actions(new NullAction('a'), new NullAction('b'));

        $this->assertSame(['a', 'b'], $actions->ids());
        $this->assertSame([], new Actions()->ids());
    }

    public function testHasMethod()
    {
        $actions = new Actions(
            new NullAction('x'),
        );

        $this->assertTrue($actions->has('x'));
        $this->assertFalse($actions->has('y'));
    }

    public function testGetMethod()
    {
        $a1 = new NullAction('a1');
        $actions = new Actions($a1);

        $this->assertSame($a1, $actions->get('a1'));
        $this->assertNull($actions->get('missing'));
    }

    public function testIterator()
    {
        $actions = new Actions(
            new NullAction('a'),
            new NullAction('b'),
        );

        $ids = [];

        foreach ($actions as $id => $action) {
            $ids[] = $id;
        }

        $this->assertSame(['a', 'b'], $ids);
    }

    public function testCountMethod()
    {
        $actions = new Actions(
            new NullAction('a'),
            new NullAction('b'),
        );

        $this->assertSame(2, $actions->count());
    }

    public function testJsonSerialize()
    {
        $actions = new Actions(
            new NullAction('a'),
            new NullAction('b'),
        );

        $json = $actions->jsonSerialize();

        $this->assertSame([
            [
                'class' => NullAction::class,
                'data'  => ['id' => 'a'],
            ],
            [
                'class' => NullAction::class,
                'data'  => ['id' => 'b'],
            ],
        ], $json);
    }
}