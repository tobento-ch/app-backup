<?php

/**
 * TOBENTO
 *
 * @copyright   Tobias Strub, TOBENTO
 * @license     MIT License, see LICENSE file distributed with this source code.
 * @author      Tobias Strub
 * @link        https://www.tobento.ch
 */

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tobento\App\Backup\Action;
use Tobento\App\Backup\Artifact;
use Tobento\App\Backup\Feature;
use Tobento\App\Backup\Hook;
use Tobento\App\Backup\Manifest;
use Tobento\App\Backup\Process;
use Tobento\App\Backup\Registry;
use Tobento\App\Backup\Repo;
use Tobento\App\Backup\BackupRuntimeConfig;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\Service\Database\DatabasesInterface;
use Tobento\Service\FileStorage\StoragesInterface;
use function Tobento\App\{directory};

return [

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Enable or disable the backup features you want to use.
    | Features provide optional functionality such as UI integration,
    | process management, artifact handling, and restore workflows.
    |
    | See: https://github.com/tobento-ch/app-backup#features
    |
    */
    
    'features' => [
        // ...
    ],

    
    'features' => [
        // CRUD for defining backups jobs:
        new Feature\Backups(
            // A menu name to show the translations link, or null for no menu entry.
            menu: 'main',
            menuLabel: 'Backups',
            // A menu parent name (e.g. 'system') or null if none.
            menuParent: null,

            // You may disable ACL while testing.
            // Otherwise, only users with the required permissions can access the page.
            withAcl: true,
        ),
        
        // CRUD for restore backups:
        new Feature\Restore(
            // A menu name to show the translations link, or null for no menu entry.
            menu: 'main',
            menuLabel: 'Restore Backups',
            // A menu parent name (e.g. 'system') or null if none.
            menuParent: null,

            // You may disable ACL while testing.
            // Otherwise, only users with the required permissions can access the page.
            withAcl: true,
        ),
        
        // CRUD for backup/restore processes:
        new Feature\Processes(
            // A menu name to show the translations link, or null for no menu entry.
            menu: 'main',
            menuLabel: 'Backup/Restore Processes',
            // A menu parent name (e.g. 'system') or null if none.
            menuParent: null,

            // You may disable ACL while testing.
            // Otherwise, only users with the required permissions can access the page.
            withAcl: true,
        ),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Artifact Registries
    |--------------------------------------------------------------------------
    |
    | Define all artifact registries used for backup and restore. Each registry
    | specifies where artifacts are stored and how they are handled. Writable
    | registries create artifacts during backup. Readable registries expose a
    | repository for discovering restore candidates and opening artifacts for
    | restore. Registries may support writing, reading, or both.
    |
    | See: https://github.com/tobento-ch/app-backup#available-artifact-registries
    */
    
    'artifacts' => [
        new Registry\Artifact\ZipArtifactRegistry(
            // A unique registry identifier used internally.
            id: 'zip.local',

            // Display name shown in the UI.
            name: 'ZIP (Local Private Storage)',

            // Local storage path where ZIP artifacts are written and read.
            location: directory('app').'storage/uploads-private/backups/',

            // Estimated write throughput (bytes/sec), used for time‑window feasibility checks.
            throughputInBytes: 50_000_000,
        ),
        
        /*
        // Example FTP
        new Registry\Artifact\FileStorageArtifactRegistry(
            // A unique registry identifier used internally.
            id: 'ftp.storage',

            // Display name shown in the UI.
            name: 'FTP Storage',

            // The name of the configured storage service (e.g. ftp).
            storageName: 'ftp',

            // Optional folder inside the storage service where artifacts are stored.
            folder: 'backups/domain', // or null

            // Estimated throughput (bytes/sec), used for both write and read feasibility checks.
            throughputInBytes: 50_000_000,
        ),*/
    ],

    /*
    |--------------------------------------------------------------------------
    | Action Registries
    |--------------------------------------------------------------------------
    |
    | Define the registries that provide the available actions for backup and
    | restore. Each action represents a specific unit of work (e.g. backing up
    | app directories, exporting database tables) and also knows how to restore
    | the data it produced. Only the actions defined here are included when
    | creating a backup and when generating the restore options for a backup.
    |
    | See: https://github.com/tobento-ch/app-backup#available-action-registries
    */

    'actions' => [
        
        new Registry\Action\AppsDirectoryAction(
            name: 'Apps Directory',
            withSubdirs: ['storage'],
            //only: ['frontend'],
            //except: ['api']
        ),
        
        new Registry\Action\AppDirectoryAction(
            name: 'Vendor Directory',
            appId: 'root',
            appDir: 'vendor',
        ),
        new Registry\Action\AppDirectoryAction(
            name: 'Public Directory',
            appId: 'root',
            appDir: 'public',
        ),
        new Registry\Action\AppDirectoryAction(
            name: 'Root Files',
            appId: 'root',
            appDir: 'root',
            onlyDirs: [],
            exceptDirs: ['*'],
        ),
        
        new Registry\Action\DatabasesAction(
            name: 'Databases',
        ),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Hooks
    |--------------------------------------------------------------------------
    |
    | Configure the hooks available for backup processing.
    |
    | Hooks allow you to extend or customize backup behavior by reacting to
    | lifecycle events such as process start, step execution, completion,
    | or failure. They are ideal for adding notifications, cleanup logic,
    | metadata updates, or other side‑effects without modifying core backup
    | functionality.
    |
    | See: https://github.com/tobento-ch/app-backup#available-hooks
    |
    */
    
    'hooks' => [
        new Hook\NotifyCurrentUser(
            name: 'Keep me updated about this job',
            channels: ['browser'],
            defaultSelected: true,
        ),
        
        new Hook\NotifyUsers(
            id: 'notify.administrators',
            name: 'Notify Administrators via Account and Browser',
            roles: ['administrator'],
            channels: ['storage', 'browser'],
            defaultSelected: false,
        ),
        
        new Hook\SendGeneratedBackupZipToEmail(
            id: 'send.backup.zip.to.somebody',
            name: 'Send the generated backup ZIP to info@example.ch (requires ZIP Storage Location)',
            email: 'return@blumen-strub.ch',
            subject: 'Backup: {name}',
            message: 'lorem ipsum',
        ),
        
        new Hook\DeleteFailedBackupData(
            name: 'Auto‑Delete Failed Backup Data',
            group: 'Maintenance',
            defaultSelected: true,
        ),

        // Add this hook last:
        // It deletes the finished process entity,
        // so hooks that read the process entity from the repository must run earlier.
        new Hook\DeleteFinishedProcess(
            name: 'Auto‑Delete Finished Process (removes it from the process list)',
            group: 'Maintenance',
        ),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Interfaces
    |--------------------------------------------------------------------------
    |
    | Do not change the interface's names as it may be used in other app bundles!
    |
    */
    
    'interfaces' => [
        BackupRuntimeConfigInterface::class => function () {
            return new BackupRuntimeConfig(
                // Run backups on a specific queue.
                // If null, the default queue is used.
                queueName: 'file',
                
                // Unified execution time budget in seconds for backup and restore jobs.
                // This value is used both as the queue job duration and as the
                // time budget for action processing (chunking and resumability).
                // It must not exceed the queue worker's timeout.
                jobTimeBudgetInSeconds: 20,
                
                // Name of the encrypter to use for encryption.
                encrypter: 'default',

                // Estimated encryption throughput in bytes per second.
                encryptThroughputInBytes: 50_000_000,
            );
        },
        
        Action\ActionsFactoryInterface::class => Action\ActionsFactory::class,
        
        Process\BackupProcessFactoryInterface::class => Process\BackupProcessFactory::class,
        
        Process\RestoreProcessFactoryInterface::class => Process\RestoreProcessFactory::class,
        
        Artifact\ReadableArtifactFactoryInterface::class => Artifact\ReadableArtifactFactory::class,
        
        Artifact\WritableArtifactFactoryInterface::class => Artifact\WritableArtifactFactory::class,
        
        Manifest\ManifestFactoryInterface::class => Manifest\ManifestFactory::class,
        
        Repo\BackupRepositoryInterface::class =>
        static function(
            DatabasesInterface $databases,
            Repo\BackupEntityFactory $backupEntityFactory,
            null|ServerRequestInterface $request,
        ): Repo\BackupRepositoryInterface {
            return new Repo\BackupStorageRepository(
                storage: $databases->default('storage')->storage()->new(),
                table: 'backups',
                backupEntityFactory: $backupEntityFactory,
                request: $request,
            );
        },
        
        Repo\ProcessRepositoryInterface::class =>
        static function(
            DatabasesInterface $databases,
            Repo\ProcessEntityFactory $processEntityFactory,
            null|ServerRequestInterface $request,
        ): Repo\ProcessRepositoryInterface {
            return new Repo\ProcessStorageRepository(
                storage: $databases->default('storage')->storage()->new(),
                table: 'backup_processes',
                processEntityFactory: $processEntityFactory,
                request: $request,
            );
        },
        
        Repo\RestoreRepositoryInterface::class => Repo\CompositeRestoreRepository::class,
    ],
    
];