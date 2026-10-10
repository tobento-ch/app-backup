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
use Tobento\App\Backup\Action\AppDirectory;
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

class AppDirectoryTest extends \Tobento\App\Testing\TestCase
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
                $registries->add(
                    registry: new App\InMemoryArtifactRegistry(),
                );
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
                'app' => ['dir' => ['root' => ['public' => '1']]],
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
            id: (string) $processEntity->id(),
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
        
        $action = new AppDirectory(
            appId: 'root',
            appDir: 'trans',
            name: 'Trans Dir',
        );

        $this->assertInstanceOf(ActionInterface::class, $action);
    }
    
    public function testIdMethod()
    {
        $app = $this->bootingApp();
        
        $action = new AppDirectory(
            appId: 'root',
            appDir: 'trans',
            name: 'Trans Dir',
        );

        $this->assertSame('app.dir.root.trans', $action->id());
    }
    
    public function testGetterMethod()
    {
        $app = $this->bootingApp();

        $action = new AppDirectory(
            appId: 'root',
            appDir: 'trans',
            name: 'Trans Dir',
            onlyDirs: ['foo'],
            exceptDirs: ['bar'],
            timeBudgetInSeconds: 42,
        );

        $this->assertSame('root', $action->appId());
        $this->assertSame('trans', $action->appDir());
        $this->assertSame('Trans Dir', $action->name());
        $this->assertSame(['foo'], $action->onlyDirs());
        $this->assertSame(['bar'], $action->exceptDirs());
        $this->assertSame(42, $action->timeBudgetInSeconds());
    }
    
    public function testBackupMethodMarksActionFinishedWhenAppNotFound()
    {
        $app = $this->bootingApp();
        $action = new AppDirectory(
            appId: 'does-not-exist',
            appDir: 'storage',
            name: 'Missing App Storage',
        );
        $process = $this->createBackupProcess($app);
        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        // Confirm no files were written for this branch, either.
        $artifact = $this->findWritableInMemoryArtifact($process);
        $this->assertEmpty($artifact->writtenFiles());
    }

    public function testBackupMethodSkipsIfAlreadyFinished()
    {
        $app = $this->bootingApp();
        $action = new AppDirectory(
            appId: 'root',
            appDir: 'this-dir-does-not-exist',
            name: 'Bogus Dir',
        );
        $process = $this->createBackupProcess($app);
        $process->markActionFinished(action: $action);

        // If backup() did NOT return early, createAction() would run
        // $appInstance->dir('this-dir-does-not-exist'), which throws
        // per the method's own doc comment ("this will throw if not exists").
        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        // Direct proof the write path was never reached: nothing landed
        // in the artifact, confirming the early-return truly short-circuited.
        $artifact = $this->findWritableInMemoryArtifact($process);
        $this->assertEmpty($artifact->writtenFiles());
    }

    public function testBackupMethodCopiesDirectoryContentsAndMarksFinished()
    {
        $app = $this->bootingApp();

        $action = new AppDirectory(
            appId: 'root',
            appDir: 'public',
            name: 'Public Dir',
        );

        $process = $this->createBackupProcess($app);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));
        
        $artifact = $this->findWritableInMemoryArtifact($process);

        $writtenPaths = array_keys($artifact->writtenFiles());

        $this->assertNotEmpty($writtenPaths);

        foreach ($writtenPaths as $path) {
            $this->assertStringStartsWith('app-dir/root/public/', $path);
        }
    }
    
    public function testBackupMethodDoesNotMarkFinishedWhenArtifactDefers()
    {
        $app = $this->bootingApp();

        $action = new AppDirectory(
            appId: 'root',
            appDir: 'public',
            name: 'Public Dir',
        );

        $process = $this->createBackupProcess($app);

        $artifact = $this->findWritableInMemoryArtifact($process);
        $artifact->forceCanWriteFileResult(new FileAcceptResult(
            FileAcceptDecision::Defer,
            reason: 'forced defer for resumability test',
            throw: false,
            skip: false,
        ));

        $action->backup($process, $app->container());

        // Directory should have saved offset state and returned without
        // finishing - so AppDirectory's outer isActionFinished(action: $this)
        // must also be false, proving the early-return "resume next window"
        // branch actually ran instead of the finished branch.
        $this->assertFalse($process->isActionFinished($action));

        // No file should have been written, since Defer means "not now".
        $this->assertEmpty($artifact->writtenFiles());
    }

    public function testRestoreMethodMarksActionFinishedWhenAppNotFound()
    {
        $app = $this->bootingApp();

        $action = new AppDirectory(
            appId: 'does-not-exist',
            appDir: 'public',
            name: 'Public Dir',
        );

        $process = $this->createRestoreProcess($app);

        $action->restore($process, $app->container());

        // app not found = nothing to do; must be marked finished
        // so the job handler can progress instead of requeuing forever.
        $this->assertTrue($process->isActionFinished($action));
        
        // Proof createAction() returned null before Directory::restore() ever ran:
        // the readable artifact was never queried at all.
        $artifact = $this->findReadableInMemoryArtifact($process);
        $this->assertFalse($artifact->wasRead());        
    }

    public function testRestoreMethodRestoresDirectoryContentsAndMarksFinished()
    {
        $app = $this->bootingApp();

        $action = new AppDirectory(
            appId: 'root',
            appDir: 'public',
            name: 'Public Dir',
        );

        $process = $this->createRestoreProcess($app);

        $artifact = $this->findReadableInMemoryArtifact($process);
        $artifact->seedFile(
            'app-dir/root/public/restore-test.txt',
            'hello from restore test',
        );

        $action->restore($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        $restoredPath = $app->dir('public') . 'restore-test.txt';

        $this->assertFileExists($restoredPath);
        $this->assertSame('hello from restore test', file_get_contents($restoredPath));

        @unlink($restoredPath);
    }
    
    public function testRestoreMethodDoesNotMarkFinishedWhenArtifactDefers()
    {
        $app = $this->bootingApp();

        $action = new AppDirectory(
            appId: 'root',
            appDir: 'public',
            name: 'Public Dir',
        );

        $process = $this->createRestoreProcess($app);

        $artifact = $this->findReadableInMemoryArtifact($process);
        $artifact->seedFile('app-dir/root/public/deferred-test.txt', 'content');
        $artifact->forceCanReadFileResult(new FileAcceptResult(
            FileAcceptDecision::Defer,
            reason: 'forced defer for resumability test',
            throw: false,
            skip: false,
        ));

        $action->restore($process, $app->container());

        $this->assertFalse($process->isActionFinished($action));

        $restoredPath = $app->dir('public') . 'deferred-test.txt';
        $this->assertFileDoesNotExist($restoredPath);
    }

    public function testConfigureRestoreFieldsMethodReturnsFields()
    {
        $app = $this->bootingApp();

        $action = new AppDirectory(
            appId: 'root',
            appDir: 'public',
            name: 'Public Dir',
        );

        $fields = $action->configureRestoreFields(new Edit());
        $fields = Fields::fromIterable($fields);
        
        $this->assertSame(['options.app.dir.root.public'], $fields->getNames());
    }
    
    public function testConfigureForRestoreMethodReturnsNullActionIfNotEnabled()
    {
        $app = $this->bootingApp();

        $action = new AppDirectory(
            appId: 'root',
            appDir: 'public',
            name: 'Public Dir',
        );

        $input = new Input([
            'options.app.dir.fixture.storage' => '0',
        ]);

        $configured = $action->configureForRestore($input);

        $this->assertInstanceOf(NullAction::class, $configured);
    }
    
    public function testConfigureForRestoreMethodReturnsNullActionIfOptionMissing()
    {
        $app = $this->bootingApp();

        $action = new AppDirectory(
            appId: 'root',
            appDir: 'public',
            name: 'Public Dir',
        );

        $input = new Input([]);

        $configured = $action->configureForRestore($input);

        $this->assertInstanceOf(NullAction::class, $configured);
    }

    public function testConfigureForRestoreMethodReturnsNewInstanceIfEnabled()
    {
        $app = $this->bootingApp();

        $action = new AppDirectory(
            appId: 'root',
            appDir: 'public',
            name: 'Public Dir',
        );

        $input = new Input([
            'options.app.dir.root.public' => '1',
        ]);

        $configured = $action->configureForRestore($input);

        $this->assertInstanceOf(AppDirectory::class, $configured);
        $this->assertNotSame($action, $configured);
        $this->assertSame($action->jsonSerialize(), $configured->jsonSerialize());
    }
    
    public function testJsonSerialize()
    {
        $app = $this->bootingApp();
        
        $action = new AppDirectory(
            appId: 'blog',
            appDir: 'storage',
            name: 'Blog Storage',
            onlyDirs: ['uploads'],
            exceptDirs: ['cache'],
            timeBudgetInSeconds: 15,
        );

        $this->assertSame(
            [
                'appId' => 'blog',
                'appDir' => 'storage',
                'name' => 'Blog Storage',
                'onlyDirs' => ['uploads'],
                'exceptDirs' => ['cache'],
                'timeBudgetInSeconds' => 15,
            ],
            $action->jsonSerialize(),
        );
    }
}