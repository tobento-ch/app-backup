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

namespace Tobento\App\Backup\Test\File;

use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\File\File;
use Tobento\App\Backup\File\FileInterface;
use Tobento\App\Backup\Test\Factory;

class FileTest extends TestCase
{
    public function testThatImplementsFileInterface()
    {
        $streamFactory = Factory::createStreamFactory();

        $file = new File(
            path: 'folder/data.txt',
            stream: $streamFactory->createStream('CONTENT'),
            mimeType: 'text/plain',
            size: 6,
        );

        $this->assertInstanceOf(FileInterface::class, $file);
    }

    public function testPathMethodParsesCorrectly()
    {
        $streamFactory = Factory::createStreamFactory();

        $file = new File(
            path: 'folder/sub/data.TXT',
            stream: $streamFactory->createStream('X'),
            mimeType: 'text/plain',
            size: 1,
        );

        $this->assertSame('folder/sub/data.TXT', $file->path());
        $this->assertSame('data.TXT', $file->name());
        $this->assertSame('data', $file->filename());
        $this->assertSame('txt', $file->extension());
    }

    public function testFolderPathMethodReturnsCorrectFolder()
    {
        $streamFactory = Factory::createStreamFactory();

        $file = new File(
            path: 'folder/sub/data.txt',
            stream: $streamFactory->createStream('X'),
            mimeType: 'text/plain',
            size: 1,
        );

        $this->assertSame('folder/sub', $file->folderPath());
    }

    public function testFolderPathMethodReturnsEmptyForRootFile()
    {
        $streamFactory = Factory::createStreamFactory();

        $file = new File(
            path: 'data.txt',
            stream: $streamFactory->createStream('X'),
            mimeType: 'text/plain',
            size: 1,
        );

        $this->assertSame('', $file->folderPath());
    }

    public function testStreamMethodReturnsDirectStream()
    {
        $streamFactory = Factory::createStreamFactory();

        $stream = $streamFactory->createStream('ABC');

        $file = new File(
            path: 'data.txt',
            stream: $stream,
            mimeType: 'text/plain',
            size: 3,
        );

        $this->assertSame('ABC', $file->stream()->getContents());
    }

    public function testStreamMethodEvaluatesClosureLazy()
    {
        $streamFactory = Factory::createStreamFactory();

        $file = new File(
            path: 'lazy.txt',
            stream: function () use ($streamFactory) {
                return $streamFactory->createStream('LAZY');
            },
            mimeType: 'text/plain',
            size: 4,
        );

        $this->assertSame('LAZY', $file->stream()->getContents());
    }

    public function testMimeTypeMethodReturnsCorrectMimeType()
    {
        $streamFactory = Factory::createStreamFactory();

        $file = new File(
            path: 'image.png',
            stream: $streamFactory->createStream('IMG'),
            mimeType: 'image/png',
            size: 3,
        );

        $this->assertSame('image/png', $file->mimeType());
    }

    public function testSizeMethodReturnsCorrectSize()
    {
        $streamFactory = Factory::createStreamFactory();

        $file = new File(
            path: 'data.bin',
            stream: $streamFactory->createStream('12345'),
            mimeType: 'application/octet-stream',
            size: 5,
        );

        $this->assertSame(5, $file->size());
    }
}