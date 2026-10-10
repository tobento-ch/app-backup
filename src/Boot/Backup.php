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
 
namespace Tobento\App\Backup\Boot;

use Tobento\App\AppInterface;
use Tobento\App\Boot;
use Tobento\App\Boot\Config;
use Tobento\App\Migration\Boot\Migration;
use Tobento\App\Backup\Hook;
use Tobento\App\Backup\Registry\Action\ActionRegistries;
use Tobento\App\Backup\Registry\Action\ActionRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistries;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;

class Backup extends Boot
{
    public const INFO = [
        'boot' => [
            'installs and loads backup config',
            'implements the required interfaces',
            'boots features',
        ],
    ];

    public const BOOT = [
        Config::class,
        Migration::class,
        \Tobento\App\Database\Boot\Database::class,
        \Tobento\App\Translation\Boot\Translation::class,
        \Tobento\App\Queue\Boot\Queue::class,
        \Tobento\App\Notifier\Boot\Notifier::class,
        \Tobento\App\Encryption\Boot\Encryption::class,
    ];

    /**
     * Boot application services.
     *
     * @param Config $config
     * @param Migration $migration
     * @return void
     */
    public function boot(
        Config $config,
        Migration $migration,
    ): void {
        // Migration:
        $migration->install(\Tobento\App\Backup\Migration\Backup::class);
        
        // Load the config:
        $config = $config->load('backup.php');
        
        // Interfaces:
        foreach($config['interfaces'] ?? [] as $interface => $implementation) {
            $this->app->set($interface, $implementation);
        }

        $this->app->set(ArtifactRegistriesInterface::class, static function () use ($config) {
            $registries = new ArtifactRegistries();
            
            foreach($config['artifacts'] ?? [] as $registry) {
                $registries->add(registry: $registry);
            }
            
            return $registries;
        });
        
        $this->app->set(ActionRegistriesInterface::class, static function () use ($config) {
            $registries = new ActionRegistries();
            
            foreach($config['actions'] ?? [] as $registry) {
                $registries->add(registry: $registry);
            }
            
            return $registries;
        });
        
        $this->app->set(Hook\HooksInterface::class, static function () use ($config) {
            $hooks = new Hook\Hooks();
            
            foreach($config['hooks'] ?? [] as $hook) {
                $hooks->add(hook: $hook);
            }
            
            return $hooks;
        });
        
        // Migrate repositories:
        $migration->install(\Tobento\App\Backup\Migration\Repositories::class);
        
        // Features:
        foreach($config['features'] ?? [] as $feature) {
            if (is_string($feature)) {
                $feature = $this->app->make($feature);
            }
            
            $this->app->boot($feature);
        }
    }
}