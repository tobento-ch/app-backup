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
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
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

class AppDirectory implements ActionInterface
{
    /**
     * Create a new instance.
     *
     * @param string $appId
     * @param string $appDir
     * @param string $name
     * @param array<array-key, string> $onlyDirs
     * @param array<array-key, string> $exceptDirs
     * @param int $timeBudgetInSeconds
     */
    final public function __construct(
        protected string $appId,
        protected string $appDir,
        protected string $name,
        protected array $onlyDirs = [],
        protected array $exceptDirs = [],
        protected int $timeBudgetInSeconds = 20,
    ) {}
    
    public function appId(): string
    {
        return $this->appId;
    }

    public function appDir(): string
    {
        return $this->appDir;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function onlyDirs(): array
    {
        return $this->onlyDirs;
    }

    public function exceptDirs(): array
    {
        return $this->exceptDirs;
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
        return 'app.dir.'.$this->appId.'.'.$this->appDir;
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
        
        $action = $this->createAction($container);

        if (is_null($action)) {
            $process->markActionFinished(action: $this);
            return;
        }

        $action->backup($process, $container);

        if (!$process->isActionFinished(action: $action)) {
            return; // stop here, resume next window
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
        
        $action = $this->createAction($container);

        if (is_null($action)) {
            $process->markActionFinished(action: $this);
            return;
        }
            
        $action->restore($process, $container);

        if (!$process->isActionFinished(action: $action)) {
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
        yield new Field\Radios(
            name: 'options.app.dir.'.$this->appId.'.'.$this->appDir,
            label: $this->name,
        )
            ->group(trans('App Directory'))
            ->options(['0' => trans('No'), '1' => trans('Yes')])
            ->selected(value: '0', action: 'create|edit')
            ->displayInline();
    }
    
    /**
     * Create a configured restore action using validated CRUD input.
     *
     * @param InputInterface $input
     * @return ActionInterface
     */
    public function configureForRestore(InputInterface $input): ActionInterface
    {
        if ($input->get('options.app.dir.'.$this->appId.'.'.$this->appDir) == '1') {
            return new static(
                appId: $this->appId,
                appDir: $this->appDir,
                name: $this->name,
                onlyDirs: $this->onlyDirs,
                exceptDirs: $this->exceptDirs,
                timeBudgetInSeconds: $this->timeBudgetInSeconds,
            );            
        }
        
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
            'appId' => $this->appId,
            'appDir' => $this->appDir,
            'name' => $this->name,
            'onlyDirs' => $this->onlyDirs,
            'exceptDirs' => $this->exceptDirs,
            'timeBudgetInSeconds' => $this->timeBudgetInSeconds,
        ];
    }
    
    /**
     * Create DirectoryAction instance for this AppDirectory.
     *
     * @param ContainerInterface $container
     * @return null|Directory
     */
    private function createAction(ContainerInterface $container): null|Directory
    {
        $app = $container->get(AppInterface::class);
        $finder = new AppFinder(app: $app);

        $appInstance = $finder->findByIdRecursive($this->appId);
        if ($appInstance === null) {
            return null;
        }

        $fullDir = $appInstance->dir($this->appDir); // this will throw if not exists
        
        $targetPrefix = 'app-dir/' . $this->appId . '/' . $this->appDir . '/';

        if ($container->has(AppsInterface::class)) {
            $container->get(AppsInterface::class)->bootingApp($app);
        }
        
        return new Directory(
            id: $this->id(),
            dir: $fullDir,
            targetFolder: $targetPrefix,
            onlyDirs: $this->onlyDirs,
            exceptDirs: $this->exceptDirs,
            timeBudgetInSeconds: $this->timeBudgetInSeconds,
        );
    }
}