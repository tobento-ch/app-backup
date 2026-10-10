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
use Tobento\App\Backup\Exception\FileNotFoundException;
use Tobento\App\Backup\Exception\BackupException;

class FileNotFoundExceptionTest extends TestCase
{
    public function testException()
    {
        $e = new FileNotFoundException(
            path: '/tmp/data.json',
            message: 'File missing'
        );

        $this->assertInstanceof(BackupException::class, $e);
        $this->assertSame('/tmp/data.json', $e->path());
        $this->assertSame('File missing', $e->getMessage());

        // Re-check with different path and message
        $e = new FileNotFoundException(
            path: '/var/log/app.log',
            message: 'Custom error'
        );

        $this->assertSame('/var/log/app.log', $e->path());
        $this->assertSame('Custom error', $e->getMessage());
    }
}