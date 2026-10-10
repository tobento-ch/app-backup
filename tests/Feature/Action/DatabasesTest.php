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

namespace Tobento\App\Backup\Test\Feature\Action;

use Tobento\App\AppInterface;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Action\Database;
use Tobento\App\Backup\Action\Databases;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Artifact\FileAcceptResult;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Process\RestoreProcessFactoryInterface;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Backup\Repo\RestoreEntity;
use Tobento\App\Backup\Test\App;
use Tobento\App\Crud\Action\Edit;
use Tobento\App\Crud\Field\Fields;
use Tobento\App\Crud\Input\Input;
use Tobento\App\Testing\Database\RefreshDatabases;
use Tobento\Service\Database\Dumper\DatabaseDumperInterface;
use Tobento\Service\Database\Dumper\DatabaseDumpersInterface;
use Tobento\Service\Database\Dumper\LazyDatabaseDumpers;
use Tobento\Service\Database\Dumper\MySqlDatabaseDumper;
use Tobento\Service\Database\DatabaseInterface;
use Tobento\Service\Database\Databases as ServiceDatabases;
use Tobento\Service\Database\DatabasesInterface;
use Tobento\Service\Database\PdoDatabaseFactory;

class DatabasesTest extends \Tobento\App\Testing\TestCase
{
    use RefreshDatabases;
    use App\Traits\InMemoryArtifactHelpers;
    
    protected function setUp(): void
    {
        if (! getenv('TEST_TOBENTO_BACKUP_MYSQL')) {
            $this->markTestSkipped('MySql database tests are disabled');
        }

        parent::setUp();
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
            $database->execute(
                statement: sprintf('CREATE TABLE %s (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255))', $table)
            );
        }

