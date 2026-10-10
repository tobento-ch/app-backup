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

namespace Tobento\App\Backup\Test\Repo;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\Repo\ProcessEntityFactory;
use Tobento\App\Backup\Repo\ProcessEntityInterface;
use Tobento\App\Backup\Repo\ProcessStorageRepository;
use Tobento\App\User\User;
use Tobento\App\User\UserInterface;
use Tobento\Service\Storage\InMemoryStorage;

class ProcessStorageRepositoryTest extends TestCase
{
    protected function createRepository(?UserInterface $user = null): ProcessStorageRepository
    {
        $request = null;

        if ($user !== null) {
            $request = new ServerRequest('GET', '/')->withAttribute(UserInterface::class, $user);
        }

        return new ProcessStorageRepository(
            storage: new InMemoryStorage([]),
            table: 'processes',
            processEntityFactory: new ProcessEntityFactory(),
            request: $request,
        );
    }

    public function testRequestMethod()
    {
        $repo = $this->createRepository();
        $this->assertNull($repo->request());

        $user = new User(id: 5);
        $repo = $this->createRepository(user: $user);

        $this->assertSame(
            $user,
            $repo->request()?->getAttribute(UserInterface::class)
        );
    }

    public function testCreateSetsUserIdFromRequestIfNotProvided()
    {
        $repo = $this->createRepository(
            user: new User(id: 99)
        );

        $entity = $repo->create([
            'type' => 'backup',
            'type_id' => 1,
            'status' => 'running',
            'name' => 'My Process',
            'artifacts' => [],
            'actions' => [],
            'processed_actions' => [],
            'action_states' => [],
            'hooks' => [],
            'meta' => [],
        ]);

        $this->assertSame(99, $entity->userId());
    }

    public function testCreateDoesNotOverrideExplicitUserId()
    {
        $repo = $this->createRepository(
            user: new User(id: 99)
        );

        $entity = $repo->create([
            'type' => 'backup',
            'type_id' => 1,
            'status' => 'running',
            'name' => 'My Process',
            'user_id' => 5,
            'artifacts' => [],
            'actions' => [],
            'processed_actions' => [],
            'action_states' => [],
            'hooks' => [],
            'meta' => [],
        ]);

        $this->assertSame(5, $entity->userId());
    }

    public function testCreateEntityCreatesProcessEntity()
    {
        $repo = $this->createRepository();

        $entity = $repo->createEntity([
            'id' => 10,
            'type' => 'restore',
            'type_id' => 2,
            'status' => 'completed',
            'name' => 'Restore Job',
            'artifacts' => [['db']],
            'actions' => ['copy' => ['start']],
            'processed_actions' => ['copy'],
            'action_states' => ['copy' => ['ok' => true]],
            'hooks' => [['after.restore']],
            'meta' => [],
            'runtime_seconds' => 1.234,
            'memory_peak_bytes' => 2048,
            'created_at' => null,
        ]);

        $this->assertInstanceOf(ProcessEntityInterface::class, $entity);
        $this->assertSame(10, $entity->id());
        $this->assertSame('restore', $entity->type());
        $this->assertSame(['db'], $entity->artifacts());
        $this->assertSame(['copy'], $entity->processedActions());
        $this->assertSame(1.234, $entity->runtimeInSeconds());
        $this->assertSame(2048, $entity->memoryPeakBytes());
    }

    public function testDistinctValuesReturnsUniqueValues()
    {
        $repo = $this->createRepository();

        $repo->create([
            'type' => 'backup',
            'type_id' => 1,
            'status' => 'running',
            'name' => 'A',
            'artifacts' => [],
            'actions' => [],
            'processed_actions' => [],
            'action_states' => [],
            'hooks' => [],
            'meta' => [],
        ]);

        $repo->create([
            'type' => 'backup',
            'type_id' => 2,
            'status' => 'completed',
            'name' => 'B',
            'artifacts' => [],
            'actions' => [],
            'processed_actions' => [],
            'action_states' => [],
            'hooks' => [],
            'meta' => [],
        ]);

        $repo->create([
            'type' => 'restore',
            'type_id' => 3,
            'status' => 'running',
            'name' => 'C',
            'artifacts' => [],
            'actions' => [],
            'processed_actions' => [],
            'action_states' => [],
            'hooks' => [],
            'meta' => [],
        ]);

        $values = $repo->distinctValues('type');

        $this->assertSame([
            'backup' => 'backup',
            'restore' => 'restore',
        ], $values);
    }
}