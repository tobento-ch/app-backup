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

namespace Tobento\App\Backup\Registry\Action;

use Psr\Container\ContainerInterface;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Exception\ActionCreateException;
use Tobento\App\Backup\Repo\BackupEntityInterface;
use Tobento\App\Crud\Action\ActionInterface as CrudActionInterface;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\FieldsInterface;

interface ActionRegistryInterface
{
    /**
     * Unique identifier.
     *
     * @return string
     */
    public function id(): string;
    
    /**
     * Returns the registry name.
     *
     * @return string
     */
    public function name(): string;
    
    /**
     * Returns the configured fields.
     *
     * @param CrudActionInterface $action
     * @return iterable<FieldInterface>|FieldsInterface
     */
    public function configureFields(CrudActionInterface $action): iterable|FieldsInterface;
    
    /**
     * Create action.
     *
     * @param ContainerInterface $container
     * @param BackupEntityInterface $entity
     * @return ActionInterface
     * @throws ActionCreateException
     */
    public function createAction(ContainerInterface $container, BackupEntityInterface $entity): ActionInterface;
}