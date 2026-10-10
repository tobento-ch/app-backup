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

namespace Tobento\App\Backup\Action;

use Psr\Container\ContainerInterface;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Crud\Action\ActionInterface as CrudActionInterface;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Input\InputInterface;

class NullAction implements ActionInterface
{
    /**
     * Create a new instance.
     *
     * @param string $id
     */
    final public function __construct(
        protected string $id,
    ) {}
    
    /**
     * Unique identifier.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Perform the backup for this action.
     *
     * @param BackupProcessInterface $process Backup process context.
     * @param ContainerInterface $container
     * @return void
     */
    public function backup(BackupProcessInterface $process, ContainerInterface $container): void
    {
        if ($process->isActionFinished(action: $this)) {
            return;
        }

        $process->markActionFinished(action: $this);
    }

    /**
     * Restore the backup for this action.
     *
     * @param RestoreProcessInterface $process Restore process context.
     * @param ContainerInterface $container
     * @return void
     */
    public function restore(RestoreProcessInterface $process, ContainerInterface $container): void
    {
        if ($process->isActionFinished(action: $this)) {
            return;
        }

        $process->markActionFinished(action: $this);
    }
    
    /**
     * Configure dynamic restore fields for this action.
     *
     * These fields are shown in the Restore CRUD action and allow the user
     * to configure restore‑time options based on the actual backed‑up data
     * stored in the manifest (e.g. tables in a SQL dump, directories in an
     * apps backup, file lists, flags, etc.).
     *
     * @param CrudActionInterface $action
     * @return iterable<FieldInterface>|FieldsInterface
     */
    public function configureRestoreFields(CrudActionInterface $action): iterable|FieldsInterface
    {
        return [];
    }
    
    /**
     * Create a configured restore action using validated CRUD input.
     *
     * @param InputInterface $input
     * @return ActionInterface
     */
    public function configureForRestore(InputInterface $input): ActionInterface
    {
        return new NullAction($this->id());
    }
    
    /**
     * Returns data that can be serialized into the backup manifest.
     *
     * @return array The action's serializable representation.
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id(),
        ];
    }
}