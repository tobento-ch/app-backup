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

namespace Tobento\App\Backup\Action;

use Psr\Container\ContainerInterface;
use Tobento\App\AppInterface;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Backup\Service\AppFinder;
use Tobento\App\Crud\Action\ActionInterface as CrudActionInterface;
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Input\InputInterface;
use function Tobento\App\Translation\trans;

/**
 * Directory
 *
 * Low‑level backup/restore engine for a single resolved directory path.
 *
 * This action is intentionally NOT used as a top‑level registry action.
 * It uses absolute directory paths and therefore cannot be safely
 * referenced from configuration or reused across different applications.
 *
 * Instead, higher‑level actions such as AppsDirectoryAction::class
 * or AppDirectoryAction::class resolve logical
 * directory keys (e.g. "app", "vendor", "public") into absolute paths using
 * the application's directory registry, and then delegate the actual
 * backup/restore work to DirectoryAction.
 *
 * DirectoryAction encapsulates:
 * - directory traversal
 * - time‑budget enforcement
 * - offset tracking
 * - feasibility checks (Yes / Defer / NeverFits / Skip)
 * - safe target path construction
 *
 * Its purpose is to provide a reusable, isolated implementation of the
 * backup/restore loop so that higher‑level actions do not duplicate logic.
 */
class Directory implements ActionInterface
{
    /**
     * Create a new instance.
     *
     * @param string $id
     * @param string $dir
     * @param string $targetFolder
     * @param array<array-key, string> $onlyDirs
     * @param array<array-key, string> $exceptDirs
     * @param int $timeBudgetInSeconds
     */
    final public function __construct(
        protected string $id,
        protected string $dir,
        protected string $targetFolder,
        protected array $onlyDirs = [],
        protected array $exceptDirs = [],
        protected int $timeBudgetInSeconds = 20,
    ) {
        if ($this->targetFolder === '') {
            throw new \InvalidArgumentException('Target folder cannot be an empty string.');
        }

        $this->targetFolder = trim($this->targetFolder, '/'). '/';
    }
    
    /**
     * Unique identifier.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->id;
    }
    
    /**
     * Perform the backup for this action.
     *
     * @param BackupProcessInterface $process Backup process context.
     * @param ContainerInterface $container
     * @return void
     */
    public function backup(BackupProcessInterface $process, ContainerInterface $container): void
    {
        if ($process->isActionFinished(action: $this)) {
            return;
        }

        $artifact = $process->artifact();

        // Load state for this specific DirectoryAction instance
        $state  = $process->stateFor(action: $this);
        $offset = $state['offset'] ?? 0;

        $start = microtime(true);
        $timeBudget  = $this->timeBudgetInSeconds;
        $currentIndex = 0;

        // Nothing to do
        if (!is_dir($this->dir)) {
            $process->markActionFinished(action: $this);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->dir,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $fileInfo) {

            if ($fileInfo->isDir()) {
                continue;
            }

            // Skip until offset
            if ($currentIndex < $offset) {
                $currentIndex++;
                continue;
            }

            $sourcePath = $fileInfo->getPathname();
            $fileSize = $fileInfo->getSize();

            //
            // ---------------------------------------------------------
            // DIRECTORY FILTERING (onlyDirs / exceptDirs)
            // ---------------------------------------------------------
            //

            // Normalize paths
            $normalizedSource = str_replace('\\', '/', $sourcePath);
            $normalizedRoot = rtrim(str_replace('\\', '/', $this->dir), '/');

            // Compute relative path
            $relative = substr($normalizedSource, strlen($normalizedRoot) + 1);

            // Extract directory part
            $relativeDir = dirname($relative);
            if ($relativeDir === '.' || $relativeDir === '') {
                $relativeDir = '';
            }

            // exceptDirs: skip if directory matches any excluded prefix
            foreach ($this->exceptDirs as $excluded) {

                if ($excluded === '*' && $relativeDir !== '') {
                    // exclude all subdirectories
                    $currentIndex++;
                    continue 2;
                }

                if ($excluded !== '*' && str_starts_with($relativeDir, $excluded)) {
                    // exclude matching subdir
                    $currentIndex++;
                    continue 2;
                }
            }

            // onlyDirs: allow only directories matching allowed prefixes
            if (!empty($this->onlyDirs)) {
                $allowed = false;

                foreach ($this->onlyDirs as $allowedDir) {
                    if (str_starts_with($relativeDir, $allowedDir)) {
                        $allowed = true;
                        break;
                    }
                }

                if (!$allowed) {
                    $currentIndex++;
                    continue;
                }
            }

            //
            // ---------------------------------------------------------
            // TIME BUDGET + FEASIBILITY LOGIC
            // ---------------------------------------------------------
            //

            // Remaining time in this window
            $elapsed   = microtime(true) - $start;
            $remaining = (float)$timeBudget - $elapsed;

            // Hard cutoff
            if ($remaining <= 0.0) {
                $state['offset'] = $currentIndex;
                $process->updateStateFor(action: $this, state: $state);
                $artifact->partialPersist();
                return;
            }

            // Ask artifact if it can accept the file now
            $result = $artifact->canWriteFile(
                filePath: $sourcePath,
                fileSize: $fileSize,
                remainingSeconds: $remaining,
                timeBudgetInSeconds: $timeBudget
            );

            if ($result->shouldThrow()) {
                throw new \RuntimeException($result->reason());
            }

            if ($result->shouldSkip()) {

                // Load state
                $state = $process->stateFor(action: $this);

                // Append skip entry
                $state['skipped'][] = [
                    'file' => $relative,
                    'size' => $fileSize,
                    'reason' => $result->reason(),
                    'timestamp' => microtime(true),
                ];

                // Save state
                $process->updateStateFor(action: $this, state: $state);

                $currentIndex++;
                continue;
            }

            if ($result->decision() === FileAcceptDecision::Defer) {
                $state['offset'] = $currentIndex;
                $process->updateStateFor(action: $this, state: $state);
                $artifact->partialPersist();
                return;
            }

            //
            // ---------------------------------------------------------
            // ACCEPT → WRITE FILE
            // ---------------------------------------------------------
            //

            $target = $this->targetFolder . $relative;

            $artifact->writeFileFromPath($target, $sourcePath, $fileSize);

            $currentIndex++;
        }

        // Done
        $process->markActionFinished(action: $this);
    }

