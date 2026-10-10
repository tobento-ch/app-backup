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
use Tobento\App\Backup\Process\DeleteProcessInterface;
use Tobento\App\Backup\Process\NullDeleteProcess;

class NullDeleteProcessTest extends TestCase
{
    public function testThatImplementsInterface()
    {
        $this->assertInstanceof(DeleteProcessInterface::class, new NullDeleteProcess());
    }
    
    public function testHasRemainingItemsMethodIsAlwaysFalse(): void
    {
        $process = new NullDeleteProcess();

        $this->assertFalse($process->hasRemainingItems());
    }

    public function testNextItemMethodIsAlwaysNull(): void
    {
        $process = new NullDeleteProcess();

        $this->assertNull($process->nextItem());
    }

    public function testDeleteItemMethodDoesNothing(): void
    {
        $process = new NullDeleteProcess();

        $process->deleteItem('anything');

        $this->assertTrue(true);
    }
}