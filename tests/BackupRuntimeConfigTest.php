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

namespace Tobento\App\Backup\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\BackupRuntimeConfig;
use Tobento\App\Backup\BackupRuntimeConfigInterface;

class BackupRuntimeConfigTest extends TestCase
{
    public function testImplementsInterface()
    {
        $this->assertInstanceOf(
            BackupRuntimeConfigInterface::class,
            new BackupRuntimeConfig()
        );
    }

    public function testQueueName()
    {
        $this->assertNull(new BackupRuntimeConfig()->queueName());
        $this->assertSame('backup', new BackupRuntimeConfig('backup')->queueName());
    }

    public function testJobTimeBudgetInSeconds()
    {
        $this->assertSame(20, new BackupRuntimeConfig()->jobTimeBudgetInSeconds());
        $this->assertSame(30, new BackupRuntimeConfig(null, 30)->jobTimeBudgetInSeconds());
    }

    public function testJobTimeBudgetInSecondsThrowsIfBelow10()
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupRuntimeConfig(null, 9);
    }

    public function testEncrypter()
    {
        $this->assertSame('default', new BackupRuntimeConfig()->encrypter());
        $this->assertSame('fast', new BackupRuntimeConfig(null, 20, 'fast')->encrypter());
    }

    public function testEncryptThroughputInBytes()
    {
        $this->assertSame(50_000_000, new BackupRuntimeConfig()->encryptThroughputInBytes());
        $this->assertSame(123_456, new BackupRuntimeConfig(null, 20, 'default', 123_456)->encryptThroughputInBytes());
    }
}