    /**
     * Restore the backup for this action.
     *
     * @param RestoreProcessInterface $process Restore process context.
     * @param ContainerInterface $container
     * @return void
     */
    public function restore(RestoreProcessInterface $process, ContainerInterface $container): void
    {
        if ($process->isActionFinished(action: $this)) {
            return;
        }

        $artifact = $process->artifact();

        // Incremental restore state for this DirectoryAction instance
        $state  = $process->stateFor(action: $this);
        $offset = $state['offset'] ?? 0;
        $seen   = 0;

        // Time-window model
        $timeBudgetInSeconds = $this->timeBudgetInSeconds;
        $start = microtime(true);

        // Base directory (absolute)
        $baseDir = rtrim($this->dir, '/');
        $baseDirReal = realpath($baseDir);
        
        if ($baseDirReal === false) {
            // Directory does not exist or cannot be resolved → nothing safe to restore into
            $process->markActionFinished(action: $this);
            return;
        }

        // Iterate over all files stored under this action's targetFolder prefix
        foreach ($artifact->files(path: $this->targetFolder, recursive: true) as $file) {

            // Skip until offset reached
            if ($seen < $offset) {
                $seen++;
                continue;
            }

            // Remaining time in this window
            $elapsed = microtime(true) - $start;
            $remainingSeconds = (float)$timeBudgetInSeconds - $elapsed;

            // Hard cutoff
            if ($remainingSeconds <= 0.0) {
                $state['offset'] = $seen;
                $process->updateStateFor(action: $this, state: $state);
                return;
            }

            // Feasibility check
            $result = $artifact->canReadFile(
                filePath: $file->path(),
                fileSize: $file->size(),
                remainingSeconds: $remainingSeconds,
                timeBudgetInSeconds: $timeBudgetInSeconds
            );

            if ($result->shouldThrow()) {
                throw new \RuntimeException($result->reason());
            }

            if ($result->shouldSkip()) {

                // Load state
                $state = $process->stateFor(action: $this);

                // Append skip entry
                $state['skipped'][] = [
                    'file' => $file->path(),
                    'relative' => substr($file->path(), strlen($this->targetFolder)),
                    'size' => $file->size(),
                    'reason' => $result->reason(),
                    'timestamp' => microtime(true),
                ];

                // Save state
                $process->updateStateFor(action: $this, state: $state);

                $seen++;
                continue;
            }

            if ($result->decision() === FileAcceptDecision::Defer) {
                $state['offset'] = $seen;
                $process->updateStateFor(action: $this, state: $state);
                return;
            }

            // --- SAFE PATH RECONSTRUCTION ---

            // Compute relative path inside this directory action
            $relative = substr($file->path(), strlen($this->targetFolder));

            // Lexical ZIP-slip prevention
            $parts = explode('/', $relative);
            foreach ($parts as $p) {
                if ($p === '' || $p === '..') {
                    $seen++;
                    continue 2; // skip this file entirely
                }
            }

            // Build target path segment-by-segment
            $target = $baseDir;
            foreach ($parts as $p) {
                $target .= '/' . $p;
            }

            // Ensure directory exists before containment check
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Final containment check (defense in depth)
            $dirReal = realpath($dir);
            if ($dirReal === false ||
                !($dirReal === $baseDirReal || str_starts_with($dirReal, $baseDirReal . '/'))
            ) {
                $seen++;
                continue;
            }

            // --- RESTORE FILE ---

            $stream   = $file->stream();
            $resource = fopen($target, 'wb');

            while (!$stream->eof()) {
                fwrite($resource, $stream->read(8192));
            }

            fclose($resource);
            $stream->close();

            $seen++;
        }

        // All files restored
        $process->markActionFinished(action: $this);
    }
    
    /**
     * Configure dynamic restore fields for this action.
     *
     * These fields are shown in the Restore CRUD action and allow the user
     * to configure restore‑time options based on the actual backed‑up data
     * stored in the manifest (e.g. tables in a SQL dump, directories in an
     * apps backup, file lists, flags, etc.).
     *
     * @param CrudActionInterface $action
     * @return iterable<FieldInterface>|FieldsInterface
     */
    public function configureRestoreFields(CrudActionInterface $action): iterable|FieldsInterface
    {
        return [];
    }
    
    /**
     * Create a configured restore action using validated CRUD input.
     *
     * @param InputInterface $input
     * @return static
     */
    public function configureForRestore(InputInterface $input): static
    {
        return clone $this;
    }
    
    /**
     * Returns data that can be serialized into the backup manifest.
     *
     * @return array The action's serializable representation.
     */
    public function jsonSerialize(): array
    {
        return [
            'dir' => $this->dir,
            'targetFolder' => $this->targetFolder,
            'onlyDirs' => $this->onlyDirs,
            'exceptDirs' => $this->exceptDirs,
            'timeBudgetInSeconds' => $this->timeBudgetInSeconds,
        ];
    }
}