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
use Tobento\App\AppInterface;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Crud\Action\ActionInterface as CrudActionInterface;
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Input\InputInterface;
use Tobento\Apps\AppFinder;
use Tobento\Apps\AppsInterface;
use function Tobento\App\Translation\trans;

class AppsDirectory implements ActionInterface
{
    /**
     * Create a new instance.
     *
     * @param array $dirs
     * @param string $targetFolder
     * @param int $timeBudgetInSeconds
     */
    final public function __construct(
        protected array $dirs = [],
        protected string $targetFolder = 'apps-directory',
        protected int $timeBudgetInSeconds = 20,
    ) {
        if ($this->targetFolder === '') {
            $this->targetFolder = 'apps-directory';
        }

        $this->targetFolder = trim($this->targetFolder, '/'). '/';
    }
    
    public function dirs(): array
    {
        return $this->dirs;
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
        return 'apps.directory';
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
        foreach ($this->dirs as $appId => $dirs) {
            // Skip empty directory groups
            if (empty($dirs)) {
                continue;
            }
            
            // $dirs are plain folder paths coming from the manifest.
            $options = array_combine($dirs, $dirs);

            yield new Field\Checkboxes(
                name: 'options.apps.dirs.' . $appId,
                label: $appId,
            )
                ->group(trans('Apps Directory'))
                ->options($options)
                ->selected(array_keys($options));
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
        return new static(
            dirs: $input->get('options.apps.dirs', []),
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
            'dirs' => $this->dirs,
            'targetFolder' => $this->targetFolder,
            'timeBudgetInSeconds' => $this->timeBudgetInSeconds,
        ];
    }
    
    /**
     * Create all Directory instances for this AppsDirectory.
     *
     * @param ContainerInterface $container
     * @return \Generator<Directory>
     */
    private function createActions(ContainerInterface $container): \Generator
    {
        $app = $container->get(AppInterface::class);
        $finder = new AppFinder(app: $app);
        
        foreach ($this->dirs as $appId => $folders) {

            $appInstance = $finder->findByIdRecursive($appId);
            if ($appInstance === null) {
                continue;
            }
            
            $baseDir = rtrim($appInstance->dir('app'), '/');

            foreach ($folders as $folder) {

                $fullDir = $baseDir . '/' . trim($folder, '/');
                $targetPrefix = $this->targetFolder . $appId . '/' . $folder . '/';

                yield new Directory(
                    id: $targetPrefix,
                    dir: $fullDir,
                    targetFolder: $targetPrefix,
                    timeBudgetInSeconds: $this->timeBudgetInSeconds,
                );
            }
        }
        
        if ($container->has(AppsInterface::class)) {
            $container->get(AppsInterface::class)->bootingApp($app);
        }
    }
}