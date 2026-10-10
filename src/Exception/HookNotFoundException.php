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
 
namespace Tobento\App\Backup\Exception;

class HookNotFoundException extends BackupException
{
    /**
     * Create a new HookNotFoundException.
     *
     * @param string $hook
     */
    public function __construct(
        protected string $hook,
    ) {
        parent::__construct(sprintf('Hook %s not found', $hook));
    }
    
    /**
     * Returns the hook.
     *
     * @return string
     */
    public function hook(): string
    {
        return $this->hook;
    }
}