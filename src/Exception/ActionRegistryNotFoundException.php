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

class ActionRegistryNotFoundException extends BackupException
{
    /**
     * Create a new instance.
     *
     * @param string $registry
     */
    public function __construct(
        protected string $registry,
    ) {
        parent::__construct(sprintf('Action registry %s not found', $registry));
    }
    
    /**
     * Returns the registry.
     *
     * @return string
     */
    public function registry(): string
    {
        return $this->registry;
    }
}