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

namespace Tobento\App\Backup\Test\Manifest;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Tobento\App\Backup\Action\Actions;
use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\Exception\ManifestException;
use Tobento\App\Backup\Manifest\ManifestFactory;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\Service\Clock\FrozenClock;
use Tobento\Service\Container\Container;
use Tobento\Service\Uri\BaseUri;
use Tobento\Service\Uri\BaseUriInterface;

class ManifestFactoryTest extends TestCase
{
    protected function createFrozenClock(string $time = '2026-01-01 12:00:00'): ClockInterface
    {
        return new FrozenClock(now: new \DateTimeImmutable($time));
    }

    protected function createBaseUri(string $host = 'example.com'): BaseUriInterface
    {
        $uri = new \Nyholm\Psr7\Factory\Psr17Factory()->createUri('https://'.$host.'/');

        return new \Tobento\Service\Uri\BaseUri($uri);
    }

    protected function createContainer(
        ?ClockInterface $clock = null,
        ?BaseUriInterface $baseUri = null,
    ): Container {
        $container = new Container();
        $container->set(ClockInterface::class, $clock ?? $this->createFrozenClock());
        $container->set(BaseUriInterface::class, $baseUri ?? $this->createBaseUri());

        return $container;
    }

    protected function createBackupProcess(
        int $processId = 1,
        string $name = 'My Backup',
        null|int|string $userId = null,
        array $meta = [],
    ): BackupProcessInterface {
        return new class($processId, $name, $userId, $meta) implements BackupProcessInterface {
            public function __construct(
                private int $processId,
                private string $name,
                private null|int|string $userId,
                private array $meta,
            ) {}

            public function id(): int { return $this->processId; }
            public function name(): ?string { return $this->name; }
            public function userId(): null|int|string { return $this->userId; }
            public function meta(): array { return $this->meta; }

            public function actions(): \Tobento\App\Backup\Action\ActionsInterface
            {
                return new \Tobento\App\Backup\Action\Actions(
                    new \Tobento\App\Backup\Action\NullAction(id: 'test.action')
                );
            }

            public function hooks(): array { return []; }

            public function artifact(): \Tobento\App\Backup\Artifact\WritableArtifactInterface
            {
                throw new \RuntimeException('not implemented - unused by ManifestFactory');
            }

            public function isActionFinished(\Tobento\App\Backup\Action\ActionInterface $action): bool { return false; }
            public function markActionFinished(\Tobento\App\Backup\Action\ActionInterface $action): void {}
            public function allActionsFinished(): bool { return false; }
            public function stateFor(\Tobento\App\Backup\Action\ActionInterface $action): array { return []; }
            public function updateStateFor(\Tobento\App\Backup\Action\ActionInterface $action, array $state): void {}
        };
    }

    public function testCreateManifestFromBackupProcessMethod()
    {
        $factory = new ManifestFactory(
            $this->createContainer(
                clock: $this->createFrozenClock('2026-06-15 08:30:00'),
                baseUri: $this->createBaseUri('backups.example.com'),
            )
        );

        $process = $this->createBackupProcess(
            processId: 42,
            name: 'Nightly Backup',
            userId: 7,
            meta: ['encrypt' => '1'],
        );

        $manifest = $factory->createManifestFromBackupProcess($process);

        $this->assertSame(42, $manifest->processId());
        $this->assertSame('Nightly Backup', $manifest->name());
        $this->assertSame(7, $manifest->userId());
        $this->assertSame('backups.example.com', $manifest->domain());
        $this->assertSame(['encrypt' => '1'], $manifest->meta());
        $this->assertSame(
            '2026-06-15 08:30:00',
            $manifest->createdAt()->format('Y-m-d H:i:s'),
        );
    }

    public function testCreateManifestFromArrayMethod()
    {
        $factory = new ManifestFactory($this->createContainer());

        $manifest = $factory->createManifestFromArray([
            'process_id' => 5,
            'created_at' => '2026-03-10 10:00:00',
            'actions' => [['class' => NullAction::class, 'data' => ['id' => 'a']]],
            'name' => 'Restored Backup',
            'user_id' => 3,
            'domain' => 'example.com',
            'meta' => ['encrypt' => '0'],
        ]);

        $this->assertSame(5, $manifest->processId());
        $this->assertSame('Restored Backup', $manifest->name());
        $this->assertSame(3, $manifest->userId());
        $this->assertSame('example.com', $manifest->domain());
        $this->assertSame(['encrypt' => '0'], $manifest->meta());
        $this->assertSame(
            '2026-03-10 10:00:00',
            $manifest->createdAt()->format('Y-m-d H:i:s'),
        );
    }
    
    public function testCreateManifestFromArrayMethodFailsOnMissingProcessId()
    {
        $factory = new ManifestFactory($this->createContainer());

        $this->expectException(ManifestException::class);

        $factory->createManifestFromArray([]);
    }

    public function testCreateManifestFromArrayMethodUsesDefaultsForMissingKeys()
    {
        $factory = new ManifestFactory($this->createContainer());

        $manifest = $factory->createManifestFromArray(['process_id' => 2]);

        $this->assertSame(2, $manifest->processId());
        $this->assertNull($manifest->name());
        $this->assertNull($manifest->userId());
        $this->assertNull($manifest->domain());
        $this->assertSame([], $manifest->meta());
        $this->assertSame([], $manifest->actions());
    }

    public function testCreateManifestFromJsonStringMethod()
    {
        $factory = new ManifestFactory($this->createContainer());

        $json = json_encode([
            'process_id' => 9,
            'name' => 'From JSON',
        ]);

        $manifest = $factory->createManifestFromJsonString($json);

        $this->assertSame(9, $manifest->processId());
        $this->assertSame('From JSON', $manifest->name());
    }

    public function testCreateManifestFromJsonStringMethodThrowsOnInvalidJson()
    {
        $factory = new ManifestFactory($this->createContainer());

        $this->expectException(ManifestException::class);

        $factory->createManifestFromJsonString('{invalid json}');
    }

    public function testCreateManifestFromJsonStringMethodThrowsWhenNotArray()
    {
        $factory = new ManifestFactory($this->createContainer());

        $this->expectException(ManifestException::class);

        $factory->createManifestFromJsonString('"just-a-string"');
    }
}