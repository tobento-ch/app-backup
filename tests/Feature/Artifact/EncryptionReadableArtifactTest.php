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

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\AppInterface;
use Tobento\App\Backup\Artifact\EncryptionReadableArtifact;
use Tobento\App\Backup\Artifact\ReadableArtifactInterface;
use Tobento\App\Backup\Exception\FileNotFoundException;
use Tobento\App\Backup\File\DecryptedFile;
use Tobento\App\Backup\File\DecryptedFiles;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Test\App;
use Tobento\App\Backup\Test\App\ReadableInMemoryArtifact;
use Tobento\Service\Encryption\EncrypterInterface;

class EncryptionReadableArtifactTest extends \Tobento\App\Testing\TestCase
{
    use App\Traits\InMemoryArtifactHelpers;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');

        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        // Register InMemory artifact registry
        $app->on(
            ArtifactRegistriesInterface::class,
            static function(ArtifactRegistriesInterface $registries): void {
                $registries->add(new App\InMemoryArtifactRegistry());
            }
        );

        return $app;
    }

    protected function createInnerArtifact(AppInterface $app): ReadableInMemoryArtifact
    {
        $inner = new ReadableInMemoryArtifact(
            streamFactory: $app->get(StreamFactoryInterface::class),
            id: 'folder',
        );
        $inner->seedFile('plain.txt', 'hello-world');
        return $inner;
    }

    protected function createEncryptedInnerArtifact(AppInterface $app): ReadableInMemoryArtifact
    {
        $inner = new ReadableInMemoryArtifact(
            streamFactory: $app->get(StreamFactoryInterface::class),
        );

        $encrypter = $app->get(EncrypterInterface::class);
        $encrypted = $encrypter->encrypt('secret-data');

        $inner->seedFile('encrypted.txt', $encrypted);

        return $inner;
    }

    protected function createEncryptionArtifact(AppInterface $app, ReadableArtifactInterface $inner): EncryptionReadableArtifact
    {
        return new EncryptionReadableArtifact(
            inner: $inner,
            streamFactory: $app->get(StreamFactoryInterface::class),
            encrypter: $app->get(EncrypterInterface::class),
        );
    }
    
    public function testImplementsReadableArtifactInterface()
    {
        $app = $this->bootingApp();

        $inner = $this->createInnerArtifact($app);

        $artifact = $this->createEncryptionArtifact($app, $inner);

        $this->assertInstanceOf(ReadableArtifactInterface::class, $artifact);
    }

    public function testIdMethodReturnsInnerArtifactId()
    {
        $app = $this->bootingApp();

        $inner = $this->createInnerArtifact($app);
        $artifact = $this->createEncryptionArtifact($app, $inner);

        $this->assertSame('folder', $artifact->id());
    }
    
    public function testAdaptedReturnsInnerArtifact()
    {
        $app = $this->bootingApp();

        $inner = $this->createInnerArtifact($app);
        $artifact = $this->createEncryptionArtifact($app, $inner);

        $this->assertSame($inner, $artifact->adapted());
    }

    public function testCanReadFileDelegatesToInnerArtifact()
    {
        $app = $this->bootingApp();

        $inner = $this->createInnerArtifact($app);
        $artifact = $this->createEncryptionArtifact($app, $inner);

        $result = $artifact->canReadFile('plain.txt', 100, 5, 10);

        $this->assertEquals(
            $inner->canReadFile('plain.txt', 100, 5, 10),
            $result
        );
    }

    public function testFilesReturnsDecryptedFilesWrapper()
    {
        $app = $this->bootingApp();

        $inner = $this->createEncryptedInnerArtifact($app);
        $artifact = $this->createEncryptionArtifact($app, $inner);

        $files = $artifact->files('', true);

        $this->assertInstanceOf(DecryptedFiles::class, $files);

        $paths = [];
        foreach ($files as $file) {
            $paths[] = $file->path();
        }

        $this->assertSame(['encrypted.txt'], $paths);
    }

    public function testFileReturnsDecryptedFileWrapper()
    {
        $app = $this->bootingApp();

        $inner = $this->createEncryptedInnerArtifact($app);
        $artifact = $this->createEncryptionArtifact($app, $inner);

        $file = $artifact->file('encrypted.txt');

        $this->assertInstanceOf(DecryptedFile::class, $file);
        $this->assertSame('encrypted.txt', $file->path());
    }

    public function testFileThrowsWhenInnerThrows()
    {
        $app = $this->bootingApp();

        $inner = new ReadableInMemoryArtifact(
            streamFactory: $app->get(StreamFactoryInterface::class),
        ); // empty
        $artifact = $this->createEncryptionArtifact($app, $inner);

        $this->expectException(FileNotFoundException::class);

        $artifact->file('missing.txt');
    }

    public function testDecryptedFileReturnsDecryptedContent()
    {
        $app = $this->bootingApp();

        $inner = $this->createEncryptedInnerArtifact($app);
        $artifact = $this->createEncryptionArtifact($app, $inner);

        $file = $artifact->file('encrypted.txt');

        $content = $file->stream()->getContents();

        $this->assertSame('secret-data', $content);
    }

    public function testDecryptedFilesReturnDecryptedContent()
    {
        $app = $this->bootingApp();

        $inner = $this->createEncryptedInnerArtifact($app);
        $artifact = $this->createEncryptionArtifact($app, $inner);

        $files = $artifact->files('', true);

        $file = iterator_to_array($files)[0];

        $content = $file->stream()->getContents();

        $this->assertSame('secret-data', $content);
    }
}