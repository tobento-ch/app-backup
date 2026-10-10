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

namespace Tobento\App\Translation;

if (!function_exists(__NAMESPACE__.'\trans')) {
    function trans(string $message, array $parameters = [], null|string $locale = null): string {
        return $message;
    }
}