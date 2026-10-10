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
use Tobento\App\Backup\Controller\ProcessCrudController;
use Tobento\Service\Acl\AclInterface;
use Tobento\Service\Menu\MenusInterface;
use Tobento\Service\Routing\RouterInterface;
use function Tobento\App\Translation\trans;

class Processes extends Boot
{
    public const INFO = [
        'boot' => [
            'routes processes',
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
        protected string $menuLabel = 'Backup/Restore Processes',
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
        $acl->rule('backups.processes')->description('User can access and manage processes.');
        
        if ($this->withAcl === false) {
            $acl->addPermissions(['backups.processes']);
        }

        // Routes:
        $crud->routeController(
            ProcessCrudController::class,
            middleware: [
                [
                    \Tobento\App\User\Middleware\VerifyRoutePermission::class,
                    'permissions' => [
                        'backups-processes.index' => 'backups.processes',
                        'backups-processes.show' => 'backups.processes',
                        'backups-processes.delete' => 'backups.processes',
                        'backups-processes.bulk' => 'backups.processes',
                    ],
                ]
            ],
            except: ['copy', 'create', 'store', 'edit', 'update'],
            localized: true,
        );
        
        // Menu:
        if ($this->menu) {
            $app->on(
                MenusInterface::class,
                function(MenusInterface $menus, AclInterface $acl, RouterInterface $router) {
                    if ($acl->can('backups.processes')) {
                        $menus->menu($this->menu)
                            ->link($router->url('backups-processes.index'), trans($this->menuLabel))
                            ->parent($this->menuParent)
                            ->id('backups-processes.index');
                    }
                }
            );
        }
    }
}