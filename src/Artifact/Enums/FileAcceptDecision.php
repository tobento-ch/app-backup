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

namespace Tobento\App\Backup\Artifact\Enums;

enum FileAcceptDecision
{
    case Yes; // Safe to write now
    case Defer; // Not safe now, but safe in a fresh job
    case NeverFits; // Not safe now, not safe ever
}