        return $database;
    }
    
    protected function registerTestDatabase(): DatabaseInterface
    {
        $database = $this->createDatabase(name: 'mysql', tables: ['foo']);
        
        $this->onCreateApp(function(AppInterface $app) use ($database) {
            $app->on(DatabasesInterface::class, function(DatabasesInterface $databases) use ($database) {
                $dbs = new ServiceDatabases($database);
                
                $dbs->register('file', function() use ($databases) {
                    return $databases->get('file');
                });
                
                $dbs->addDefault('storage', 'file');
                
                return $dbs;
            });
            
            $app->on(DatabaseDumpersInterface::class, function(DatabaseDumpersInterface $dumpers) use ($app, $database) {
                $port = getenv('TEST_TOBENTO_BACKUP_MYSQL_PORT');
                $port = $port !== false && $port !== '' ? (int)$port : null; 
                
                return new LazyDatabaseDumpers(
                    container: $app->container(),
                    dumpers: [
                        'mysql' => new MySqlDatabaseDumper(
                            name: 'mysql',
                            database: 'tobento_backup_test_mysql',
                            host: getenv('TEST_TOBENTO_BACKUP_MYSQL_HOST'),
                            user: getenv('TEST_TOBENTO_BACKUP_MYSQL_USER'),
                            password: getenv('TEST_TOBENTO_BACKUP_MYSQL_PASSWORD'),
                            port: $port,
                            socket: getenv('TEST_TOBENTO_BACKUP_MYSQL_SOCKET'),
                        ),
                    ],
                );
            });
        });

        return $database;
    }

    protected function createBackupProcess(AppInterface $app): BackupProcessInterface
    {
        $backupEntity = new BackupEntity([
            'id' => 1,
            'name' => 'Test Backup',
            'user_id' => 0,
            'created_at' => time(),
            'options' => [
                'artifacts' => ['inmemory'],
                'hooks' => [],
            ],
        ]);

        $factory = $app->get(BackupProcessFactoryInterface::class);

        return $factory->createProcess(
            backupEntity: $backupEntity,
            processId: null,
            userId: null,
        );
    }

    protected function createRestoreProcess(AppInterface $app): RestoreProcessInterface
    {
        $processRepository = $app->get(ProcessRepositoryInterface::class);

        $processEntity = $processRepository->create([
            'type' => 'restore',
            'type_id' => 1,
            'actions' => [],
            'processed_actions' => [],
            'action_states' => [],
            'hooks' => [],
            'status' => 'pending',
            'name' => 'Test Restore',
            'user_id' => null,
            'meta' => [],
        ]);

        $restoreEntity = new RestoreEntity(
            id: (string)$processEntity->id(),
            name: 'Test Restore',
            type: 'inmemory',
            raw: 'dummy-source',
            registry: new App\InMemoryArtifactRegistry(),
        );

        $factory = $app->get(RestoreProcessFactoryInterface::class);

        return $factory->createProcess(
            restoreEntity: $restoreEntity,
            processId: $processEntity->id(),
        );
    }
    
    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        $app->on(
            ArtifactRegistriesInterface::class,
            static function(ArtifactRegistriesInterface $registries): void {
                $registries->add(new App\InMemoryArtifactRegistry());
            }
        );

        return $app;
    }
    
    public function testImplementsActionInterface()
    {
        $action = new Databases(['mysql' => ['foo']]);
        $this->assertInstanceOf(\Tobento\App\Backup\Action\ActionInterface::class, $action);
    }

    public function testGetterMethods()
    {
        $action = new Databases(
            ['mysql' => ['foo', 'bar']],
            true,
            'db',
            33
        );

        $this->assertSame(['mysql' => ['foo', 'bar']], $action->tables());
        $this->assertTrue($action->perTableDumpFiles());
        $this->assertSame('db', $action->targetFolder());
        $this->assertSame(33, $action->timeBudgetInSeconds());
    }

    public function testIdMethod()
    {
        $action = new Databases(['mysql' => ['foo']]);
        $this->assertSame('databases', $action->id());
    }

    public function testCreateActionsWithoutPerTableDumpFiles()
    {
        $action = new Databases(['mysql' => ['foo', 'bar']], false, 'db');
        $container = $this->bootingApp()->container();

        $ref = new \ReflectionClass($action);
        $method = $ref->getMethod('createActions');
        $method->setAccessible(true);

        $actions = iterator_to_array($method->invoke($action, $container));

        $this->assertCount(1, $actions);

        $dbAction = $actions[0];

        $this->assertSame('database:mysql', $dbAction->id());
        $this->assertSame(['foo', 'bar'], $dbAction->tables());
        $this->assertSame('db/', $dbAction->targetFolder());
        $this->assertSame('mysql', $dbAction->filepath());
    }

    public function testCreateActionsWithPerTableDumpFiles()
    {
        $action = new Databases(['mysql' => ['foo', 'bar']], true, 'db');
        $container = $this->bootingApp()->container();

        $ref = new \ReflectionClass($action);
        $method = $ref->getMethod('createActions');
        $method->setAccessible(true);

        $actions = iterator_to_array($method->invoke($action, $container));

        $this->assertCount(2, $actions);

        $this->assertSame('database:mysql:foo', $actions[0]->id());
        $this->assertSame(['foo'], $actions[0]->tables());
        $this->assertSame('mysql/foo', $actions[0]->filepath());

        $this->assertSame('database:mysql:bar', $actions[1]->id());
        $this->assertSame(['bar'], $actions[1]->tables());
        $this->assertSame('mysql/bar', $actions[1]->filepath());
    }

    public function testBackupDelegatesToSubActions()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice')");

        $action = new Databases(['mysql' => ['foo']], false, 'db');
        $process = $this->createBackupProcess($app);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        $dumper = $app->get(DatabaseDumpersInterface::class)->get('mysql');
        $expectedPath = 'db/mysql.' . $dumper->fileExtension();

        $artifact = $this->findWritableInMemoryArtifact($process);
        $this->assertArrayHasKey($expectedPath, $artifact->writtenFiles());
    }

    public function testBackupStopsIfSubActionNotFinished()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice')");

        $action = new Databases(['mysql' => ['foo']], false, 'db');
        $process = $this->createBackupProcess($app);

        $artifact = $this->findWritableInMemoryArtifact($process);
        $artifact->forceCanWriteFileResult(new FileAcceptResult(
            FileAcceptDecision::Defer,
            'forced defer',
            false,
            false
        ));

        $action->backup($process, $app->container());

        $this->assertFalse($process->isActionFinished($action));
    }

    public function testRestoreDelegatesToSubActions()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        // Backup
        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice')");
        $backupAction = new Databases(['mysql' => ['foo']], false, 'db');
        $backupProcess = $this->createBackupProcess($app);
        $backupAction->backup($backupProcess, $app->container());

        $dumper = $app->get(DatabaseDumpersInterface::class)->get('mysql');
        $path = 'db/mysql.' . $dumper->fileExtension();
        $dumpContent = $this->findWritableInMemoryArtifact($backupProcess)->writtenFiles()[$path];

        // Wipe
        $database->pdo()->exec('TRUNCATE TABLE foo');

        // Restore
        $restoreAction = new Databases(['mysql' => ['foo']], false, 'db');
        $restoreProcess = $this->createRestoreProcess($app);

        $artifact = $this->findReadableInMemoryArtifact($restoreProcess);
        $artifact->seedFile($path, $dumpContent);

        $restoreAction->restore($restoreProcess, $app->container());

        $this->assertTrue($restoreProcess->isActionFinished($restoreAction));

        $names = $database->pdo()
            ->query('SELECT name FROM foo ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame(['alice'], $names);
    }

    public function testConfigureRestoreFields()
    {
        $action = new Databases(['mysql' => ['foo', 'bar']], true, 'db');

        $fields = Fields::fromIterable(
            $action->configureRestoreFields(new Edit())
        );

        $this->assertSame(
            ['options.databases.tables.mysql'],
            $fields->getNames()
        );

        $field = $fields->get('options.databases.tables.mysql');

        $this->assertSame(['bar', 'foo'], array_keys($field->getOptions()));
    }

    public function testConfigureRestoreFieldsDisabledWhenPerTableDumpFilesFalse()
    {
        $action = new Databases(['mysql' => ['foo']], false, 'db');

        $fields = Fields::fromIterable(
            $action->configureRestoreFields(new Edit())
        );

        $field = $fields->get('options.databases.tables.mysql');

        $this->assertTrue($field->isDisabled());
    }

    public function testConfigureForRestore()
    {
        $action = new Databases(['mysql' => ['foo', 'bar']], true, 'db');

        $input = new Input([
            'options' => [
                'databases' => [
                    'tables' => [
                        'mysql' => ['bar']
                    ]
                ]
            ]
        ]);

        $configured = $action->configureForRestore($input);

        $this->assertSame(['mysql' => ['bar']], $configured->tables());
    }

    public function testConfigureForRestoreIgnoresInputWhenPerTableDumpFilesFalse()
    {
        $action = new Databases(['mysql' => ['foo', 'bar']], false, 'db');

        $input = new Input([
            'options' => [
                'databases' => [
                    'tables' => [
                        'mysql' => ['bar']
                    ]
                ]
            ]
        ]);

        $configured = $action->configureForRestore($input);

        $this->assertSame(['mysql' => ['foo', 'bar']], $configured->tables());
    }

    public function testJsonSerialize()
    {
        $action = new Databases(['mysql' => ['foo']], true, 'db', 99);

        $this->assertSame(
            [
                'tables' => ['mysql' => ['foo']],
                'perTableDumpFiles' => true,
                'targetFolder' => 'db',
                'timeBudgetInSeconds' => 99,
            ],
            $action->jsonSerialize()
        );
    }
}