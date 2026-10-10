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

namespace Tobento\App\Backup\Test\App\Traits;

use RuntimeException;
use Tobento\App\Backup\Artifact\ArtifactAdapterInterface;
use Tobento\App\Backup\Artifact\WritableArtifacts;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Backup\Test\App\ReadableInMemoryArtifact;
use Tobento\App\Backup\Test\App\WritableInMemoryArtifact;

trait InMemoryArtifactHelpers
{
    protected function findWritableInMemoryArtifact(BackupProcessInterface $process): WritableInMemoryArtifact
    {
        $artifact = $this->unwrapArtifact($process->artifact());

        if ($artifact instanceof WriteableInMemoryArtifact) {
            return $artifact;
        }

        if ($artifact instanceof WritableArtifacts) {
            foreach ($artifact->artifacts() as $inner) {
                $inner = $this->unwrapArtifact($inner);

                if ($inner instanceof WritableInMemoryArtifact) {
                    return $inner;
                }
            }
        }

        throw new RuntimeException(sprintf(
            'No WriteableInMemoryArtifact found in process artifact (got %s).',
            $artifact::class,
        ));
    }

    protected function findReadableInMemoryArtifact(RestoreProcessInterface $process): ReadableInMemoryArtifact
    {
        $artifact = $this->unwrapArtifact($process->artifact());

        if ($artifact instanceof ReadableInMemoryArtifact) {
            return $artifact;
        }

        throw new RuntimeException(sprintf(
            'No ReadableInMemoryArtifact found in process artifact (got %s).',
            $artifact::class,
        ));
    }

    private function unwrapArtifact(mixed $artifact): mixed
    {
        while ($artifact instanceof ArtifactAdapterInterface) {
            $artifact = $artifact->adapted();
        }

        return $artifact;
    }
}