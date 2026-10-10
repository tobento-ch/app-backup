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
use Tobento\App\Backup\Action;
use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\BackupRuntimeConfig;
use Tobento\App\Backup\Registry\Action\ActionRegistryInterface;
use Tobento\App\Backup\Registry\Action\DatabasesAction;
use Tobento\App\Backup\Test\Factory;
use Tobento\App\Crud\Action\Edit;
use Tobento\App\Crud\Field\Fields;
use Tobento\Apps\AppsInterface;
use Tobento\Service\Database\DatabaseInterface;
use Tobento\Service\Database\Databases;
use Tobento\Service\Database\DatabasesInterface;
use Tobento\Service\Database\PdoDatabaseFactory;

class DatabasesActionTest extends \Tobento\App\Testing\TestCase
{
    protected function setUp(): void
    {
        if (! getenv('TEST_TOBENTO_BACKUP_MYSQL')) {
            $this->markTestSkipped('MySql database tests are disabled');
        }
    }
    
    protected function createDatabase(string $name, array $tables = []): DatabaseInterface
    {
        $port = getenv('TEST_TOBENTO_BACKUP_MYSQL_PORT');
        $port = $port !== false && $port !== '' ? (int)$port : null;
        
        $dbName = 'tobento_backup_test_'.$name;

        $pdo = new \PDO(
            'mysql:host='.getenv('TEST_TOBENTO_BACKUP_MYSQL_HOST').';'.($port ? 'port='.$port.';' : ''),
            getenv('TEST_TOBENTO_BACKUP_MYSQL_USER'),
            getenv('TEST_TOBENTO_BACKUP_MYSQL_PASSWORD'),
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$dbName.'`');
        
        $database = new PdoDatabaseFactory()->createDatabase(name: $name, config: [
            'driver' => 'mysql',
            'host' => getenv('TEST_TOBENTO_BACKUP_MYSQL_HOST'),
            'port' => $port,
            'database' => $dbName,
            'username' => getenv('TEST_TOBENTO_BACKUP_MYSQL_USER'),
            'password' => getenv('TEST_TOBENTO_BACKUP_MYSQL_PASSWORD'),          
        ]);
        
        $tablesResult = $database->pdo()->query('SHOW TABLES');
        $existingTables = $tablesResult->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($existingTables as $existingTable) {
            $database->pdo()->exec('DROP TABLE IF EXISTS `'.$existingTable.'`');
        }
        
        foreach ($tables as $table) {
            $statement = $database->execute(
                statement: sprintf('CREATE TABLE %s (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255))', $table)
            );
        }
        
        return $database;
    }
    
    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        return $app;
    }
    
    public function testThatImplementsInterface()
    {
        $action = new DatabasesAction(name: 'Databases');

        $this->assertInstanceOf(ActionRegistryInterface::class, $action);
    }

    public function testIdMethodReturnsCorrectIdentifier()
    {
        $action = new DatabasesAction(name: 'Databases');

        $this->assertSame('databases', $action->id());
    }

    public function testNameMethodReturnsName()
    {
        $app = $this->bootingApp();
        
        $action = new DatabasesAction(name: 'Databases');

        $this->assertSame('Databases', $action->name());
    }

    public function testConfigureFieldsMethodReturnsFields()
    {
        $this->onCreateApp(function(AppInterface $app) {
            $app->on(DatabasesInterface::class, function (DatabasesInterface $databases) {
                $dbs = new Databases(
                    $this->createDatabase(name: 'mysql', tables: ['foo', 'bar']),
                );
                
                $dbs->register('file', function() use ($databases) {
                    return $databases->get('file');
                });
                
                $dbs->addDefault('storage', 'file');
                
                return $dbs;
            });
        });
        
        $app = $this->bootingApp();
        
        $action = new DatabasesAction(name: 'Databases');
        
        $editAction = new Edit();
        $editAction->setContainer($app->container());

        $fields = $action->configureFields($editAction);
        $fields = Fields::fromIterable($fields);
        
        $this->assertSame(['options.databases.mode', 'options.databases.tables.mysql'], $fields->getNames());
    }
    
    public function testConfigureFieldsMethodCollectsDatabaseTables()
    {
        $this->onCreateApp(function(AppInterface $app) {
            $app->on(DatabasesInterface::class, function (DatabasesInterface $databases) {
                $dbs = new Databases(
                    $this->createDatabase(name: 'mysql', tables: ['foo', 'bar']),
                    $this->createDatabase(name: 'mysql1', tables: ['foo', 'bar']),
                );
                
                $dbs->register('file', function() use ($databases) {
                    return $databases->get('file');
                });
                
                $dbs->addDefault('storage', 'file');
                
                return $dbs;
            });
        });
        
        $app = $this->bootingApp();
        
        $action = new DatabasesAction(name: 'Databases');
        
        $editAction = new Edit();
        $editAction->setContainer($app->container());

        $fields = $action->configureFields($editAction);
        $fields = Fields::fromIterable($fields);
        
        $field = $fields->get('options.databases.tables.mysql');
        $this->assertSame(['bar' => 'bar', 'foo' => 'foo'], $field->getOptions());
        
        $field = $fields->get('options.databases.tables.mysql1');
        $this->assertSame(['bar' => 'bar', 'foo' => 'foo'], $field->getOptions());
    }
    
    public function testConfigureFieldsMethodCollectsDatabaseTablesWithMultipleApps()
    {
        // Boot multiple apps
        $this->onCreateApp(function(AppInterface $app) {
            $app->boot(\Tobento\App\Backup\Test\App\Backend::class);
            $app->boot(\Tobento\App\Backup\Test\App\Frontend::class);
        });

        $app = $this->bootingApp();
        $container = $app->container();

        // Access apps and configure DatabasesInterface per app
        $apps = $app->get(\Tobento\Apps\AppsInterface::class);

        // Backend app
        $backend = $apps->get('backend')->app();
        $backend->on(DatabasesInterface::class, function (DatabasesInterface $databases) {
            $dbs = new Databases(
                $this->createDatabase(name: 'mysql_backend', tables: ['foo', 'bar']),
            );
            
            $dbs->register('file', function() use ($databases) {
                return $databases->get('file');
            });

            $dbs->addDefault('storage', 'file');
            
            return $dbs;
        });

        // Frontend app
        $frontend = $apps->get('frontend')->app();
        $frontend->on(DatabasesInterface::class, function (DatabasesInterface $databases) {
            $dbs = new Databases(
                $this->createDatabase(name: 'mysql_frontend', tables: ['baz', 'qux']),
            );
            
            $dbs->register('file', function() use ($databases) {
                return $databases->get('file');
            });

            $dbs->addDefault('storage', 'file');
            
            return $dbs;
        });

        $action = new DatabasesAction(name: 'Databases');

        $editAction = new Edit();
        $editAction->setContainer($container);

        $fields = $action->configureFields($editAction);
        $fields = Fields::fromIterable($fields);

        $fieldBackend = $fields->get('options.databases.tables.mysql_backend');
        $this->assertSame(['bar' => 'bar', 'foo' => 'foo'], $fieldBackend->getOptions());

        $fieldFrontend = $fields->get('options.databases.tables.mysql_frontend');
        $this->assertSame(['baz' => 'baz', 'qux' => 'qux'], $fieldFrontend->getOptions());
    }
    
    public function testCreateActionMethodReturnsNullActionWhenNoDatabasesSelected()
    {
        $app = $this->bootingApp();
        $container = $app->container();

        $entity = Factory::createBackupEntity([
            'options' => [
                'databases' => [
                    'tables' => []
                ]
            ]
        ]);

        $action = new DatabasesAction(name: 'Databases');

        $result = $action->createAction($container, $entity);

        $this->assertInstanceOf(NullAction::class, $result);
        $this->assertSame('databases', $result->id());
    }

    public function testCreateActionReturnsDatabasesActionWithSelectedDatabases()
    {
        $app = $this->bootingApp();
        $container = $app->container();

        $container->set(
            \Tobento\App\Backup\BackupRuntimeConfigInterface::class,
            new BackupRuntimeConfig(jobTimeBudgetInSeconds: 55)
        );

        $entity = Factory::createBackupEntity([
            'options' => [
                'databases' => [
                    'tables' => [
                        'mysql' => ['foo', 'bar'],
                    ],
                    'mode' => 'partial',
                ]
            ]
        ]);

        $action = new DatabasesAction(name: 'Databases');

        $result = $action->createAction($container, $entity);

        $this->assertInstanceOf(Action\Databases::class, $result);
        $this->assertSame(['mysql' => ['foo', 'bar']], $result->tables());
        $this->assertTrue($result->perTableDumpFiles());
    }
}