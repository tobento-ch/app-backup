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

namespace Tobento\App\Backup\Test\Feature\Artifact;

use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\AppInterface;
use Tobento\App\Backup\Artifact\EncryptionReadableArtifact;
use Tobento\App\Backup\Artifact\ReadableArtifactFactory;
use Tobento\App\Backup\Artifact\ReadableArtifactFactoryInterface;
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\ProcessEntityInterface;
use Tobento\App\Backup\Repo\RestoreEntity;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\Backup\Test\App;
use Tobento\App\Backup\Test\App\ReadableInMemoryArtifact;
use Tobento\Service\Encryption\EncryptersInterface;

class ReadableArtifactFactoryTest extends \Tobento\App\Testing\TestCase
{
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

    protected function createRestoreEntity(AppInterface $app): RestoreEntityInterface
    {
        return new RestoreEntity(
            id: '1',
            name: 'Test Restore',
            type: 'inmemory',
            raw: 'dummy',
            registry: new App\InMemoryArtifactRegistry(),
        );
    }

    protected function createProcessEntity(array $meta = []): ProcessEntityInterface
    {
        return new ProcessEntity([
            'id' => 1,
            'type' => 'restore',
            'typeId' => 1,
            'actions' => [],
            'processedActions' => [],
            'actionStates' => [],
            'hooks' => [],
            'status' => 'pending',
            'name' => 'Test Process',
            'userId' => null,
            'meta' => $meta,
        ]);
    }

    public function testImplementsReadableArtifactFactoryInterface()
    {
        $app = $this->bootingApp();

        $factory = new ReadableArtifactFactory($app->container());

        $this->assertInstanceOf(
            ReadableArtifactFactoryInterface::class,
            $factory
        );
    }

    public function testCreateArtifactMethod()
    {
        $app = $this->bootingApp();

        $factory = new ReadableArtifactFactory($app->container());
        $restoreEntity = $this->createRestoreEntity($app);
        $processEntity = $this->createProcessEntity(['encrypt' => '0']);

        $artifact = $factory->createArtifact($restoreEntity, $processEntity);

        $this->assertInstanceOf(ReadableArtifactInterface::class, $artifact);
        $this->assertNotInstanceOf(EncryptionReadableArtifact::class, $artifact);
    }

    public function testCreateArtifactMethodReturnsEncryptedArtifact()
    {
        $app = $this->bootingApp();

        $factory = new ReadableArtifactFactory($app->container());
        $restoreEntity = $this->createRestoreEntity($app);
        $processEntity = $this->createProcessEntity(['encrypt' => '1']);

        $artifact = $factory->createArtifact($restoreEntity, $processEntity);

        $this->assertInstanceOf(EncryptionReadableArtifact::class, $artifact);
    }

    public function testCreateArtifactMethodUsesMetaEncrypter()
    {
        $app = $this->bootingApp();

        $factory = new ReadableArtifactFactory($app->container());
        $restoreEntity = $this->createRestoreEntity($app);

        $processEntity = $this->createProcessEntity([
            'encrypt' => '1',
            'encrypter' => 'default',
        ]);

        $artifact = $factory->createArtifact($restoreEntity, $processEntity);

        $this->assertInstanceOf(EncryptionReadableArtifact::class, $artifact);

        $encrypters = $app->get(EncryptersInterface::class);
        $expected = $encrypters->get('default');

        $this->assertInstanceOf(get_class($expected), $expected);
    }

    public function testCreateArtifactMethodUsesRuntimeEncrypter()
    {
        $app = $this->bootingApp();

        $factory = new ReadableArtifactFactory($app->container());
        $restoreEntity = $this->createRestoreEntity($app);

        $processEntity = $this->createProcessEntity([
            'encrypt' => '1',
        ]);

        $artifact = $factory->createArtifact($restoreEntity, $processEntity);

        $this->assertInstanceOf(EncryptionReadableArtifact::class, $artifact);

        $runtime = $app->get(BackupRuntimeConfigInterface::class);
        $encrypters = $app->get(EncryptersInterface::class);

        $expected = $encrypters->get($runtime->encrypter());

        $this->assertInstanceOf(get_class($expected), $expected);
    }
}