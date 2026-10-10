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
use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\Action\Actions;
use Tobento\App\Backup\Action\ActionsInterface;
use Tobento\App\Backup\Action\ActionsFactoryInterface;
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Artifact\ReadableArtifactFactoryInterface;
use Tobento\App\Backup\Manifest\ManifestFactoryInterface;
use Tobento\App\Backup\Manifest\Manifest;
use Tobento\App\Backup\Manifest\ManifestInterface;
use Tobento\App\Backup\Process\RestoreProcessFactory;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\Backup\Test\App\ReadableInMemoryArtifact;
use Tobento\App\Backup\Test\Factory;

class RestoreProcessFactoryTest extends TestCase
{
    public function createArtifact(): ReadableArtifactInterface
    {
        return new ReadableInMemoryArtifact(
            streamFactory: Factory::createStreamFactory(),
        );
    }

    protected function createActionsFactory(): ActionsFactoryInterface
    {
        return new class implements ActionsFactoryInterface {

            public function createActionsFromBackupEntity($entity): ActionsInterface
            {
                throw new \LogicException('Not used in restore');
            }

            public function createActionsFromRestoreEntity(RestoreEntityInterface $entity): ActionsInterface
            {
                throw new \LogicException('Not used directly');
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

    protected function createArtifactFactory(): ReadableArtifactFactoryInterface
    {
        return new class($this) implements ReadableArtifactFactoryInterface {

            public function __construct(private RestoreProcessFactoryTest $test) {}

            public function createArtifact(RestoreEntityInterface $restoreEntity, $processEntity): ReadableArtifactInterface
            {
                return $this->test->createArtifact();
            }
        };
    }

    protected function createManifestFactory(): ManifestFactoryInterface
    {
        return new class implements ManifestFactoryInterface {

            public function createManifestFromRestoreEntity(RestoreEntityInterface $entity): ManifestInterface
            {
                return new Manifest(
                    processId: 1,
                    createdAt: new \DateTimeImmutable(),
                    actions: [
                        ['class' => NullAction::class, 'data' => ['id' => 'a']],
                    ],
                    name: $entity->name(),
                    meta: ['foo' => 'bar'],
                );
            }
            
            public function createManifestFromBackupProcess($process): ManifestInterface
            {
                throw new \LogicException('Not used in this test');
            }

            public function createManifestFromArray(array $data): ManifestInterface
            {
                throw new \LogicException('Not used in this test');
            }

            public function createManifestFromJsonString(string $json): ManifestInterface
            {
                throw new \LogicException('Not used in this test');
            }
        };
    }
    
    public function testCreateProcessMethodCreatesNewRestoreProcess()
    {
        $repo = Factory::createProcessRepository();

        $factory = new RestoreProcessFactory(
            processRepository: $repo,
            actionsFactory: $this->createActionsFactory(),
            artifactFactory: $this->createArtifactFactory(),
            manifestFactory: $this->createManifestFactory(),
            container: Factory::createContainer(),
        );

        $restoreEntity = Factory::createRestoreEntity([
            'name' => 'MyRestore',
            'hooks' => ['h1', 'h2'],
        ]);

        $process = $factory->createProcess(
            restoreEntity: $restoreEntity,
            processId: null,
            userId: 123,
            input: ['hooks' => ['h1', 'h2']],
        );

        $this->assertInstanceOf(RestoreProcessInterface::class, $process);
        $this->assertSame('MyRestore', $process->name());
        $this->assertSame(123, $process->userId());
        $this->assertSame(['h1', 'h2'], $process->hooks());
        $this->assertSame(['foo' => 'bar'], $process->meta());
        $this->assertSame(['a'], $process->actions()->ids());

        $stored = $repo->findById($process->id());

        $this->assertSame('restore', $stored->type());
        $this->assertSame(10, $stored->typeId());
    }

    public function testCreateProcessMethodLoadsExistingRestoreProcess()
    {
        $repo = Factory::createProcessRepository();

        $entity = $repo->create([
            'type' => 'restore',
            'type_id' => 10,
            'actions' => [
                ['class' => NullAction::class, 'data' => ['id' => 'a']]
            ],
            'processed_actions' => ['a'],
            'action_states' => ['a' => ['foo' => 'bar']],
            'hooks' => ['h1'],
            'status' => 'pending',
            'name' => 'ExistingRestore',
            'user_id' => 55,
            'meta' => ['x' => 'y'],
        ]);

        $factory = new RestoreProcessFactory(
            processRepository: $repo,
            actionsFactory: $this->createActionsFactory(),
            artifactFactory: $this->createArtifactFactory(),
            manifestFactory: $this->createManifestFactory(),
            container: Factory::createContainer(),
        );
        
        $restoreEntity = Factory::createRestoreEntity([
            'raw' => 'new-raw-source',
        ]);

        $process = $factory->createProcess(
            restoreEntity: $restoreEntity,
            processId: $entity->id(),
            userId: null,
            input: [],
        );

        $this->assertSame('ExistingRestore', $process->name());
        $this->assertSame(55, $process->userId());
        $this->assertSame(['h1'], $process->hooks());
        $this->assertSame(['x' => 'y'], $process->meta());
        $this->assertTrue($process->isActionFinished(new NullAction('a')));
        $this->assertSame(['foo' => 'bar'], $process->stateFor(new NullAction('a')));
    }
}