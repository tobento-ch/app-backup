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
use Tobento\App\Backup\Action\Directory;
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

class DirectoryTest extends \Tobento\App\Testing\TestCase
{
    use RefreshDatabases;
    use App\Traits\InMemoryArtifactHelpers;

    protected string $tmpSourceDir;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpSourceDir = sys_get_temp_dir().'/tobento-backup-test-'.uniqid();
        mkdir($this->tmpSourceDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpSourceDir);

        parent::tearDown();
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }

    protected function writeSourceFile(string $relativePath, string $content): void
    {
        $full = $this->tmpSourceDir.'/'.$relativePath;
        $dir = dirname($full);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($full, $content);
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
        
        $action = new Directory(id: 'my-id', dir: $this->tmpSourceDir, targetFolder: 'target');

        $this->assertInstanceOf(ActionInterface::class, $action);
    }
    
    public function testIdMethod()
    {
        $app = $this->bootingApp();
        
        $action = new Directory(id: 'my-id', dir: $this->tmpSourceDir, targetFolder: 'target');

        $this->assertSame('my-id', $action->id());
    }

    public function testBackupMethodMarksFinishedWhenDirDoesNotExist()
    {
        $app = $this->bootingApp();

        $action = new Directory(
            id: 'missing-dir',
            dir: $this->tmpSourceDir.'/does-not-exist',
            targetFolder: 'target',
        );

        $process = $this->createBackupProcess($app);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        $artifact = $this->findWritableInMemoryArtifact($process);
        $this->assertEmpty($artifact->writtenFiles());
    }

    public function testBackupMethodSkipsIfAlreadyFinished()
    {
        $app = $this->bootingApp();

        $this->writeSourceFile('file.txt', 'content');

        $action = new Directory(id: 'dir-action', dir: $this->tmpSourceDir, targetFolder: 'target');

        $process = $this->createBackupProcess($app);
        $process->markActionFinished(action: $action);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        // Early-return proof: even though a real file exists, nothing
        // was written, since the finished-check should short-circuit
        // before the directory is ever scanned.
        $artifact = $this->findWritableInMemoryArtifact($process);
        $this->assertEmpty($artifact->writtenFiles());
    }

    public function testBackupMethodCopiesFilesToArtifactAndMarksFinished()
    {
        $app = $this->bootingApp();

        $this->writeSourceFile('a.txt', 'aaa');
        $this->writeSourceFile('sub/b.txt', 'bbb');

        $action = new Directory(id: 'dir-action', dir: $this->tmpSourceDir, targetFolder: 'target');

        $process = $this->createBackupProcess($app);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        $artifact = $this->findWritableInMemoryArtifact($process);
        $written = $artifact->writtenFiles();

        $this->assertSame('aaa', $written['target/a.txt'] ?? null);
        $this->assertSame('bbb', $written['target/sub/b.txt'] ?? null);
    }

    public function testBackupMethodAppliesOnlyDirsFilter()
    {
        $app = $this->bootingApp();

        $this->writeSourceFile('keep/a.txt', 'aaa');
        $this->writeSourceFile('skip/b.txt', 'bbb');

        $action = new Directory(
            id: 'dir-action',
            dir: $this->tmpSourceDir,
            targetFolder: 'target',
            onlyDirs: ['keep'],
        );

        $process = $this->createBackupProcess($app);

        $action->backup($process, $app->container());

        $artifact = $this->findWritableInMemoryArtifact($process);
        $written = $artifact->writtenFiles();

        $this->assertArrayHasKey('target/keep/a.txt', $written);
        $this->assertArrayNotHasKey('target/skip/b.txt', $written);
    }

    public function testBackupMethodAppliesExceptDirsFilter()
    {
        $app = $this->bootingApp();

        $this->writeSourceFile('keep.txt', 'keep');
        $this->writeSourceFile('cache/skip.txt', 'skip');

        $action = new Directory(
            id: 'dir-action',
            dir: $this->tmpSourceDir,
            targetFolder: 'target',
            exceptDirs: ['cache'],
        );

        $process = $this->createBackupProcess($app);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));

        $artifact = $this->findWritableInMemoryArtifact($process);
        $written = $artifact->writtenFiles();

        $this->assertArrayHasKey('target/keep.txt', $written);
        $this->assertArrayNotHasKey('target/cache/skip.txt', $written);
    }
    
    public function testBackupMethodExceptDirsWildcardSkipsAllSubdirectories()
    {
        $app = $this->bootingApp();

        // root-level file
        $this->writeSourceFile('root.txt', 'root');

        // subdirectory files
        $this->writeSourceFile('sub/a.txt', 'aaa');
        $this->writeSourceFile('deep/sub/b.txt', 'bbb');

        $action = new Directory(
            id: 'dir-action',
            dir: $this->tmpSourceDir,
            targetFolder: 'target',
            exceptDirs: ['*'],   // skip ALL subdirectories
        );

        $process = $this->createBackupProcess($app);

        $action->backup($process, $app->container());

        $artifact = $this->findWritableInMemoryArtifact($process);
        $written = $artifact->writtenFiles();

        // root file must be included
        $this->assertArrayHasKey('target/root.txt', $written);

        // all subdirectories must be excluded
        $this->assertArrayNotHasKey('target/sub/a.txt', $written);
        $this->assertArrayNotHasKey('target/deep/sub/b.txt', $written);
    }

    public function testBackupMethodDoesNotMarkFinishedWhenArtifactDefers()
    {
        $app = $this->bootingApp();

        $this->writeSourceFile('a.txt', 'aaa');

        $action = new Directory(id: 'dir-action', dir: $this->tmpSourceDir, targetFolder: 'target');

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

    public function testRestoreMethodMarksFinishedWhenBaseDirDoesNotExist()
    {
        $app = $this->bootingApp();

        $action = new Directory(
            id: 'missing-dir',
            dir: $this->tmpSourceDir.'/does-not-exist',
            targetFolder: 'target',
        );

        $process = $this->createRestoreProcess($app);

        $action->restore($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));
    }

    public function testRestoreMethodSkipsIfAlreadyFinished()
    {
        $app = $this->bootingApp();

        $action = new Directory(id: 'dir-action', dir: $this->tmpSourceDir, targetFolder: 'target');

        $process = $this->createRestoreProcess($app);
        $process->markActionFinished(action: $action);

        $artifact = $this->findReadableInMemoryArtifact($process);
        $artifact->seedFile('target/a.txt', 'aaa');

        $action->restore($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));
        $this->assertFileDoesNotExist($this->tmpSourceDir.'/a.txt');
    }

    public function testRestoreMethodWritesFilesToDiskAndMarksFinished()
    {
        $app = $this->bootingApp();

        $this->writeSourceFile('a.txt', 'aaa');
        $this->writeSourceFile('sub/b.txt', 'bbb');
        
        $action = new Directory(id: 'dir-action', dir: $this->tmpSourceDir, targetFolder: 'target');

        $process = $this->createRestoreProcess($app);

        $artifact = $this->findReadableInMemoryArtifact($process);
        $artifact->seedFile('target/a.txt', 'aaa');
        $artifact->seedFile('target/sub/b.txt', 'bbb');

        $action->restore($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));
        $this->assertSame('aaa', file_get_contents($this->tmpSourceDir.'/a.txt'));
        $this->assertSame('bbb', file_get_contents($this->tmpSourceDir.'/sub/b.txt'));
    }

    public function testRestoreMethodPreventsDirectoryTraversal()
    {
        $app = $this->bootingApp();

        $action = new Directory(id: 'dir-action', dir: $this->tmpSourceDir, targetFolder: 'target');

        $process = $this->createRestoreProcess($app);

        $artifact = $this->findReadableInMemoryArtifact($process);
        // '..' segment inside the relative path must be rejected.
        $artifact->seedFile('target/../evil.txt', 'malicious');

        $action->restore($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));
        $this->assertFileDoesNotExist(dirname($this->tmpSourceDir).'/evil.txt');
    }

    public function testRestoreMethodDoesNotMarkFinishedWhenArtifactDefers()
    {
        $app = $this->bootingApp();

        $action = new Directory(id: 'dir-action', dir: $this->tmpSourceDir, targetFolder: 'target');

        $process = $this->createRestoreProcess($app);

        $artifact = $this->findReadableInMemoryArtifact($process);
        $artifact->seedFile('target/a.txt', 'aaa');
        $artifact->forceCanReadFileResult(new FileAcceptResult(
            FileAcceptDecision::Defer,
            reason: 'forced defer',
            throw: false,
            skip: false,
        ));

        $action->restore($process, $app->container());

        $this->assertFalse($process->isActionFinished($action));
        $this->assertFileDoesNotExist($this->tmpSourceDir.'/a.txt');
    }

    public function testConfigureForRestoreMethodReturnsClone()
    {
        $app = $this->bootingApp();
        
        $action = new Directory(id: 'my-id', dir: $this->tmpSourceDir, targetFolder: 'target');

        $input = new Input([]);
        $configured = $action->configureForRestore($input);

        $this->assertInstanceOf(Directory::class, $configured);
        $this->assertNotSame($action, $configured);
        $this->assertSame($action->jsonSerialize(), $configured->jsonSerialize());
    }

    public function testConfigureRestoreFieldsMethodReturnsEmptyArray()
    {
        $app = $this->bootingApp();
        
        $action = new Directory(id: 'my-id', dir: $this->tmpSourceDir, targetFolder: 'target');

        $fields = $action->configureRestoreFields(new Edit());
        $fields = Fields::fromIterable($fields);

        $this->assertSame([], $fields->getNames());
    }
    
    public function testJsonSerializeMethod()
    {
        $app = $this->bootingApp();
        
        $action = new Directory(
            id: 'my-id',
            dir: $this->tmpSourceDir,
            targetFolder: 'target',
            onlyDirs: ['a'],
            exceptDirs: ['b'],
            timeBudgetInSeconds: 5,
        );

        $this->assertSame(
            [
                'dir' => $this->tmpSourceDir,
                'targetFolder' => 'target/',
                'onlyDirs' => ['a'],
                'exceptDirs' => ['b'],
                'timeBudgetInSeconds' => 5,
            ],
            $action->jsonSerialize(),
        );
    }    
}