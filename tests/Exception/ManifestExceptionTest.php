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
use Tobento\App\Backup\Exception\ManifestException;
use Tobento\App\Backup\Exception\BackupException;

class ManifestExceptionTest extends TestCase
{
    public function testException()
    {
        $e = new ManifestException('Failure');

        $this->assertInstanceof(BackupException::class, $e);
        $this->assertSame('Failure', $e->getMessage());

        $e = new ManifestException('Custom message');
        $this->assertSame('Custom message', $e->getMessage());
    }
}