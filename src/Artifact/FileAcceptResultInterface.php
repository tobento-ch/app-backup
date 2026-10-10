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

/**
 * Represents the result of checking whether a file
 * can be accepted for reading or writing by an artifact.
 *
 * This result provides:
 * - The accept decision (Yes, Defer, NeverFits)
 * - A human‑readable reason for the decision
 * - Whether the action should throw immediately
 * - Whether the file should be skipped entirely
 *
 * Typical usage:
 *   $result = $artifact->canWriteFile(...);
 *
 *   if ($result->shouldThrow()) { ... }
 *   if ($result->shouldSkip())  { ... }
 *   switch ($result->decision()) { ... }
 */
interface FileAcceptResultInterface
{
    /**
     * Returns the accept decision for the file.
     *
     * @return FileAcceptDecision
     */
    public function decision(): FileAcceptDecision;

    /**
     * Returns a human‑readable explanation of the decision.
     * May be empty if no explanation is needed.
     *
     * @return string
     */
    public function reason(): string;

    /**
     * Indicates whether the caller should throw an exception
     * immediately instead of continuing the backup process.
     *
     * @return bool
     */
    public function shouldThrow(): bool;

    /**
     * Indicates whether the file should be skipped entirely
     * and optionally logged or written to the manifest.
     *
     * @return bool
     */
    public function shouldSkip(): bool;
}