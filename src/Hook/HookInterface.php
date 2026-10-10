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
 
namespace Tobento\App\Backup\Hook;

use Psr\Container\ContainerInterface;

interface HookInterface
{
    /**
     * Unique identifier.
     *
     * @return string
     */
    public function id(): string;
    
    /**
     * Returns the hook name.
     *
     * @return string
     */
    public function name(): string;
    
    /**
     * Returns the hook group.
     *
     * @return string
     */
    public function group(): string;
    
    /**
     * Whether the hook should be selected by default in the UI.
     *
     * @return bool
     */
    public function defaultSelected(): bool;
    
    /**
     * Whether this hook applies to backup processes.
     *
     * @return bool
     */
    public function supportsBackup(): bool;

    /**
     * Whether this hook applies to restore processes.
     *
     * @return bool
     */
    public function supportsRestore(): bool;
    
    /**
     * Create hook handler.
     *
     * @param ContainerInterface $container
     * @return HookHandlerInterface
     */
    public function createHookHandler(ContainerInterface $container): HookHandlerInterface;
}