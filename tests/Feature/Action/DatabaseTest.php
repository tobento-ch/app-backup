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

use InvalidArgumentException;
use Tobento\App\AppInterface;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Action\Database;
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
use Tobento\Service\Database\Databases;
use Tobento\Service\Database\DatabasesInterface;
use Tobento\Service\Database\PdoDatabaseFactory;

class DatabaseTest extends \Tobento\App\Testing\TestCase
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

    protected function registerTestDatabase(): DatabaseInterface
    {
        $database = $this->createDatabase(name: 'mysql', tables: ['foo']);
        
        $this->onCreateApp(function(AppInterface $app) use ($database) {
            $app->on(DatabasesInterface::class, function(DatabasesInterface $databases) use ($database) {
                $dbs = new Databases($database);
                
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

    public function testImplementsActionInterface()
    {
        $this->registerTestDatabase();
        $app = $this->bootingApp();

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $this->assertInstanceOf(ActionInterface::class, $action);
    }

    public function testIdMethod()
    {
        $this->registerTestDatabase();
        $app = $this->bootingApp();

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $this->assertSame('db-action', $action->id());
    }
    
    public function testGetterMethods()
    {
        $action = new Database(
            'database:mysql',
            'mysql',
            ['foo', 'bar'],
            'db',
            'mysql/foo',
            33
        );

        $this->assertSame('mysql', $action->database());
        $this->assertSame(['foo', 'bar'], $action->tables());
        $this->assertSame('db/', $action->targetFolder());
        $this->assertSame('mysql/foo', $action->filepath());
        $this->assertSame(33, $action->timeBudgetInSeconds());
    }

    public function testConstructorThrowsWhenTablesEmpty()
    {
        $this->registerTestDatabase();
        $this->bootingApp();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No tables specified.');

        new Database(
            id: 'db-action',
            database: 'mysql',
            tables: [],
            targetFolder: 'db',
            filepath: '',
        );
    }

    public function testConstructorThrowsWhenTargetFolderEmpty()
    {
        $this->registerTestDatabase();
        $this->bootingApp();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target folder cannot be an empty string.');

        new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: '',
            filepath: '',
        );
    }

    public function testBackupMethodWritesDumpToArtifactAndMarksFinished()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice'), ('bob')");

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $process = $this->createBackupProcess($app);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        $dumper = $app->get(DatabaseDumpersInterface::class)->get('mysql');
        $expectedPath = 'db/mysql.'.$dumper->fileExtension();

        $artifact = $this->findWritableInMemoryArtifact($process);
        $written = $artifact->writtenFiles();
        
        $this->assertArrayHasKey($expectedPath, $written);
        $this->assertNotSame('', $written[$expectedPath]);
    }

    public function testBackupMethodSkipsIfAlreadyFinished()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice')");

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $process = $this->createBackupProcess($app);
        $process->markActionFinished(action: $action);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        // Early-return proof: nothing was written, even though real data exists.
        $artifact = $this->findWritableInMemoryArtifact($process);
        $this->assertEmpty($artifact->writtenFiles());
    }

    public function testBackupMethodDoesNotMarkFinishedWhenArtifactDefers()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice')");

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $process = $this->createBackupProcess($app);

        $artifact = $this->findWritableInMemoryArtifact($process);
        $artifact->forceCanWriteFileResult(new FileAcceptResult(
            FileAcceptDecision::Defer,
            reason: 'forced defer',
            throw: false,
            skip: false,
        ));

        $action->backup($process, $app->container());

        $this->assertFalse($process->isActionFinished($action));
        $this->assertEmpty($artifact->writtenFiles());
    }

    public function testBackupThenRestoreRoundTripsData()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice'), ('bob')");

        // --- Backup ---
        $backupAction = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $backupProcess = $this->createBackupProcess($app);

        $backupAction->backup($backupProcess, $app->container());

        $this->assertTrue($backupProcess->isActionFinished($backupAction));

        $dumper = $app->get(DatabaseDumpersInterface::class)->get('mysql');
        $expectedPath = 'db/mysql.'.$dumper->fileExtension();

        $writable = $this->findWritableInMemoryArtifact($backupProcess);
        $written = $writable->writtenFiles();

        $this->assertArrayHasKey($expectedPath, $written);
        $dumpContent = $written[$expectedPath];

        // --- Wipe the table so restore has something real to prove ---
        $database->pdo()->exec('TRUNCATE TABLE foo');
        $this->assertSame(
            [],
            $database->pdo()->query('SELECT name FROM foo')->fetchAll(\PDO::FETCH_COLUMN)
        );

        // --- Restore ---
        $restoreAction = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $restoreProcess = $this->createRestoreProcess($app);

        $readable = $this->findReadableInMemoryArtifact($restoreProcess);
        $readable->seedFile($expectedPath, $dumpContent);

        $restoreAction->restore($restoreProcess, $app->container());

        $this->assertTrue($restoreProcess->isActionFinished($restoreAction));

        $names = $database->pdo()
            ->query('SELECT name FROM foo ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame(['alice', 'bob'], $names);
    }

    public function testBackupMethodThrowsWhenFilesizeFails()
    {
        $database = $this->registerTestDatabase();

        $app = $this->getApp();
        // Replace dumper with a fake one that deletes the temp file AFTER writing
        $app->on(DatabaseDumpersInterface::class, function() {
            return new class implements DatabaseDumpersInterface {
                public function has(string $name): bool { return true; }
                public function names(): array { return []; }
                public function get(string $name): DatabaseDumperInterface
                {
                    return new class implements DatabaseDumperInterface {
                        public function name(): string {}
                        public function dump(array $tables, $destination, null|float $timeoutSeconds = null): void
                        {
                            // Write something
                            fwrite($destination, "dummy");
                            fflush($destination);

                            // Get the temp file path
                            $meta = stream_get_meta_data($destination);
                            $tmpFile = $meta['uri'];

                            // Delete AFTER dump, BEFORE filesize()
                            @unlink($tmpFile);
                        }
                        public function restore($source, null|float $timeoutSeconds = null): void {}
                        public function fileExtension(): string
                        {
                            return 'sql';
                        }
                    };
                }
            };
        });

        $app = $this->bootingApp();

        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice')");

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $process = $this->createBackupProcess($app);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to determine dump file size.');

        $action->backup($process, $app->container());
    }

    public function testBackupMethodThrowsWhenArtifactShouldThrow()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        // Insert some data so dumper produces output
        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice')");

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $process = $this->createBackupProcess($app);

        // Force artifact to return shouldThrow() = true
        $artifact = $this->findWritableInMemoryArtifact($process);
        $artifact->forceCanWriteFileResult(new FileAcceptResult(
            FileAcceptDecision::Yes,
            'forced throw',
            true,   // shouldThrow
            false   // shouldSkip
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forced throw');

        $action->backup($process, $app->container());

        // After exception:
        // - Action must NOT be finished
        // - No file must be written
        $this->assertFalse($process->isActionFinished($action));
        $this->assertSame([], $artifact->writtenFiles());
    }

    public function testBackupMethodSkipsWhenArtifactShouldSkip()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        // Insert some data so dumper produces output
        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice')");

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $process = $this->createBackupProcess($app);

        // Force artifact to return shouldSkip() = true
        $artifact = $this->findWritableInMemoryArtifact($process);
        $artifact->forceCanWriteFileResult(new FileAcceptResult(
            FileAcceptDecision::Yes,
            'forced skip',
            false,  // shouldThrow
            true    // shouldSkip
        ));

        // Execute backup
        $action->backup($process, $app->container());

        // Action must NOT be finished
        $this->assertFalse($process->isActionFinished($action));

        // No file must be written
        $this->assertSame([], $artifact->writtenFiles());

        // Skip entry must be present in state
        $state = $process->stateFor($action);

        $this->assertArrayHasKey('skipped', $state);
        $this->assertCount(1, $state['skipped']);

        $entry = $state['skipped'][0];

        $this->assertSame('mysql', $entry['database']);
        $this->assertSame('forced skip', $entry['reason']);
        $this->assertArrayHasKey('size', $entry);
        $this->assertArrayHasKey('timestamp', $entry);
    }

    public function testRestoreMethodSkipsIfAlreadyFinished()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $process = $this->createRestoreProcess($app);
        $process->markActionFinished(action: $action);

        $dumper = $app->get(DatabaseDumpersInterface::class)->get('mysql');
        $path = 'db/mysql.'.$dumper->fileExtension();

        $artifact = $this->findReadableInMemoryArtifact($process);
        // Even if a dump were present, the early-return must prevent it
        // from ever being read/imported.
        $artifact->seedFile($path, 'not-a-real-dump');

        $action->restore($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        $names = $database->pdo()
            ->query('SELECT name FROM foo')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame([], $names);
    }

    public function testRestoreMethodDoesNotMarkFinishedWhenArtifactDefers()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice')");

        $backupAction = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $backupProcess = $this->createBackupProcess($app);
        $backupAction->backup($backupProcess, $app->container());

        $dumper = $app->get(DatabaseDumpersInterface::class)->get('mysql');
        $path = 'db/mysql.'.$dumper->fileExtension();
        $dumpContent = $this->findWritableInMemoryArtifact($backupProcess)->writtenFiles()[$path];

        $database->pdo()->exec('TRUNCATE TABLE foo');

        $restoreAction = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $restoreProcess = $this->createRestoreProcess($app);

        $artifact = $this->findReadableInMemoryArtifact($restoreProcess);
        $artifact->seedFile($path, $dumpContent);
        $artifact->forceCanReadFileResult(new FileAcceptResult(
            FileAcceptDecision::Defer,
            reason: 'forced defer',
            throw: false,
            skip: false,
        ));

        $restoreAction->restore($restoreProcess, $app->container());

        $this->assertFalse($restoreProcess->isActionFinished($restoreAction));

        $names = $database->pdo()
            ->query('SELECT name FROM foo')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame([], $names);
    }

    public function testRestoreMethodThrowsWhenArtifactShouldThrow()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        // Prepare backup dump
        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice')");
        $backupAction = new Database('db-action', 'mysql', ['foo'], 'db', '');
        $backupProcess = $this->createBackupProcess($app);
        $backupAction->backup($backupProcess, $app->container());

        $dumper = $app->get(DatabaseDumpersInterface::class)->get('mysql');
        $path = 'db/mysql.'.$dumper->fileExtension();
        $dumpContent = $this->findWritableInMemoryArtifact($backupProcess)->writtenFiles()[$path];

        // Wipe table
        $database->pdo()->exec('TRUNCATE TABLE foo');

        // Prepare restore
        $restoreAction = new Database('db-action', 'mysql', ['foo'], 'db', '');
        $restoreProcess = $this->createRestoreProcess($app);

        $artifact = $this->findReadableInMemoryArtifact($restoreProcess);
        $artifact->seedFile($path, $dumpContent);

        // Force shouldThrow()
        $artifact->forceCanReadFileResult(new FileAcceptResult(
            FileAcceptDecision::Yes,
            'forced throw',
            true, // shouldThrow
            false // shouldSkip
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forced throw');

        $restoreAction->restore($restoreProcess, $app->container());

        // After exception:
        $this->assertFalse($restoreProcess->isActionFinished($restoreAction));

        $names = $database->pdo()
            ->query('SELECT name FROM foo')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame([], $names);
    }

    public function testRestoreMethodSkipsWhenArtifactShouldSkip()
    {
        $database = $this->registerTestDatabase();
        $app = $this->bootingApp();

        // Prepare backup dump
        $database->pdo()->exec("INSERT INTO foo (name) VALUES ('alice')");
        $backupAction = new Database('db-action', 'mysql', ['foo'], 'db', '');
        $backupProcess = $this->createBackupProcess($app);
        $backupAction->backup($backupProcess, $app->container());

        $dumper = $app->get(DatabaseDumpersInterface::class)->get('mysql');
        $path = 'db/mysql.'.$dumper->fileExtension();
        $dumpContent = $this->findWritableInMemoryArtifact($backupProcess)->writtenFiles()[$path];

        // Wipe table
        $database->pdo()->exec('TRUNCATE TABLE foo');

        // Prepare restore
        $restoreAction = new Database('db-action', 'mysql', ['foo'], 'db', '');
        $restoreProcess = $this->createRestoreProcess($app);

        $artifact = $this->findReadableInMemoryArtifact($restoreProcess);
        $artifact->seedFile($path, $dumpContent);

        // Force shouldSkip()
        $artifact->forceCanReadFileResult(new FileAcceptResult(
            FileAcceptDecision::Yes,
            'forced skip',
            false,  // shouldThrow
            true    // shouldSkip
        ));

        // Execute restore
        $restoreAction->restore($restoreProcess, $app->container());

        // Action must NOT be finished
        $this->assertFalse($restoreProcess->isActionFinished($restoreAction));

        // Table must still be empty
        $names = $database->pdo()
            ->query('SELECT name FROM foo')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame([], $names);

        // Skip entry must exist
        $state = $restoreProcess->stateFor($restoreAction);

        $this->assertArrayHasKey('skipped', $state);
        $this->assertCount(1, $state['skipped']);

        $entry = $state['skipped'][0];

        $this->assertSame('mysql', $entry['database']);
        $this->assertSame('forced skip', $entry['reason']);
        $this->assertArrayHasKey('size', $entry);
        $this->assertArrayHasKey('timestamp', $entry);
    }

    public function testConfigureForRestoreMethodReturnsClone()
    {
        $this->registerTestDatabase();
        $app = $this->bootingApp();

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $input = new Input([]);
        $configured = $action->configureForRestore($input);

        $this->assertInstanceOf(Database::class, $configured);
        $this->assertNotSame($action, $configured);
        $this->assertSame($action->jsonSerialize(), $configured->jsonSerialize());
    }

    public function testConfigureRestoreFieldsMethodReturnsEmptyArray()
    {
        $this->registerTestDatabase();
        $app = $this->bootingApp();

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo'],
            targetFolder: 'db',
            filepath: '',
        );

        $fields = $action->configureRestoreFields(new Edit());
        $fields = Fields::fromIterable($fields);

        $this->assertSame([], $fields->getNames());
    }

    public function testJsonSerializeMethod()
    {
        $this->registerTestDatabase();
        $app = $this->bootingApp();

        $action = new Database(
            id: 'db-action',
            database: 'mysql',
            tables: ['foo', 'bar'],
            targetFolder: 'db',
            filepath: 'custom-dump',
            timeBudgetInSeconds: 5,
        );

        $this->assertSame(
            [
                'id' => 'db-action',
                'database' => 'mysql',
                'tables' => ['foo', 'bar'],
                'targetFolder' => 'db/',
                'filepath' => 'custom-dump',
                'timeBudgetInSeconds' => 5,
            ],
            $action->jsonSerialize(),
        );
    }
}