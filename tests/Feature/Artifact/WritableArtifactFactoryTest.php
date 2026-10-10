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

use Psr\Container\ContainerInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\AppInterface;
use Tobento\App\Backup\Artifact\ArtifactInterface;
use Tobento\App\Backup\Artifact\EncryptionWritableArtifact;
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Artifact\WritableArtifactFactory;
use Tobento\App\Backup\Artifact\WritableArtifactFactoryInterface;
use Tobento\App\Backup\Artifact\WritableArtifacts;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Backup\Exception\ArtifactCreateException;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\ReadableArtifactRegistryInterface;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\ProcessEntityInterface;
use Tobento\App\Backup\Repo\RestoreEntityInterface;
use Tobento\App\Backup\Test\App;
use Tobento\App\Backup\Test\App\WritableInMemoryArtifact;
use Tobento\Service\Encryption\EncryptersInterface;
use Tobento\Service\Repository\RepositoryInterface;

class WritableArtifactFactoryTest extends \Tobento\App\Testing\TestCase
{
    use App\Traits\InMemoryArtifactHelpers;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');

        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        $app->on(
            ArtifactRegistriesInterface::class,
            static function (ArtifactRegistriesInterface $registries): void {
                $registries->add(new App\InMemoryArtifactRegistry());
            }
        );

        return $app;
    }

    /**
     * A registry that only supports reading, never writing - used to
     * confirm WritableArtifactFactory skips non-writable registries.
     */
    protected function createReadOnlyRegistry(): ReadableArtifactRegistryInterface
    {
        return new class implements ReadableArtifactRegistryInterface {
            public function id(): string
            {
                return 'readonly';
            }

            public function name(): string
            {
                return 'ReadOnly';
            }

            public function createReadableArtifact(
                ContainerInterface $container,
                ProcessEntityInterface $entity
            ): ReadableArtifactInterface {
                throw new \RuntimeException('Not implemented for testing.');
            }

            public function createRepository(ContainerInterface $container): RepositoryInterface
            {
                throw new \RuntimeException('Not implemented for testing.');
            }

            public function mapEntity(mixed $raw, ContainerInterface $container): RestoreEntityInterface
            {
                throw new \RuntimeException('Not implemented for testing.');
            }
        };
    }

    protected function createProcessEntity(array $artifacts = ['inmemory'], array $meta = []): ProcessEntityInterface
    {
        return new ProcessEntity([
            'id' => 1,
            'type' => 'backup',
            'typeId' => 1,
            'artifacts' => $artifacts,
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

    public function testImplementsWritableArtifactFactoryInterface()
    {
        $app = $this->bootingApp();

        $factory = new WritableArtifactFactory(
            $app->get(ArtifactRegistriesInterface::class),
            $app->container(),
        );

        $this->assertInstanceOf(WritableArtifactFactoryInterface::class, $factory);
    }

    public function testCreateArtifactFromProcessEntityMethodReturnsWritableArtifacts()
    {
        $app = $this->bootingApp();

        $factory = new WritableArtifactFactory(
            $app->get(ArtifactRegistriesInterface::class),
            $app->container(),
        );

        $processEntity = $this->createProcessEntity(['inmemory'], ['encrypt' => '0']);

        $artifact = $factory->createArtifactFromProcessEntity($processEntity);

        $this->assertInstanceOf(WritableArtifacts::class, $artifact);

        $inner = $artifact->artifacts();
        $this->assertCount(1, $inner);
        $this->assertInstanceOf(WritableInMemoryArtifact::class, $inner[0]);
    }

    public function testCreateArtifactFromProcessEntityMethodSkipsUnregisteredArtifactId()
    {
        $app = $this->bootingApp();

        $factory = new WritableArtifactFactory(
            $app->get(ArtifactRegistriesInterface::class),
            $app->container(),
        );

        $processEntity = $this->createProcessEntity(['does-not-exist']);

        $artifact = $factory->createArtifactFromProcessEntity($processEntity);

        $this->assertInstanceOf(WritableArtifacts::class, $artifact);
        $this->assertCount(0, $artifact->artifacts());
    }

    public function testCreateArtifactFromProcessEntityMethodSkipsNonWritableRegistry()
    {
        $this->onCreateApp(function (AppInterface $app) {
            $app->on(
                ArtifactRegistriesInterface::class,
                function (ArtifactRegistriesInterface $registries): void {
                    $registries->add(new App\InMemoryArtifactRegistry());
                    $registries->add($this->createReadOnlyRegistry());
                }
            );
        });

        $app = $this->bootingApp();

        $factory = new WritableArtifactFactory(
            $app->get(ArtifactRegistriesInterface::class),
            $app->container(),
        );

        // Requests both a writable and a read-only registry id - only
        // the writable one should produce an entry.
        $processEntity = $this->createProcessEntity(['inmemory', 'readonly']);

        $artifact = $factory->createArtifactFromProcessEntity($processEntity);

        $this->assertCount(1, $artifact->artifacts());
        $this->assertInstanceOf(WritableInMemoryArtifact::class, $artifact->artifacts()[0]);
    }

    public function testCreateArtifactFromProcessEntityMethodReturnsEncryptedArtifact()
    {
        $app = $this->bootingApp();

        $factory = new WritableArtifactFactory(
            $app->get(ArtifactRegistriesInterface::class),
            $app->container(),
        );

        $processEntity = $this->createProcessEntity(['inmemory'], ['encrypt' => '1']);

        $artifact = $factory->createArtifactFromProcessEntity($processEntity);

        $inner = $artifact->artifacts();
        $this->assertCount(1, $inner);
        $this->assertInstanceOf(EncryptionWritableArtifact::class, $inner[0]);
    }

    public function testCreateArtifactFromProcessEntityMethodUsesMetaEncrypter()
    {
        $app = $this->bootingApp();

        $factory = new WritableArtifactFactory(
            $app->get(ArtifactRegistriesInterface::class),
            $app->container(),
        );

        $processEntity = $this->createProcessEntity(['inmemory'], [
            'encrypt' => '1',
            'encrypter' => 'default',
        ]);

        $artifact = $factory->createArtifactFromProcessEntity($processEntity);

        $inner = $artifact->artifacts();
        $this->assertInstanceOf(EncryptionWritableArtifact::class, $inner[0]);

        $encrypters = $app->get(EncryptersInterface::class);
        $expected = $encrypters->get('default');

        $this->assertInstanceOf(get_class($expected), $expected);
    }

    public function testCreateArtifactFromProcessEntityMethodUsesRuntimeEncrypter()
    {
        $app = $this->bootingApp();

        $factory = new WritableArtifactFactory(
            $app->get(ArtifactRegistriesInterface::class),
            $app->container(),
        );

        $processEntity = $this->createProcessEntity(['inmemory'], [
            'encrypt' => '1',
        ]);

        $artifact = $factory->createArtifactFromProcessEntity($processEntity);

        $inner = $artifact->artifacts();
        $this->assertInstanceOf(EncryptionWritableArtifact::class, $inner[0]);

        $runtime = $app->get(BackupRuntimeConfigInterface::class);
        $encrypters = $app->get(EncryptersInterface::class);

        $expected = $encrypters->get($runtime->encrypter());

        $this->assertInstanceOf(get_class($expected), $expected);
    }
}