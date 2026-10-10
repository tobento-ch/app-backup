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
use Tobento\App\Backup\Exception\ActionRegistryNotFoundException;
use Tobento\App\Backup\Exception\BackupException;

class ActionRegistryNotFoundExceptionTest extends TestCase
{
    public function testException()
    {
        $e = new ActionRegistryNotFoundException(registry: 'zip');

        $this->assertInstanceof(BackupException::class, $e);
        $this->assertSame('zip', $e->registry());
        $this->assertSame('Action registry zip not found', $e->getMessage());

        $e = new ActionRegistryNotFoundException(registry: 'zip');
        $this->assertSame('Action registry zip not found', $e->getMessage());
    }
}
