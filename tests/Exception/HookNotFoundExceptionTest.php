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

namespace Tobento\App\Backup\Test\Exception;

use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\Exception\HookNotFoundException;
use Tobento\App\Backup\Exception\BackupException;

class HookNotFoundExceptionTest extends TestCase
{
    public function testException()
    {
        $e = new HookNotFoundException(hook: 'notify');

        $this->assertInstanceof(BackupException::class, $e);
        $this->assertSame('notify', $e->hook());
        $this->assertSame('Hook notify not found', $e->getMessage());

        // Re-check with another hook name
        $e = new HookNotFoundException(hook: 'cleanup');
        $this->assertSame('cleanup', $e->hook());
        $this->assertSame('Hook cleanup not found', $e->getMessage());
    }
}