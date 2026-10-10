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
 
namespace Tobento\App\Backup\Test\App;

use Tobento\Apps\AppBoot;

class Backend extends AppBoot
{
    public const INFO = [
        'boot' => [
            'Backend Area',
        ],
    ];
    
    /**
     * Specify your app boots:
     */
    protected const APP_BOOT = [
        \Tobento\App\Boot\App::class,
        \Tobento\App\Backup\Boot\Backup::class,
    ];
    
    public const APP_ID = 'backend';
    
    protected const SLUG = 'admin';
    
    // You may set a domain for the routing e.g. api.example.com
    // In addition, you may the slug to an empty string,
    // otherwise it gets appended e.g. api.example.com/slug
    protected const DOMAIN = '';
    
    // You may set a migration to be installed on booting e.g Migration::class
    protected const MIGRATION = '';
    //protected const MIGRATION = \Tobento\App\Backend\Migration\Backend::class;
}