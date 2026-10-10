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

namespace Tobento\App\Backup\Process;

use Tobento\Service\FileStorage\StorageInterface;
use Tobento\Service\FileStorage\FileInterface;
use Tobento\Service\FileStorage\FolderInterface;

/**
 * Delete process for file‑storage artifacts, removing files and folders
 * within the configured root directory.
 */
final class FileStorageDeleteProcess implements DeleteProcessInterface
{
    private array $folders = [];
    private bool $foldersLoaded = false;

    /**
     * Create a new instance.
     *
     * @param StorageInterface $storage
     * @param string $rootFolder
     */
    public function __construct(
        private StorageInterface $storage,
        private string $rootFolder,
    ) {}
    
    /**
     * Returns the storage.
     *
     * @return StorageInterface
     */
    public function storage(): StorageInterface
    {
        return $this->storage;
    }

    /**
     * Returns the root folder.
     *
     * @return string
     */
    public function rootFolder(): string
    {
        return $this->rootFolder;
    }

    /**
     * Determine if there are remaining items to delete.
     *
     * @return bool
     */
    public function hasRemainingItems(): bool
    {
        return $this->storage->folderExists($this->rootFolder);
    }

    /**
     * Get the next item to delete.
     *
     * @return mixed The next item to delete.
     */
    public function nextItem(): mixed
    {
        // Load folder list once (recursive)
        if (! $this->foldersLoaded) {
            $this->folders = $this->storage->folders(
                path: $this->rootFolder,
                recursive: true
            )->all();
            $this->foldersLoaded = true;
        }

        // 1. Delete nested folders first
        if (!empty($this->folders)) {
            $folder = $this->folders[0];

            // Fetch files inside this folder (non-recursive!)
            $files = $this->storage->files(
                path: $folder->path(),
                recursive: false
            )->all();

            if (!empty($files)) {
                return $files[0]; // FileInterface
            }

            // Folder empty: delete folder
            array_shift($this->folders);
            return $folder; // FolderInterface
        }

        // 2. Delete files in root folder
        $files = $this->storage->files(
            path: $this->rootFolder,
            recursive: false
        )->all();

        if (!empty($files)) {
            return $files[0];
        }

        // 3. Delete root folder
        return $this->rootFolder;
    }

    /**
     * Delete the specified item.
     *
     * @param mixed $item The item to delete.
     * @return void
     */
    public function deleteItem(mixed $item): void
    {
        if ($item instanceof FileInterface) {
            $this->storage->delete($item->path());
            return;
        }

        if ($item instanceof FolderInterface) {
            $this->storage->deleteFolder($item->path());
            return;
        }

        if (is_string($item)) {
            $this->storage->deleteFolder($item);
        }
    }
}