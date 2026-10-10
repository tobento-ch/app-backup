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
use Tobento\App\Backup\Exception\ArtifactRegistryNotFoundException;
use Tobento\App\Backup\Exception\BackupException;

class ArtifactRegistryNotFoundExceptionTest extends TestCase
{
    public function testException()
    {
        $e = new ArtifactRegistryNotFoundException(registry: 'zip');

        $this->assertInstanceof(BackupException::class, $e);
        $this->assertSame('zip', $e->registry());
        $this->assertSame('Artifact registry zip not found', $e->getMessage());

        // Re-check message consistency
        $e = new ArtifactRegistryNotFoundException(registry: 'images');
        $this->assertSame('Artifact registry images not found', $e->getMessage());
    }
}