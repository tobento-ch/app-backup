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
use Tobento\App\Backup\Action\NullAction;
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

class NullActionTest extends \Tobento\App\Testing\TestCase
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
                'hooks' => [],
            ],
        ]);

        return $app->get(BackupProcessFactoryInterface::class)->createProcess(
            backupEntity: $backupEntity,
            processId: null,
            userId: null,
        );
    }

    protected function createRestoreProcess(AppInterface $app): RestoreProcessInterface
    {
        $repo = $app->get(ProcessRepositoryInterface::class);

        $processEntity = $repo->create([
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
            raw: 'dummy',
            registry: new App\InMemoryArtifactRegistry(),
        );

        return $app->get(RestoreProcessFactoryInterface::class)->createProcess(
            restoreEntity: $restoreEntity,
            processId: $processEntity->id(),
        );
    }

    public function testImplementsActionInterface()
    {
        $app = $this->bootingApp();
        $action = new NullAction('my-id');
        $this->assertInstanceOf(ActionInterface::class, $action);
    }
    
    public function testIdMethod()
    {
        $app = $this->bootingApp();
        $action = new NullAction('my-id');
        $this->assertSame('my-id', $action->id());
    }

    public function testBackupMarksFinished()
    {
        $app = $this->bootingApp();
        $action = new NullAction('null');

        $process = $this->createBackupProcess($app);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));
    }

    public function testBackupSkipsIfAlreadyFinished()
    {
        $app = $this->bootingApp();
        $action = new NullAction('null');

        $process = $this->createBackupProcess($app);
        $process->markActionFinished(action: $action);

        $action->backup($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));
    }

    public function testRestoreMarksFinished()
    {
        $app = $this->bootingApp();
        $action = new NullAction('null');

        $process = $this->createRestoreProcess($app);

        $action->restore($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));
    }

    public function testRestoreSkipsIfAlreadyFinished()
    {
        $app = $this->bootingApp();
        $action = new NullAction('null');

        $process = $this->createRestoreProcess($app);
        $process->markActionFinished(action: $action);

        $action->restore($process, $app->container());

        $this->assertTrue($process->isActionFinished($action));
    }

    public function testConfigureRestoreFieldsReturnsEmpty()
    {
        $app = $this->bootingApp();
        
        $action = new NullAction('null');

        $fields = $action->configureRestoreFields(new Edit());
        $fields = Fields::fromIterable($fields);

        $this->assertSame([], $fields->getNames());
    }

    public function testConfigureForRestoreReturnsNewInstance()
    {
        $app = $this->bootingApp();
        
        $action = new NullAction('null');

        $input = new Input([]);

        $configured = $action->configureForRestore($input);

        $this->assertInstanceOf(NullAction::class, $configured);
        $this->assertNotSame($action, $configured);
        $this->assertSame('null', $configured->id());
    }

    public function testJsonSerialize()
    {
        $app = $this->bootingApp();
        
        $action = new NullAction('null');

        $this->assertSame(['id' => 'null'], $action->jsonSerialize());
    }
}