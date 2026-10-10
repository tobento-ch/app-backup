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
use Tobento\App\Backup\File\Files;
use Tobento\App\Backup\File\File;
use Tobento\App\Backup\File\FileInterface;
use Tobento\App\Backup\File\FilesInterface;
use Tobento\App\Backup\Test\Factory;

class FilesTest extends TestCase
{
    public function testThatImplementsFilesInterface()
    {
        $files = new Files(new \ArrayIterator([]));

        $this->assertInstanceOf(FilesInterface::class, $files);
    }

    public function testIteratorMethodReturnsFiles()
    {
        $streamFactory = Factory::createStreamFactory();

        $fileA = new File('a.txt', $streamFactory->createStream('A'), 'text/plain', 1);
        $fileB = new File('b.txt', $streamFactory->createStream('B'), 'text/plain', 1);

        $files = new Files(new \ArrayIterator([$fileA, $fileB]));

        $items = iterator_to_array($files);

        $this->assertCount(2, $items);
        $this->assertSame($fileA, $items[0]);
        $this->assertSame($fileB, $items[1]);
    }

    public function testFilterMethodFiltersCorrectly()
    {
        $streamFactory = Factory::createStreamFactory();

        $fileA = new File('a.txt', $streamFactory->createStream('A'), 'text/plain', 1);
        $fileB = new File('b.txt', $streamFactory->createStream('B'), 'text/plain', 1);

        $files = new Files(new \ArrayIterator([$fileA, $fileB]));

        $filtered = $files->filter(fn(FileInterface $f) => $f->path() === 'b.txt');

        $items = iterator_to_array($filtered);

        $this->assertCount(1, $items);
        $this->assertSame($fileB, $items[0]);
    }
}