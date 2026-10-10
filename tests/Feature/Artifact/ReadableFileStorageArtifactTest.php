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

use Tobento\App\AppInterface;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Artifact\ReadableFileStorageArtifact;
use Tobento\App\Backup\Exception\FileNotFoundException;

class ReadableFileStorageArtifactTest extends \Tobento\App\Testing\TestCase
{
    use \Tobento\App\Testing\FileStorage\RefreshFileStorages;
    
    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        return $app;
    }
    
    public function testGetterMethods()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();
        
        $storage = $fileStorage->storage(name: 'uploads-private');
        
        $artifact = new ReadableFileStorageArtifact(
            storage: $storage,
            folder: 'backup',
            throughputInBytes: 1000,
        );

        $this->assertSame(
            $app->get(\Tobento\Service\FileStorage\StoragesInterface::class)->get('uploads-private'),
            $artifact->storage()
        );
        $this->assertSame('backup', $artifact->id());
        $this->assertSame('backup', $artifact->folder());
        $this->assertSame(1000, $artifact->throughputInBytes());
    }

    public function testCanReadFileMethodReturnsYesWhenWithinBudget()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();

        $storage = $fileStorage->storage(name: 'uploads-private');

        $artifact = new ReadableFileStorageArtifact(
            storage: $storage,
            folder: 'backup',
            throughputInBytes: 1000,
        );

        $result = $artifact->canReadFile(
            filePath: 'a.txt',
            fileSize: 500,
            remainingSeconds: 10.0,
            timeBudgetInSeconds: 10.0,
        );

        $this->assertSame(FileAcceptDecision::Yes, $result->decision());
        $this->assertFalse($result->shouldThrow());
    }

    public function testCanReadFileMethodReturnsDeferWhenNotEnoughTimeRemainingInWindow()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();

        $storage = $fileStorage->storage(name: 'uploads-private');

        $artifact = new ReadableFileStorageArtifact(
            storage: $storage,
            folder: 'backup',
            throughputInBytes: 1000,
        );

        $result = $artifact->canReadFile(
            filePath: 'a.txt',
            fileSize: 5000,
            remainingSeconds: 1.0,
            timeBudgetInSeconds: 10.0,
        );

        $this->assertSame(FileAcceptDecision::Defer, $result->decision());
        $this->assertFalse($result->shouldThrow());
    }

    public function testCanReadFileMethodReturnsNeverFitsWhenExceedsFullTimeBudget()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();

        $storage = $fileStorage->storage(name: 'uploads-private');

        $artifact = new ReadableFileStorageArtifact(
            storage: $storage,
            folder: 'backup',
            throughputInBytes: 1000,
        );

        $result = $artifact->canReadFile(
            filePath: 'a.txt',
            fileSize: 50_000,
            remainingSeconds: 10.0,
            timeBudgetInSeconds: 10.0,
        );

        $this->assertSame(FileAcceptDecision::NeverFits, $result->decision());
        $this->assertTrue($result->shouldThrow());
    }
    
    public function testFilesMethodReturnsFilesUnderFolder()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();
        
        $folder = 'backup-'.uniqid();
        $storage = $fileStorage->storage(name: 'uploads-private');
        $storage->write(path: $folder.'/a.txt', content: 'aaa');
        $storage->write(path: $folder.'/b.txt', content: 'bbb');

        $artifact = new ReadableFileStorageArtifact(storage: $storage, folder: $folder);

        $files = iterator_to_array($artifact->files());

        $this->assertCount(2, $files);

        $contents = [];
        foreach ($files as $file) {
            $contents[$file->path()] = $file->stream()->getContents();
        }

        $this->assertSame('aaa', $contents['a.txt'] ?? null);
        $this->assertSame('bbb', $contents['b.txt'] ?? null);
    }

    public function testFilesMethodReturnsRelativePathsNotAbsolute()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();
        
        $folder = 'backup-'.uniqid();
        $storage = $fileStorage->storage(name: 'uploads-private');
        $storage->write(path: $folder.'/sub/nested.txt', content: 'nested');

        $artifact = new ReadableFileStorageArtifact(storage: $storage, folder: $folder);

        $files = iterator_to_array($artifact->files(recursive: true));
        $paths = array_map(fn($f) => $f->path(), $files);

        // Path should be relative to the artifact's folder, not the
        // absolute storage path (which would include "backup/" prefix).
        $this->assertContains('sub/nested.txt', $paths);
    }

    public function testFilesMethodRespectsRecursiveFalse()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();
        
        $folder = 'backup-'.uniqid();
        $storage = $fileStorage->storage(name: 'uploads-private');
        $storage->write(path: $folder.'/top.txt', content: 'top');
        $storage->write(path: $folder.'/sub/nested.txt', content: 'nested');

        $artifact = new ReadableFileStorageArtifact(storage: $storage, folder: $folder);

        $files = iterator_to_array($artifact->files(recursive: false));
        $paths = array_map(fn($f) => $f->path(), $files);

        $this->assertContains('top.txt', $paths);
        $this->assertNotContains('sub/nested.txt', $paths);
    }
    
    public function testFileMethodReturnsMatchingFile()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();
        
        $folder = 'backup-'.uniqid();
        $storage = $fileStorage->storage(name: 'uploads-private');
        $storage->write(path: $folder.'/a.txt', content: 'aaa');

        $artifact = new ReadableFileStorageArtifact(storage: $storage, folder: $folder);

        $file = $artifact->file('a.txt');

        $this->assertSame('a.txt', $file->path());
        $this->assertSame('aaa', $file->stream()->getContents());
        $this->assertSame(3, $file->size());
    }

    public function testFileMethodThrowsFileNotFoundExceptionWhenMissing()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();

        $storage = $fileStorage->storage(name: 'uploads-private');

        $artifact = new ReadableFileStorageArtifact(storage: $storage, folder: 'backup');

        $this->expectException(FileNotFoundException::class);

        $artifact->file('does-not-exist.txt');
    }
}