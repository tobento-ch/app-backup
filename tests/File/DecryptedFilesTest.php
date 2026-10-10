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
use Tobento\App\Backup\File\DecryptedFiles;
use Tobento\App\Backup\File\DecryptedFile;
use Tobento\App\Backup\File\File;
use Tobento\App\Backup\File\Files;
use Tobento\App\Backup\File\FilesInterface;
use Tobento\App\Backup\Test\Factory;

class DecryptedFilesTest extends TestCase
{
    public function testThatImplementsFilesInterface()
    {
        $streamFactory = Factory::createStreamFactory();
        $encrypter = Factory::createEncrypter();

        $files = new Files(new \ArrayIterator([]));

        $dfiles = new DecryptedFiles($files, $encrypter, $streamFactory);

        $this->assertInstanceOf(\Tobento\App\Backup\File\FilesInterface::class, $dfiles);
    }
    
    public function testFilesManifestJsonMethodIsNotDecrypted()
    {
        $streamFactory = Factory::createStreamFactory();
        $encrypter = Factory::createEncrypter();

        $files = new Files(new \ArrayIterator([
            new File(
                path: 'manifest.json',
                stream: $streamFactory->createStream('PLAIN'),
                mimeType: 'application/json',
                size: 5,
            ),
        ]));

        $dfiles = new DecryptedFiles($files, $encrypter, $streamFactory);

        $file = iterator_to_array($dfiles)[0];

        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('PLAIN', $file->stream()->getContents());
    }

    public function testFilesMethodDecryptsEncryptedFile()
    {
        $streamFactory = Factory::createStreamFactory();
        $encrypter = Factory::createEncrypter();

        $encrypted = $encrypter->encrypt('HELLO');

        $files = new Files(new \ArrayIterator([
            new File(
                path: 'data.txt',
                stream: $streamFactory->createStream($encrypted),
                mimeType: 'text/plain',
                size: strlen($encrypted),
            ),
        ]));

        $dfiles = new DecryptedFiles($files, $encrypter, $streamFactory);

        $file = iterator_to_array($dfiles)[0];

        $this->assertInstanceOf(DecryptedFile::class, $file);
        $this->assertSame('HELLO', $file->stream()->getContents());
    }

    public function testFilesMethodHandlesMixedFilesCorrectly()
    {
        $streamFactory = Factory::createStreamFactory();
        $encrypter = Factory::createEncrypter();

        $encrypted = $encrypter->encrypt('SECRET');

        $files = new Files(new \ArrayIterator([
            new File(
                path: 'manifest.json',
                stream: $streamFactory->createStream('PLAIN'),
                mimeType: 'application/json',
                size: 5,
            ),
            new File(
                path: 'data.txt',
                stream: $streamFactory->createStream($encrypted),
                mimeType: 'text/plain',
                size: strlen($encrypted),
            ),
        ]));

        $dfiles = new DecryptedFiles($files, $encrypter, $streamFactory);

        $items = iterator_to_array($dfiles);

        $this->assertInstanceOf(File::class, $items[0]);
        $this->assertSame('PLAIN', $items[0]->stream()->getContents());

        $this->assertInstanceOf(DecryptedFile::class, $items[1]);
        $this->assertSame('SECRET', $items[1]->stream()->getContents());
    }

    public function testFilesFilterMethodFiltersCorrectly()
    {
        $streamFactory = Factory::createStreamFactory();
        $encrypter = Factory::createEncrypter();

        $encrypted = $encrypter->encrypt('DATA');

        $files = new Files(new \ArrayIterator([
            new File(
                path: 'manifest.json',
                stream: $streamFactory->createStream('PLAIN'),
                mimeType: 'application/json',
                size: 5,
            ),
            new File(
                path: 'data.txt',
                stream: $streamFactory->createStream($encrypted),
                mimeType: 'text/plain',
                size: strlen($encrypted),
            ),
        ]));

        $dfiles = new DecryptedFiles($files, $encrypter, $streamFactory);

        $filtered = $dfiles->filter(fn($file) => $file->path() === 'data.txt');

        $items = iterator_to_array($filtered);

        // Strict assertions prevent manifest.json from slipping through
        $this->assertCount(1, $items);
        $this->assertSame('data.txt', $items[0]->path());
        $this->assertInstanceOf(DecryptedFile::class, $items[0]);
        $this->assertSame('DATA', $items[0]->stream()->getContents());
    }
}