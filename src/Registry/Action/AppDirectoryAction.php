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
use Tobento\App\AppInterface;
use Tobento\App\Backup\Action;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Exception\ActionCreateException;
use Tobento\App\Backup\Repo\BackupEntityInterface;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Crud\Action\ActionInterface as CrudActionInterface;
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use function Tobento\App\Translation\trans;

class AppDirectoryAction implements ActionRegistryInterface
{
    /**
     * Create a new instance.
     *
     * @param string $name Registry display name.
     * @param string $appId
     * @param string $appDir
     * @param array<array-key, string> $onlyDirs
     * @param array<array-key, string> $exceptDirs
     */
    final public function __construct(
        protected string $name,
        protected string $appId,
        protected string $appDir,
        protected array $onlyDirs = [],
        protected array $exceptDirs = [],
    ) {}
    
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
     * Returns the registry name.
     *
     * @return string
     */
    public function name(): string
    {
        return trans($this->name);
    }
    
    /**
     * Returns the configured fields.
     *
     * @param CrudActionInterface $action
     * @return iterable<FieldInterface>|FieldsInterface
     */
    public function configureFields(CrudActionInterface $action): iterable|FieldsInterface
    {
        yield new Field\Radios(
            name: 'options.app.dir.'.$this->appId.'.'.$this->appDir,
            label: $this->name(),
        )
            ->group(trans('App Directory'))
            ->options(['0' => trans('No'), '1' => trans('Yes')])
            ->selected(value: '0', action: 'create|edit')
            ->displayInline();
    }
    
    /**
     * Create action.
     *
     * @param ContainerInterface $container
     * @param BackupEntityInterface $entity
     * @return ActionInterface
     * @throws ActionCreateException
     */
    public function createAction(ContainerInterface $container, BackupEntityInterface $entity): ActionInterface
    {
        if ($entity->get('options.app.dir.'.$this->appId.'.'.$this->appDir) == '1') {
            
            $runtime = $container->get(BackupRuntimeConfigInterface::class);
            
            return new Action\AppDirectory(
                appId: $this->appId,
                appDir: $this->appDir,
                name: $this->name(),
                onlyDirs: $this->onlyDirs,
                exceptDirs: $this->exceptDirs,
                timeBudgetInSeconds: $runtime->jobTimeBudgetInSeconds(),
            );
        }
        
        return new Action\NullAction(id: $this->id());
    }
}