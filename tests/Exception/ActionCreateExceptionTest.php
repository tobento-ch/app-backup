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
use Tobento\App\Backup\Exception\ActionCreateException;
use Tobento\App\Backup\Exception\BackupException;

class ActionCreateExceptionTest extends TestCase
{
    public function testException()
    {
        $e = new ActionCreateException();
        
        $this->assertInstanceof(BackupException::class, $e);
    }
}