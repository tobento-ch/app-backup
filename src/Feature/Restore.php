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
use Tobento\App\Backup\Controller\RestoreCrudController;
use Tobento\Service\Acl\AclInterface;
use Tobento\Service\Menu\MenusInterface;
use Tobento\Service\Routing\RouterInterface;
use function Tobento\App\Translation\trans;

class Restore extends Boot
{
    public const INFO = [
        'boot' => [
            'routes restore',
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
        protected string $menuLabel = 'Restore Backups',
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

        $acl->rule('backups.restore')->description('User can access and manage restore backups.');
        
        if ($this->withAcl === false) {
            $acl->addPermissions(['backups.restore']);
        }

        // Routes:
        $crud->routeController(
            RestoreCrudController::class,
            middleware: [
                [
                    \Tobento\App\User\Middleware\VerifyRoutePermission::class,
                    'permissions' => [
                        'backups-restore.index' => 'backups.restore',
                        'backups-restore.delete' => 'backups.restore',
                        'backups-restore.bulk' => 'backups.restore',
                    ],
                ]
            ],
            whereId: '[\pL\pN _\-%\.]+',
            except: ['show', 'edit', 'update', 'create', 'store'],
            localized: true,
        );
        
        $dynamicRoute = $crud->routeDynamicAction(
            controller: RestoreCrudController::class,
            localized: true,
            whereId: '[\pL\pN _\-%\.]+',
        );
        
        $dynamicRoute->middleware(['can', 'permission' => 'backups.restore']);
        
        // Menu:
        if ($this->menu) {
            $app->on(
                MenusInterface::class,
                function(MenusInterface $menus, AclInterface $acl, RouterInterface $router) {
                    if ($acl->can('backups.restore')) {
                        $menus->menu($this->menu)
                            ->link($router->url('backups-restore.index'), trans($this->menuLabel))
                            ->parent($this->menuParent)
                            ->id('backups.restore.index');
                    }
                }
            );
        }
    }
}