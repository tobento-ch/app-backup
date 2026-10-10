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

namespace Tobento\App\Backup\Test\Feature\Process;

use Tobento\App\AppInterface;
use Tobento\App\Backup\Process\DeleteProcessInterface;
use Tobento\App\Backup\Process\FileStorageDeleteProcess;
use Tobento\Service\FileStorage\FileInterface;
use Tobento\Service\FileStorage\FolderInterface;

class FileStorageDeleteProcessTest extends \Tobento\App\Testing\TestCase
{
    use \Tobento\App\Testing\FileStorage\RefreshFileStorages;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);
        return $app;
    }

    public function testThatImplementsInterface()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();

        $storage = $fileStorage->storage(name: 'uploads-private');

        $process = new FileStorageDeleteProcess(
            storage: $storage,
            rootFolder: 'backup-folder'
        );
        
        $this->assertInstanceof(DeleteProcessInterface::class, $process);
    }
    
    public function testGetterMethods()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();

        $storage = $fileStorage->storage(name: 'uploads-private');

        $process = new FileStorageDeleteProcess(
            storage: $storage,
            rootFolder: 'backup-folder'
        );

        $this->assertSame(
            $app->get(\Tobento\Service\FileStorage\StoragesInterface::class)->get('uploads-private'),
            $process->storage()
        );

        $this->assertSame('backup-folder', $process->rootFolder());
    }

    public function testHasRemainingItemsReturnsMethodTrueUntilRootFolderDeleted()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();

        $storage = $fileStorage->storage(name: 'uploads-private');
        $folder = 'backup-'.uniqid();

        $storage->write(path: $folder.'/a.txt', content: 'aaa');

        $process = new FileStorageDeleteProcess(storage: $storage, rootFolder: $folder);

        $this->assertTrue($process->hasRemainingItems());

        // Delete file
        $item = $process->nextItem();
        $process->deleteItem($item);

        // Delete root folder
        $item = $process->nextItem();
        $process->deleteItem($item);

        $this->assertFalse($process->hasRemainingItems());
    }

    public function testNextItemMethodReturnsFilesInsideNestedFoldersFirst()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();

        $storage = $fileStorage->storage(name: 'uploads-private');
        $folder = 'backup-'.uniqid();

        // Structure:
        // backup-X/
        //   nested/
        //     inner.txt
        //   root.txt
        $storage->write(path: $folder.'/nested/inner.txt', content: 'inner');
        $storage->write(path: $folder.'/root.txt', content: 'root');

        $process = new FileStorageDeleteProcess(storage: $storage, rootFolder: $folder);

        // First item must be inner.txt (nested file)
        $item1 = $process->nextItem();
        $this->assertInstanceOf(FileInterface::class, $item1);
        $this->assertSame('backup-'.$folderSuffix = substr($folder, strlen('backup-')).'/nested/inner.txt', $folder.'/nested/inner.txt');

        // Delete nested file
        $process->deleteItem($item1);

        // Next item must be the nested folder (now empty)
        $item2 = $process->nextItem();
        $this->assertInstanceOf(FolderInterface::class, $item2);

        // Delete nested folder
        $process->deleteItem($item2);

        // Next item must be root.txt
        $item3 = $process->nextItem();
        $this->assertInstanceOf(FileInterface::class, $item3);

        // Delete root file
        $process->deleteItem($item3);

        // Finally root folder
        $item4 = $process->nextItem();
        $this->assertSame($folder, $item4);

        // Delete root folder
        $process->deleteItem($item4);

        $this->assertFalse($process->hasRemainingItems());
    }

    public function testDeleteItemMethodActuallyDeletesFilesAndFolders()
    {
        $fileStorage = $this->fakeFileStorage();
        $app = $this->bootingApp();

        $storage = $fileStorage->storage(name: 'uploads-private');
        $folder = 'backup-'.uniqid();

        $storage->write(path: $folder.'/a.txt', content: 'aaa');
        $storage->write(path: $folder.'/nested/b.txt', content: 'bbb');

        $process = new FileStorageDeleteProcess(storage: $storage, rootFolder: $folder);

        // Delete nested file
        $item = $process->nextItem();
        $this->assertInstanceOf(FileInterface::class, $item);
        $process->deleteItem($item);

        $this->assertFalse($storage->exists($folder.'/nested/b.txt'));

        // Delete nested folder
        $item = $process->nextItem();
        $this->assertInstanceOf(FolderInterface::class, $item);
        $process->deleteItem($item);

        $this->assertFalse($storage->folderExists($folder.'/nested'));

        // Delete root file
        $item = $process->nextItem();
        $this->assertInstanceOf(FileInterface::class, $item);
        $process->deleteItem($item);

        $this->assertFalse($storage->exists($folder.'/a.txt'));

        // Delete root folder
        $item = $process->nextItem();
        $this->assertSame($folder, $item);
        $process->deleteItem($item);

        $this->assertFalse($storage->folderExists($folder));
    }
}