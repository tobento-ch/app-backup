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

namespace Tobento\App\Backup\Test\Registry\Action;

use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\Registry\Action\ActionRegistryInterface;
use Tobento\App\Backup\Registry\Action\AppDirectoryAction;
use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\Action\AppDirectory;
use Tobento\App\Backup\BackupRuntimeConfig;
use Tobento\App\Backup\Test\Factory;
use Tobento\App\Crud\Action\Edit;
use Tobento\App\Crud\Field\Fields;

require __DIR__.'../../../function-trans.php';

class AppDirectoryActionTest extends TestCase
{
    public function testThatImplementsInterface()
    {
        $action = new AppDirectoryAction(
            name: 'MyDir',
            appId: 'appA',
            appDir: 'config'
        );

        $this->assertInstanceof(ActionRegistryInterface::class, $action);
    }
    
    public function testIdMethodReturnsCorrectIdentifier()
    {
        $action = new AppDirectoryAction(
            name: 'MyDir',
            appId: 'appA',
            appDir: 'config'
        );

        $this->assertSame('app.dir.appA.config', $action->id());
    }

    public function testNameMethodReturnsName()
    {
        $action = new AppDirectoryAction(
            name: 'MyDir',
            appId: 'appA',
            appDir: 'config'
        );

        $this->assertSame('MyDir', $action->name());
    }

    public function testConfigureFieldsMethodReturnsFields()
    {
        $action = new AppDirectoryAction(
            name: 'MyDir',
            appId: 'appA',
            appDir: 'config'
        );

        $fields = $action->configureFields(new Edit());
        $fields = Fields::fromIterable($fields);
        
        $this->assertSame(['options.app.dir.appA.config'], $fields->getNames());
    }

    public function testCreateActionMethodReturnsNullActionWhenOptionIsZero()
    {
        $entity = Factory::createBackupEntity([
            'options' => [
                'app' => [
                    'dir' => [
                        'appA' => ['config' => '0']
                    ]
                ]
            ]
        ]);

        $action = new AppDirectoryAction(
            name: 'MyDir',
            appId: 'appA',
            appDir: 'config'
        );

        $container = Factory::createContainer();

        $result = $action->createAction($container, $entity);

        $this->assertInstanceOf(NullAction::class, $result);
        $this->assertSame('app.dir.appA.config', $result->id());
    }

    public function testCreateActionMethodReturnsAppDirectoryActionWhenOptionIsOne()
    {
        $entity = Factory::createBackupEntity([
            'options' => [
                'app' => [
                    'dir' => [
                        'appA' => ['config' => '1']
                    ]
                ]
            ]
        ]);

        $action = new AppDirectoryAction(
            name: 'MyDir',
            appId: 'appA',
            appDir: 'config',
            onlyDirs: ['foo'],
            exceptDirs: ['bar'],
        );

        $container = Factory::createContainer();
        $container->set(
            \Tobento\App\Backup\BackupRuntimeConfigInterface::class,
            new BackupRuntimeConfig(jobTimeBudgetInSeconds: 99)
        );

        $result = $action->createAction($container, $entity);

        $this->assertInstanceOf(AppDirectory::class, $result);
        $this->assertSame('appA', $result->appId());
        $this->assertSame('config', $result->appDir());
        $this->assertSame('MyDir', $result->name());
        $this->assertSame(['foo'], $result->onlyDirs());
        $this->assertSame(['bar'], $result->exceptDirs());
        $this->assertSame(99, $result->timeBudgetInSeconds());
    }
}