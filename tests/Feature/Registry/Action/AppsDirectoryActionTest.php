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

namespace Tobento\App\Backup\Test\Feature\Registry\Action;

use Tobento\App\AppInterface;
use Tobento\App\Backup\Action\AppsDirectory;
use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\BackupRuntimeConfig;
use Tobento\App\Backup\Registry\Action\ActionRegistryInterface;
use Tobento\App\Backup\Registry\Action\AppsDirectoryAction;
use Tobento\App\Backup\Test\Factory;
use Tobento\App\Crud\Action\Edit;
use Tobento\App\Crud\Field\Fields;
use Tobento\Apps\AppsInterface;

class AppsDirectoryActionTest extends \Tobento\App\Testing\TestCase
{
    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        return $app;
    }
    
    public function testThatImplementsInterface()
    {
        $action = new AppsDirectoryAction(
            name: 'Apps Dir'
        );

        $this->assertInstanceOf(ActionRegistryInterface::class, $action);
    }

    public function testIdMethodReturnsCorrectIdentifier()
    {
        $action = new AppsDirectoryAction(
            name: 'Apps Dir'
        );

        $this->assertSame('apps.directory', $action->id());
    }

    public function testNameMethodReturnsName()
    {
        $app = $this->bootingApp();
        
        $action = new AppsDirectoryAction(
            name: 'Apps Dir'
        );

        $this->assertSame('Apps Dir', $action->name());
    }
    
    public function testConfigureFieldsMethodCollectsAppDirectories()
    {
        $app = $this->bootingApp();
        
        $action = new AppsDirectoryAction(
            name: 'Apps Dir'
        );
        
        $editAction = new Edit();
        $editAction->setContainer($app->container());

        $fields = $action->configureFields($editAction);
        $fields = Fields::fromIterable($fields);
        
        $this->assertSame(['options.apps.dirs.root'], $fields->getNames());
        
        $field = $fields->get('options.apps.dirs.root');
        
        $options = $field->getOptions();
        unset($options['apps']); // Filter out "apps" because its on first test run not created
        
        $this->assertSame([
            'config' => 'config',
            'countries' => 'countries',
            'migrations' => 'migrations',
            'public' => 'public',
            'storage' => 'storage',
            'trans' => 'trans',
            'views' => 'views',
        ], $options);
    }
    
    public function testConfigureFieldsMethodOnlyAppsFilterIsIgnoredWithoutAppsInterface()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectoryAction(
            name: 'Apps Dir',
            onlyApps: ['appA']
        );

        $editAction = new Edit();
        $editAction->setContainer($app->container());

        $fields = $action->configureFields($editAction);
        $fields = Fields::fromIterable($fields);

        // Filtering is ignored because AppsInterface is not present
        $this->assertSame(['options.apps.dirs.root'], $fields->getNames());
    }
    
    public function testConfigureFieldsMethodOnlyAppsFilterWorksWithMultipleApps()
    {
        // Boot multiple apps
        $this->onCreateApp(function(AppInterface $app) {
            $app->boot(\Tobento\App\Backup\Test\App\Backend::class);
            $app->boot(\Tobento\App\Backup\Test\App\Frontend::class);
        });
        
        $app = $this->bootingApp();

        $action = new AppsDirectoryAction(
            name: 'Apps Dir',
            onlyApps: ['frontend']
        );

        $editAction = new Edit();
        $editAction->setContainer($app->container());
        
        $fields = $action->configureFields($editAction);
        $fields = Fields::fromIterable($fields);

        $this->assertSame(['options.apps.dirs.frontend'], $fields->getNames());
    }

    public function testConfigureFieldsMethodExceptAppsFilterWorksWithMultipleApps()
    {
        // Boot multiple apps
        $this->onCreateApp(function(AppInterface $app) {
            $app->boot(\Tobento\App\Backup\Test\App\Backend::class);
            $app->boot(\Tobento\App\Backup\Test\App\Frontend::class);
        });
        
        $app = $this->bootingApp();
        $container = $app->container();

        $action = new AppsDirectoryAction(
            name: 'Apps Dir',
            exceptApps: ['frontend']
        );

        $editAction = new Edit();
        $editAction->setContainer($app->container());
        
        $fields = $action->configureFields($editAction);
        $fields = Fields::fromIterable($fields);

        $this->assertSame(['options.apps.dirs.backend'], $fields->getNames());
    }

    public function testConfigureFieldsMethodWithSubdirsCollectsSubdirectories()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectoryAction(
            name: 'Apps Dir',
            withSubdirs: ['storage'],
        );

        $editAction = new Edit();
        $editAction->setContainer($app->container());

        $fields = $action->configureFields($editAction);
        $fields = Fields::fromIterable($fields);

        $this->assertSame(['options.apps.dirs.root'], $fields->getNames());

        $field = $fields->get('options.apps.dirs.root');
        $options = $field->getOptions();

        // Expected minimum subdirectories
        $expected = [
            'storage/database',
            'storage/html-sanitizer',
            'storage/uploads-private',
        ];

        // Assert that all expected entries exist
        foreach ($expected as $dir) {
            $this->assertArrayHasKey($dir, $options);
        }

        // Assert that all returned storage entries start with "storage/"
        foreach ($options as $key => $value) {
            if (str_starts_with($key, 'storage/')) {
                $this->assertTrue(
                    is_string($value),
                    "Storage entry '$key' must map to a string"
                );
            }
        }
    }

    public function testCreateActionMethodReturnsNullActionWhenNoDirsSelected()
    {
        $app = $this->bootingApp();
        $container = $app->container();

        $entity = Factory::createBackupEntity([
            'options' => [
                'apps' => [
                    'dirs' => []
                ]
            ]
        ]);

        $action = new AppsDirectoryAction(name: 'Apps Dir');

        $result = $action->createAction($container, $entity);

        $this->assertInstanceOf(NullAction::class, $result);
        $this->assertSame('apps.directory', $result->id());
    }

    public function testCreateActionReturnsAppsDirectoryActionWithSelectedDirs()
    {
        $app = $this->bootingApp();
        $container = $app->container();

        $container->set(
            \Tobento\App\Backup\BackupRuntimeConfigInterface::class,
            new BackupRuntimeConfig(jobTimeBudgetInSeconds: 55)
        );

        $entity = Factory::createBackupEntity([
            'options' => [
                'apps' => [
                    'dirs' => [
                        'appA' => ['dirA', 'dirB'],
                        'appB' => ['dirC']
                    ]
                ]
            ]
        ]);

        $action = new AppsDirectoryAction(name: 'Apps Dir');

        $result = $action->createAction($container, $entity);

        $this->assertInstanceOf(AppsDirectory::class, $result);
        $this->assertSame([
            'appA' => ['dirA', 'dirB'],
            'appB' => ['dirC'],
        ], $result->dirs());
        $this->assertSame(55, $result->timeBudgetInSeconds());
    }
}