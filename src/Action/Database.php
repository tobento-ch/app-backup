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

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\StreamFactoryInterface;
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
use Tobento\Service\Database\Dumper\DatabaseDumpersInterface;
use Tobento\Service\Database\Dumper\DumpTimedOutException;
use Tobento\Service\Database\Dumper\RestoreTimedOutException;
use function Tobento\App\Translation\trans;

class Database implements ActionInterface
{
    /**
     * Create a new instance.
     *
     * @param string $id
     * @param string $database
     * @param array<array-key, string> $tables
     * @param string $targetFolder
     * @param string $filepath
     * @param int $timeBudgetInSeconds
     */
    final public function __construct(
        protected string $id,
        protected string $database,
        protected array $tables,
        protected string $targetFolder,
        protected string $filepath,
        protected int $timeBudgetInSeconds = 20,
    ) {
        if (empty($this->tables)) {
            throw new InvalidArgumentException('No tables specified.');
        }
        
        if ($this->targetFolder === '') {
            throw new InvalidArgumentException('Target folder cannot be an empty string.');
        }
        
        $this->targetFolder = trim($this->targetFolder, '/'). '/';
        
        $this->filepath = trim($this->filepath, '/');
        
        if ($this->filepath === '') {
            $this->filepath = $this->database;
        }
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
    
    public function database(): string
    {
        return $this->database;
    }

    public function tables(): array
    {
        return $this->tables;
    }

    public function targetFolder(): string
    {
        return $this->targetFolder;
    }

    public function filepath(): string
    {
        return $this->filepath;
    }

    public function timeBudgetInSeconds(): int
    {
        return $this->timeBudgetInSeconds;
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

        // Resolve dumper
        $dumpers = $container->get(DatabaseDumpersInterface::class);
        $dumper  = $dumpers->get($this->database);

        // Time window (persisted start time)
        $state = $process->stateFor(action: $this);

        $start = $state['started_at'] ?? null;
        if ($start === null) {
            $start = microtime(true);
            $state['started_at'] = $start;
            $process->updateStateFor(action: $this, state: $state);
        }

        $timeBudget = $this->timeBudgetInSeconds;

        // Compute remaining time BEFORE dump
        $elapsed   = microtime(true) - $start;
        $remaining = $timeBudget - $elapsed;

        if ($remaining <= 0.0) {
            $artifact->partialPersist();
            return;
        }

        // Produce dump into a temporary file
        $tmpFile = tempnam(sys_get_temp_dir(), 'dbdump_');
        if ($tmpFile === false) {
            throw new \RuntimeException("Unable to create temporary dump file.");
        }

        $tmpStream = fopen($tmpFile, 'wb');
        if (!is_resource($tmpStream)) {
            @unlink($tmpFile);
            throw new \RuntimeException("Unable to open temporary dump file for writing.");
        }

        try {
            // IMPORTANT: pass remaining time into dumper
            $dumper->dump(
                tables: $this->tables,
                destination: $tmpStream,
                timeoutSeconds: $remaining
            );
        } catch (DumpTimedOutException $e) {
            // Timeout → partial progress → resumable
            fclose($tmpStream);
            @unlink($tmpFile);

            $artifact->partialPersist();
            return;
        } catch (\Throwable $e) {
            fclose($tmpStream);
            @unlink($tmpFile);
            throw $e; // keep your temporary RuntimeException behavior
        }

        fclose($tmpStream);

        // Recompute remaining time AFTER dump
        $elapsed   = microtime(true) - $start;
        $remaining = $timeBudget - $elapsed;

        if ($remaining <= 0.0) {
            $artifact->partialPersist();
            return;
        }

        // Feasibility check: can we write this dump file now?
        $fileSize = @filesize($tmpFile);
        if ($fileSize === false) {
            @unlink($tmpFile);
            throw new \RuntimeException('Failed to determine dump file size.');
        }

        $result = $artifact->canWriteFile(
            filePath: $tmpFile,
            fileSize: $fileSize,
            remainingSeconds: $remaining,
            timeBudgetInSeconds: $timeBudget
        );

        if ($result->shouldThrow()) {
            @unlink($tmpFile);
            throw new \RuntimeException($result->reason());
        }

        if ($result->shouldSkip()) {
            $state['skipped'][] = [
                'database'  => $this->database,
                'size'      => $fileSize,
                'reason'    => $result->reason(),
                'timestamp' => microtime(true),
            ];
            $process->updateStateFor(action: $this, state: $state);

            @unlink($tmpFile);
            $artifact->partialPersist();
            return;
        }

        if ($result->decision() === FileAcceptDecision::Defer) {
            @unlink($tmpFile);
            $artifact->partialPersist();
            return;
        }

        // ACCEPT: write dump file into artifact
        $extension = $dumper->fileExtension();
        $targetPath = $this->targetFolder . $this->filepath . '.' . $extension;

        $streamFactory = $container->get(StreamFactoryInterface::class);
        $stream = $streamFactory->createStreamFromFile(filename: $tmpFile, mode: 'r');

        try {
            $artifact->writeFileFromStream($targetPath, $stream, $fileSize);
        } finally {
            $stream->close();
            @unlink($tmpFile);
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

        // Resolve dumper
        $dumpers = $container->get(DatabaseDumpersInterface::class);
        $dumper  = $dumpers->get($this->database);

        // Time window (persisted start time)
        $state = $process->stateFor(action: $this);

        $start = $state['started_at'] ?? null;
        if ($start === null) {
            $start = microtime(true);
            $state['started_at'] = $start;
            $process->updateStateFor(action: $this, state: $state);
        }

        $timeBudget = $this->timeBudgetInSeconds;

        $elapsed   = microtime(true) - $start;
        $remaining = $timeBudget - $elapsed;

        if ($remaining <= 0.0) {
            return;
        }

        // Locate file in artifact
        $extension = $dumper->fileExtension();
        $filePath = $this->targetFolder . $this->filepath . '.' . $extension;
        
        $file = $artifact->file($filePath);
        $fileSize = (int) $file->size();

        // Feasibility check: can we read this file now?
        $result = $artifact->canReadFile(
            filePath: $filePath,
            fileSize: $fileSize,
            remainingSeconds: $remaining,
            timeBudgetInSeconds: $timeBudget,
        );

        if ($result->shouldThrow()) {
            throw new \RuntimeException($result->reason());
        }

        if ($result->shouldSkip()) {
            $state['skipped'][] = [
                'database' => $this->database,
                'size' => $fileSize,
                'reason' => $result->reason(),
                'timestamp' => microtime(true),
            ];
            $process->updateStateFor(action: $this, state: $state);
            return;
        }

        if ($result->decision() === FileAcceptDecision::Defer) {
            return;
        }

        // Read SQL stream and restore
        $stream = $file->stream();
        $source = $stream->detach();

        if (! is_resource($source)) {
            $stream->close();
            throw new \RuntimeException('Backup file stream must be a resource for restore.');
        }

        try {
            $dumper->restore(
                source: $source,
                timeoutSeconds: $remaining,
            );
        } catch (RestoreTimedOutException $e) {
            fclose($source);
            $stream->close();
            return;
        } catch (\Throwable $e) {
            fclose($source);
            $stream->close();
            throw $e;
        }

        fclose($source);
        $stream->close();

        // Recompute remaining time AFTER restore
        $elapsed   = microtime(true) - $start;
        $remaining = $timeBudget - $elapsed;

        if ($remaining <= 0.0) {
            return;
        }

        // Done
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
            'id' => $this->id,
            'database' => $this->database,
            'tables' => $this->tables,
            'targetFolder' => $this->targetFolder,
            'filepath' => $this->filepath,
            'timeBudgetInSeconds' => $this->timeBudgetInSeconds,
        ];
    }
}