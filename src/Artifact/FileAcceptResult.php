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

namespace Tobento\App\Backup\Artifact;

use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;

final class FileAcceptResult implements FileAcceptResultInterface
{
    public function __construct(
        private FileAcceptDecision $decision,
        private string $reason = '',
        private bool $throw = false,
        private bool $skip = false,
    ) {}

    public function decision(): FileAcceptDecision
    {
        return $this->decision;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function shouldThrow(): bool
    {
        return $this->throw;
    }

    public function shouldSkip(): bool
    {
        return $this->skip;
    }
}