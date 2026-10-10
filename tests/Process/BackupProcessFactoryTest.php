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

namespace Tobento\App\Backup\Test\Process;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\Action\Actions;
use Tobento\App\Backup\Action\ActionsInterface;
use Tobento\App\Backup\Action\ActionsFactoryInterface;
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Artifact\WritableArtifactFactoryInterface;
use Tobento\App\Backup\Process\BackupProcessFactory;
use Tobento\App\Backup\Process\BackupProcessFactoryInterface;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Repo\BackupEntityInterface;
use Tobento\App\Backup\Test\App;
use Tobento\App\Backup\Test\Factory;

class BackupProcessFactoryTest extends TestCase
{
    public function createArtifact(): WritableArtifactInterface
    {
        return new App\WritableInMemoryArtifact();
    }

    protected function createActionsFactory(): ActionsFactoryInterface
    {
        return new class implements ActionsFactoryInterface {

            public function createActionsFromBackupEntity(BackupEntityInterface $entity): ActionsInterface
            {
                return new Actions(new NullAction('a'));
            }

            public function createActionsFromArray(array $data): ActionsInterface
            {
                return new Actions(new NullAction('a'));
            }
            
            public function createActionsFromJsonString(string $json): ActionsInterface
            {
                return new Actions(new NullAction('a'));
            }
        };
    }

    protected function createArtifactFactory(): WritableArtifactFactoryInterface
    {
        return new class($this) implements WritableArtifactFactoryInterface {

            public function __construct(private BackupProcessFactoryTest $test) {}

            public function createArtifactFromProcessEntity($processEntity): WritableArtifactInterface
            {
                return $this->test->createArtifact();
            }
        };
    }

    public function testThatImplementsInterface()
    {
        $factory = new BackupProcessFactory(
            processRepository: Factory::createProcessRepository(),
            actionsFactory: $this->createActionsFactory(),
            artifactFactory: $this->createArtifactFactory(),
        );

        $this->assertInstanceOf(BackupProcessFactoryInterface::class, $factory);
    }
    
    public function testCreateProcessMethodCreatesNewProcess()
    {
        $repo = Factory::createProcessRepository();
        $actionsFactory = $this->createActionsFactory();
        $artifactFactory = $this->createArtifactFactory();

        $factory = new BackupProcessFactory(
            processRepository: $repo,
            actionsFactory: $actionsFactory,
            artifactFactory: $artifactFactory,
        );

        $backupEntity = Factory::createBackupEntity([
            'id' => 10,
            'name' => 'MyBackup',
            'options' => [
                'artifacts' => [],
                'hooks' => ['h1'],
            ],
            'meta' => ['foo' => 'bar'],
        ]);

        $process = $factory->createProcess(
            backupEntity: $backupEntity,
            processId: null,
            userId: 123,
        );

        $this->assertInstanceOf(BackupProcessInterface::class, $process);
        $this->assertSame('MyBackup', $process->name());
        $this->assertSame(123, $process->userId());
        $this->assertSame(['h1'], $process->hooks());
        $this->assertSame(['foo' => 'bar'], $process->meta());
        $this->assertSame(['a'], $process->actions()->ids());

        $stored = $repo->findById($process->id());

        $this->assertSame('backup', $stored->type());
        $this->assertSame(10, $stored->typeId());
    }

    public function testCreateProcessMethodLoadsExistingProcess()
    {
        $repo = Factory::createProcessRepository();

        $entity = $repo->create([
            'type' => 'backup',
            'type_id' => 10,
            'actions' => [
                ['class' => NullAction::class, 'data' => ['id' => 'a']]
            ],
            'processed_actions' => ['a'],
            'action_states' => ['a' => ['foo' => 'bar']],
            'artifacts' => [],
            'hooks' => ['h1'],
            'status' => 'pending',
            'name' => 'ExistingBackup',
            'user_id' => 55,
            'meta' => ['x' => 'y'],
        ]);

        $factory = new BackupProcessFactory(
            processRepository: $repo,
            actionsFactory: $this->createActionsFactory(),
            artifactFactory: $this->createArtifactFactory(),
        );

        $backupEntity = Factory::createBackupEntity([
            'name' => 'IgnoredNameBecauseExistingProcessHasOwnName',
        ]);

        $process = $factory->createProcess(
            backupEntity: $backupEntity,
            processId: $entity->id(),
            userId: null,
        );

        $this->assertSame('ExistingBackup', $process->name());
        $this->assertSame(55, $process->userId());
        $this->assertSame(['h1'], $process->hooks());
        $this->assertSame(['x' => 'y'], $process->meta());
        $this->assertTrue($process->isActionFinished(new NullAction('a')));
        $this->assertSame(['foo' => 'bar'], $process->stateFor(new NullAction('a')));
    }
}