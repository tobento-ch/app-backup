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
use Tobento\Apps\AppsInterface;
use Tobento\Service\Filesystem\Dir;
use Tobento\Service\Filesystem\Folder;
use function Tobento\App\Translation\trans;

class AppsDirectoryAction implements ActionRegistryInterface
{
    /**
     * Create a new instance.
     *
     * @param string $name Registry display name.
     * @param array $withSubdirs
     * @param array $onlyApps
     * @param array $exceptApps
     */
    final public function __construct(
        protected string $name,
        protected array $withSubdirs = [],
        protected array $onlyApps = [],
        protected array $exceptApps = [],
    ) {}
    
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
        $container = $action->container();
        $appsDirs = $this->getCollectedDirs($container);
        
        foreach($appsDirs as $appId => $dirs) {
            
            $dirs = array_map(function (Folder $folder): string {
                return $folder->folderPath();
            }, $dirs);
            
            yield new Field\Checkboxes(
                name: 'options.apps.dirs.'.$appId,
                label: $appId,
            )
                ->group($this->name())
                ->options(array_combine($dirs, $dirs));
        }
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
        $selectedDirs = $entity->get('options.apps.dirs', []);

        if (empty($selectedDirs)) {
            return new Action\NullAction(id: $this->id());
        }
        
        $runtime = $container->get(BackupRuntimeConfigInterface::class);
        
        return new Action\AppsDirectory(
            dirs: $selectedDirs,
            timeBudgetInSeconds: $runtime->jobTimeBudgetInSeconds(),
        );
    }
    
    protected function getCollectedDirs(ContainerInterface $container): array
    {
        $app = $container->get(AppInterface::class);
        
        if (! $app->has(AppsInterface::class)) {
            return [
                $app->id() => $this->collectFromApp($app),
            ];
        }
        
        $apps = $app->get(AppsInterface::class);
        
        foreach($apps->all() as $appBoot) {
            $appBoot->app()->booting();
        }
        
        $dirs = [];
        
        foreach($apps->all() as $appBoot) {
            $application = $appBoot->app();
            $id = $application->id();
            
            if (!empty($this->onlyApps) && !in_array($id, $this->onlyApps, true)) {
                continue;
            }

            if (in_array($id, $this->exceptApps, true)) {
                continue;
            }
            
            $application->booting();            
            $dirs[$id] = $this->collectFromApp($application);
        }
        
        $apps->bootingApp($app);
        
        return $dirs;
    }
    
    protected function collectFromApp(AppInterface $app): array
    {
        $dir = new Dir();
        $folders = [];
        $rootFolders = $dir->getFolders($app->dir('app'));
        
        foreach($rootFolders as $rootFolder) {
            
            if (in_array($rootFolder->folderPath(), $this->withSubdirs, true)) {
                $subFolders = $dir->getFolders($rootFolder->dir());
                
                foreach($subFolders as $subFolder) {
                    $folders[] = $subFolder->withFolderPath($rootFolder->folderPath().'/'.$subFolder->folderPath());
                }
            } else {
                $folders[] = $rootFolder;
            }
        }
                
        return $folders;
    }
}