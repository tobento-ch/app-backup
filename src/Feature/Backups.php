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
 
namespace Tobento\App\Backup\Feature;

use Tobento\App\AppInterface;
use Tobento\App\Boot;
use Tobento\App\Crud\Boot\Crud;
use Tobento\App\Backup\Controller\BackupCrudController;
use Tobento\App\Language\RouteLocalizerInterface;
use Tobento\Service\Acl\AclInterface;
use Tobento\Service\Menu\MenusInterface;
use Tobento\Service\Routing\RouterInterface;
use function Tobento\App\Translation\trans;

class Backups extends Boot
{
    public const INFO = [
        'boot' => [
            'routes backup',
        ],
    ];

    public const BOOT = [
        \Tobento\App\User\Boot\Acl::class,
        \Tobento\App\User\Boot\User::class,
        \Tobento\App\User\Boot\HttpUserErrorHandler::class,
        Crud::class,
    ];
    
    /**
     * Create a new instance.
     *
     * @param null|string $menu The menu name or null if none.
     * @param string $menuLabel The menu label.
     * @param null|string $menuParent The menu parent or null if none.
     * @param bool $withAcl
     */
    public function __construct(
        protected null|string $menu = 'main',
        protected string $menuLabel = 'Backups',
        protected null|string $menuParent = null,
        protected bool $withAcl = true,
    ) {}

    /**
     * Boot application services.
     *
     * @param AppInterface $app
     * @param Crud $crud
     * @return void
     */
    public function boot(AppInterface $app, Crud $crud): void
    {
        $acl = $app->get(AclInterface::class);
        $acl->rule('backups')->description('User can access and manage backups.');
        
        if ($this->withAcl === false) {
            $acl->addPermissions(['backups']);
        }
        
        // Routes:
        $router = $app->get(RouterInterface::class);
        $routeLocalizer = $app->get(RouteLocalizerInterface::class);
        
        $crud->routeController(
            BackupCrudController::class,
            middleware: [
                [
                    \Tobento\App\User\Middleware\VerifyRoutePermission::class,
                    'permissions' => [
                        'backups.index' => 'backups',
                        'backups.create' => 'backups',
                        'backups.store' => 'backups',
                        'backups.edit' => 'backups',
                        'backups.update' => 'backups',
                        'backups.delete' => 'backups',
                        'backups.bulk' => 'backups',
                    ],
                ]
            ],
            except: ['show', 'copy'],
            localized: true,
        );
        
        $backupRoute = $router->post(
            uri: '{?locale}/backups/{id}/backup',
            handler: \Tobento\App\Backup\Controller\BackupAction::class,
        )->name('backups.backup')
         ->middleware(['can', 'permission' => 'backups']);
        
        $routeLocalizer->localizeRoute($backupRoute);
        
        // Menu:
        if ($this->menu) {
            $app->on(
                MenusInterface::class,
                function(MenusInterface $menus, AclInterface $acl, RouterInterface $router) {
                    if ($acl->can('backups')) {
                        $menus->menu($this->menu)
                            ->link($router->url('backups.index'), trans($this->menuLabel))
                            ->parent($this->menuParent)
                            ->id('backups.index');
                    }
                }
            );
        }
    }
}