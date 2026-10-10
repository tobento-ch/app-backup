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
use Tobento\App\Backup\Action\AppsDirectory;
use Tobento\App\Backup\Action\NullAction;
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

class AppsDirectoryTest extends \Tobento\App\Testing\TestCase
{
    use RefreshDatabases;
    use App\Traits\InMemoryArtifactHelpers;

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

    protected function createBackupProcess(AppInterface $app): BackupProcessInterface
    {
        $backupEntity = new BackupEntity([
            'id' => 1,
            'name' => 'Test Backup',
            'user_id' => 0,
            'created_at' => time(),
            'options' => [
                'artifacts' => ['inmemory'],
                'apps' => [
                    'dirs' => [
                        'root' => ['public', 'storage'],
                    ],
                ],
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
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: ['root' => ['public']],
            targetFolder: 'apps-dir',
        );

        $this->assertInstanceOf(ActionInterface::class, $action);
    }
    
    public function testIdMethod()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: ['root' => ['public']],
            targetFolder: 'apps-dir',
        );

        $this->assertSame('apps.directory', $action->id());
    }
    
    public function testGetterMethods()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: ['root' => ['public']],
            targetFolder: 'apps-dir',
            timeBudgetInSeconds: 33,
        );

        $this->assertSame('apps.directory', $action->id());
        $this->assertSame(['root' => ['public']], $action->dirs());
        $this->assertSame('apps-dir/', $action->targetFolder());
        $this->assertSame(33, $action->timeBudgetInSeconds());
    }

    public function testBackupMethodMarksActionFinishedWhenAppNotFound()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: [
                'does-not-exist' => ['public'],
            ],
        );

        $process = $this->createBackupProcess($app);

        $action->backup($process, $app->container());

        // Must be marked finished because no app was found
        $this->assertTrue($process->isActionFinished($action));

        // No files should have been written
        $artifact = $this->findWritableInMemoryArtifact($process);
        $this->assertEmpty($artifact->writtenFiles());
    }

    public function testBackupMethodSkipsIfAlreadyFinished()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: ['root' => ['public']],
        );

        $process = $this->createBackupProcess($app);
        $process->markActionFinished(action: $action);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        $artifact = $this->findWritableInMemoryArtifact($process);
        $this->assertEmpty($artifact->writtenFiles());
    }

    public function testBackupMethodCopiesAllDirectoriesAndMarksFinished()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: ['root' => ['public']],
        );

        $process = $this->createBackupProcess($app);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        $artifact = $this->findWritableInMemoryArtifact($process);
        $writtenPaths = array_keys($artifact->writtenFiles());

        $this->assertNotEmpty($writtenPaths);

        foreach ($writtenPaths as $path) {
            $this->assertStringStartsWith('apps-directory/root/public/', $path);
        }
    }

    public function testBackupMethodDoesNotMarkFinishedWhenArtifactDefers()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: ['root' => ['public']],
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

    public function testRestoreMethodMarksActionFinishedWhenAppNotFound()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: [
                'does-not-exist' => ['public'],
            ],
        );

        $process = $this->createRestoreProcess($app);

        $action->restore($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        $artifact = $this->findReadableInMemoryArtifact($process);
        $this->assertFalse($artifact->wasRead());
    }
    
    public function testRestoreMethodRestoresAllDirectoriesAndMarksFinished()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: ['root' => ['public']],
        );

        $process = $this->createRestoreProcess($app);

        $artifact = $this->findReadableInMemoryArtifact($process);
        $artifact->seedFile(
            'apps-directory/root/public/test.txt',
            'hello apps restore'
        );

        $action->restore($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        $restoredPath = $app->dir('public') . 'test.txt';

        $this->assertFileExists($restoredPath);
        $this->assertSame('hello apps restore', file_get_contents($restoredPath));

        @unlink($restoredPath);
    }

    public function testRestoreMethodDoesNotMarkFinishedWhenArtifactDefers()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: ['root' => ['public']],
        );

        $process = $this->createRestoreProcess($app);

        $artifact = $this->findReadableInMemoryArtifact($process);
        $artifact->seedFile('apps-directory/root/public/deferred.txt', 'content');
        $artifact->forceCanReadFileResult(new FileAcceptResult(
            FileAcceptDecision::Defer,
            reason: 'forced defer',
            throw: false,
            skip: false,
        ));

        $action->restore($process, $app->container());

        $this->assertFalse($process->isActionFinished($action));

        $restoredPath = $app->dir('public') . 'deferred.txt';
        $this->assertFileDoesNotExist($restoredPath);
    }

    public function testConfigureRestoreFieldsMethodReturnsFields()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: [
                'root' => ['public', 'storage'],
                'blog' => ['assets'],
            ],
        );

        $fields = $action->configureRestoreFields(new Edit());
        $fields = Fields::fromIterable($fields);

        $this->assertSame(
            ['options.apps.dirs.root', 'options.apps.dirs.blog'],
            $fields->getNames()
        );
    }
    
    public function testConfigureRestoreFieldsMethodReturnsNoFieldsWhenDirsEmpty()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: [
                'root' => [], // empty
                'blog' => [], // empty
            ],
        );

        $fields = $action->configureRestoreFields(new Edit());
        $fields = Fields::fromIterable($fields);

        // No fields should be generated
        $this->assertSame([], $fields->getNames());
    }

    public function testConfigureForRestoreMethodReturnsNewInstance()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: ['root' => ['public']],
        );

        $input = new Input([
            'options.apps.dirs' => [
                'root' => ['public', 'storage'],
            ],
        ]);

        $configured = $action->configureForRestore($input);

        $this->assertInstanceOf(AppsDirectory::class, $configured);
        $this->assertNotSame($action, $configured);
        $this->assertSame($configured->jsonSerialize()['dirs'], [
            'root' => ['public', 'storage'],
        ]);
    }

    public function testJsonSerialize()
    {
        $app = $this->bootingApp();

        $action = new AppsDirectory(
            dirs: ['blog' => ['storage']],
            targetFolder: 'apps-dir',
            timeBudgetInSeconds: 15,
        );

        $this->assertSame(
            [
                'dirs' => ['blog' => ['storage']],
                'targetFolder' => 'apps-dir/',
                'timeBudgetInSeconds' => 15,
            ],
            $action->jsonSerialize(),
        );
    }
}