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
use Tobento\App\Backup\Repo\BackupEntityFactory;
use Tobento\App\Backup\Repo\BackupEntityInterface;
use Tobento\App\Backup\Repo\BackupStorageRepository;
use Tobento\App\User\User;
use Tobento\App\User\UserInterface;
use Tobento\Service\Storage\InMemoryStorage;

class BackupStorageRepositoryTest extends TestCase
{
    protected function createRepository(?UserInterface $user = null): BackupStorageRepository
    {
        $request = null;

        if ($user !== null) {
            $request = new ServerRequest('GET', '/')->withAttribute(UserInterface::class, $user);
        }

        return new BackupStorageRepository(
            storage: new InMemoryStorage([]),
            table: 'backups',
            backupEntityFactory: new BackupEntityFactory(),
            request: $request,
        );
    }

    public function testCreateSetsUserIdFromRequestIfNotProvided()
    {
        $repo = $this->createRepository(
            user: new User(id: 99)
        );

        $entity = $repo->create([
            'name' => 'My Backup',
            'app_id' => 'frontend',
            'options' => [],
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
            'name' => 'My Backup',
            'app_id' => 'frontend',
            'user_id' => 5,
            'options' => [],
            'meta' => [],
        ]);

        $this->assertSame(5, $entity->userId());
    }

    public function testCreateEntityCreatesBackupEntity()
    {
        $repo = $this->createRepository();

        $entity = $repo->createEntity([
            'id' => 10,
            'name' => 'Test Backup',
            'app_id' => 'root',
            'options' => ['artifacts' => ['db']],
            'meta' => [],
            'created_at' => null,
        ]);

        $this->assertInstanceOf(BackupEntityInterface::class, $entity);
        $this->assertSame(10, $entity->id());
        $this->assertSame('Test Backup', $entity->name());
        $this->assertSame('root', $entity->appId());
        $this->assertSame(['db'], $entity->artifacts());
    }

    public function testDistinctValuesReturnsUniqueValues()
    {
        $repo = $this->createRepository();

        $repo->create([
            'name' => 'A',
            'app_id' => 'frontend',
            'options' => [],
            'meta' => [],
        ]);

        $repo->create([
            'name' => 'B',
            'app_id' => 'frontend',
            'options' => [],
            'meta' => [],
        ]);

        $repo->create([
            'name' => 'C',
            'app_id' => 'backend',
            'options' => [],
            'meta' => [],
        ]);

        $values = $repo->distinctValues('app_id');

        $this->assertSame([
            'frontend' => 'frontend',
            'backend' => 'backend',
        ], $values);
    }
}