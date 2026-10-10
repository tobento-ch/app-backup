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
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Input\InputInterface;
use function Tobento\App\Translation\trans;

class Databases implements ActionInterface
{
    /**
     * Create a new instance.
     *
     * @param array $tables
     * @param bool $perTableDumpFiles
     * @param string $targetFolder
     * @param int $timeBudgetInSeconds
     */
    final public function __construct(
        protected array $tables = [],
        protected bool $perTableDumpFiles = false,
        protected string $targetFolder = 'databases',
        protected int $timeBudgetInSeconds = 20,
    ) {}
    
    public function tables(): array
    {
        return $this->tables;
    }

    public function perTableDumpFiles(): bool
    {
        return $this->perTableDumpFiles;
    }

    public function targetFolder(): string
    {
        return $this->targetFolder;
    }

    public function timeBudgetInSeconds(): int
    {
        return $this->timeBudgetInSeconds;
    }
    
    /**
     * Unique identifier.
     *
     * @return string
     */
    public function id(): string
    {
        return 'databases';
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

        foreach ($this->createActions($container) as $action) {

            $action->backup($process, $container);

            if (!$process->isActionFinished(action: $action)) {
                return; // stop here, resume next window
            }
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

        foreach ($this->createActions($container) as $action) {
            
            $action->restore($process, $container);

            if (!$process->isActionFinished(action: $action)) {
                return;
            }
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
        foreach ($this->tables as $databaseName => $tables) {

            sort($tables);
            $options = array_combine($tables, $tables);

            $field = new Field\Checkboxes(
                name: 'options.databases.tables.' . $databaseName,
                label: $databaseName,
            )
                ->group(trans('Databases'))
                ->options($options)
                ->selected(array_keys($options));
            
            if (! $this->perTableDumpFiles) {
                $field->disabled();
            }
            
            yield $field;
        }
    }
    
    /**
     * Create a configured restore action using validated CRUD input.
     *
     * @param InputInterface $input
     * @return ActionInterface
     */
    public function configureForRestore(InputInterface $input): ActionInterface
    {
        $tables = $input->get('options.databases.tables', []);
        
        if (! $this->perTableDumpFiles) {
            $tables = $this->tables;
        }
        
        return new static(
            tables: $tables,
            perTableDumpFiles: $this->perTableDumpFiles,
            targetFolder: $this->targetFolder,
            timeBudgetInSeconds: $this->timeBudgetInSeconds,
        );
    }
    
    /**
     * Returns data that can be serialized into the backup manifest.
     *
     * @return array The action's serializable representation.
     */
    public function jsonSerialize(): array
    {
        return [
            'tables' => $this->tables,
            'perTableDumpFiles' => $this->perTableDumpFiles,
            'targetFolder' => $this->targetFolder,
            'timeBudgetInSeconds' => $this->timeBudgetInSeconds,
        ];
    }
    
    /**
     * Create all Database action instances for this Databases registry.
     *
     * @param ContainerInterface $container
     * @return \Generator<Database>
     * @psalm-suppress UnusedParam
     */
    private function createActions(ContainerInterface $container): \Generator
    {
        foreach ($this->tables as $databaseName => $tables) {

            if (empty($tables)) {
                continue;
            }

            if ($this->perTableDumpFiles) {
                foreach ($tables as $table) {
                    yield new Database(
                        id: 'database:'.$databaseName.':'.$table,
                        database: $databaseName,
                        tables: [$table],
                        targetFolder: $this->targetFolder,
                        filepath: $databaseName.'/'.$table,
                        timeBudgetInSeconds: $this->timeBudgetInSeconds,
                    );
                }
            } else {
                yield new Database(
                    id: 'database:'.$databaseName,
                    database: $databaseName,
                    tables: $tables,
                    targetFolder: $this->targetFolder,
                    filepath: $databaseName,
                    timeBudgetInSeconds: $this->timeBudgetInSeconds,
                );                    
            }
        }
    }
}