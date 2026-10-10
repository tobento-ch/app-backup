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
use Tobento\App\Backup\File\DecryptedFile;
use Tobento\App\Backup\File\File;
use Tobento\App\Backup\File\FileInterface;
use Tobento\App\Backup\Test\Factory;

class DecryptedFileTest extends TestCase
{
    public function testThatImplementsFileInterface()
    {
        $streamFactory = Factory::createStreamFactory();
        $encrypter = Factory::createEncrypter();

        $file = new File(
            path: 'data.txt',
            stream: $streamFactory->createStream('CONTENT'),
            mimeType: 'text/plain',
            size: 7,
        );

        $df = new DecryptedFile($file, $encrypter, $streamFactory);

        $this->assertInstanceOf(\Tobento\App\Backup\File\FileInterface::class, $df);
    }
    
    public function testManifestJsonIsNotDecrypted()
    {
        $streamFactory = Factory::createStreamFactory();
        $encrypter = Factory::createEncrypter();

        $file = new File(
            path: 'manifest.json',
            stream: $streamFactory->createStream('PLAIN'),
            mimeType: 'application/json',
            size: 5,
        );

        $df = new DecryptedFile($file, $encrypter, $streamFactory);

        $this->assertSame('PLAIN', $df->stream()->getContents());
    }

    public function testEncryptedFileIsDecrypted()
    {
        $streamFactory = Factory::createStreamFactory();
        $encrypter = Factory::createEncrypter();

        $encrypted = $encrypter->encrypt('HELLO WORLD');

        $file = new File(
            path: 'data.txt',
            stream: $streamFactory->createStream($encrypted),
            mimeType: 'text/plain',
            size: strlen($encrypted),
        );

        $df = new DecryptedFile($file, $encrypter, $streamFactory);

        $this->assertSame('HELLO WORLD', $df->stream()->getContents());
    }

    public function testMetadataDelegation()
    {
        $streamFactory = Factory::createStreamFactory();
        $encrypter = Factory::createEncrypter();

        $file = new File(
            path: 'folder/data.txt',
            stream: $streamFactory->createStream('CONTENT'),
            mimeType: 'text/plain',
            size: 7,
        );

        $df = new DecryptedFile($file, $encrypter, $streamFactory);

        $this->assertSame('folder/data.txt', $df->path());
        $this->assertSame('data.txt', $df->name());
        $this->assertSame('data', $df->filename());
        $this->assertSame('txt', $df->extension());
        $this->assertSame('folder', $df->folderPath());
        $this->assertSame('text/plain', $df->mimeType());
        $this->assertSame(7, $df->size());
    }
}