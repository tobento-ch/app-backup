# App Backup

The app backup system provides the following features and uses a modular, incremental process architecture with actions, artifacts, and hooks:

- [**Backups Feature**](#backups-feature) - Provides a dedicated UI where all available backup actions are listed, can be configured with options and hooks, and then executed incrementally.
- [**Restore Feature**](#restore-feature) - Provides a dedicated UI where all previously generated backup artifacts are listed, can be inspected, selected, and restored.  
- [**Processes Feature**](#processes-feature) - Displays all running and completed backup/restore processes, including action state, runtime, memory usage, and artifacts.
- [**Artifact Registries**](#available-artifact-registries) - Manage how backup artifacts (e.g., ZIP files) are created, stored, finalized, and accessed.
- [**Action Registries**](#available-action-registries) - Register and discover actions that may implement both backup and restore methods, such as the [Apps Directory Registry](#apps-directory-action-registry).
- [**Hooks**](#available-hooks) - Extend backup/restore behavior with runtime tracking, notifications, and more.

**Zero-Config Bootstrapping**

The Backup system works out-of-the-box with sensible defaults.

Simply register the [Backup Boot](#backup-boot) in your application, and the full backup management UI becomes available immediately - no additional configuration required.

All built-in actions, artifacts, and hooks are automatically discovered, listed in the UI, and ready to use.  
You can run backups, restore artifacts, inspect process state, and monitor runtime without writing any custom code.

You only need to customize configuration if you want to override defaults such as:

- available backup or restore actions  
- artifact storage locations  
- process retention behavior  
- permissions and access control  
- custom action registries  
- custom artifacts registries  
- custom hooks (runtime tracking, notifications)

## Table of Contents

- [Getting Started](#getting-started)
    - [Requirements](#requirements)
    - [Highlights](#highlights)
- [Documentation](#documentation)
    - [App](#app)
    - [Backup Boot](#backup-boot)
        - [Backup Config](#backup-config)
    - [Http Error Handler Boot](#http-error-handler-boot)
    - [Features](#features)
        - [Backups Feature](#backups-feature)
        - [Restore Feature](#restore-feature)
        - [Processes Feature](#processes-feature)
    - [Available Artifact Registries](#available-artifact-registries)
        - [File Storage Artifact Registry](#file-storage-artifact-registry)
        - [Zip Artifact Registry](#zip-artifact-registry)
    - [Artifact Encryption](#artifact-encryption)
    - [Available Action Registries](#available-action-registries)
        - [App Directory Action Registry](#app-directory-action-registry)
        - [Apps Directory Action Registry](#apps-directory-action-registry)
        - [Databases Action Registry](#databases-action-registry)
    - [Available Hooks](#available-hooks)
        - [Delete Failed Backup Data Hook](#delete-failed-backup-data-hook)
        - [Delete Finished Process Hook](#delete-finished-process-hook)
        - [Notify Hook](#notify-hook)
        - [Notify Current User Hook](#notify-current-user-hook)
        - [Notify Users Hook](#notify-users-hook)
        - [Process Lifecycle Hook](#process-lifecycle-hook)
        - [Send Generated Backup Zip To Email Hook](#send-generated-backup-zip-to-email-hook)
    - [Learn More](#learn-more)
        - [Scheduling Backups](#scheduling-backups)
        - [Scheduling Pruning Backups](#scheduling-pruning-backups)
        - [Advanced Runtime Configuration](#advanced-runtime-configuration)
        - [Adding Registries Via App](#adding-registries-via-app)
        - [Adding Hooks Via App](#adding-hooks-via-app)
        - [Notifications for Background Jobs](#notifications-for-background-jobs)
- [Credits](#credits)
___

# Getting Started

Add the latest version of the app backup project running this command.

```
composer require tobento/app-backup
```

## Requirements

- PHP 8.4 or greater

## Highlights

- runs fully in the background using queues, so backups and restores never block the request
- advances through action-defined state, allowing safe and incremental execution of large backup tasks
- automatically resumes long-running processes without losing progress
- integrates naturally with existing app modules through action registries
- supports multiple artifact formats via artifact registries (ZIP, local filesystem, custom repositories)
- easily extendable with custom actions, artifact registries, hooks, and configuration entries
- keeps the system stateless by reconstructing actions and repositories from registry identifiers
- provides a clean UI for triggering backups, restoring artifacts, and inspecting process state
- supports hook-based notifications and lifecycle tracking
- allows triggering backup and restore processes directly from the Backups Feature UI and Restore Feature UI

# Documentation

## App

Check out the [**App Skeleton**](https://github.com/tobento-ch/app-skeleton) if you are using the skeleton.

You may also check out the [**App**](https://github.com/tobento-ch/app) to learn more about the app in general.

## Backup Boot

The backup boot does the following:

* installs and loads backup config
* implements the required interfaces
* boots [features](#features) and registries configured in [backup config](#backup-config)

```php
use Tobento\App\AppFactory;
use Tobento\App\Backup\Action;
use Tobento\App\Backup\Artifact;
use Tobento\App\Backup\Feature;
use Tobento\App\Backup\Hook;
use Tobento\App\Backup\Manifest;
use Tobento\App\Backup\Process;
use Tobento\App\Backup\Registry;
use Tobento\App\Backup\Repo;

// Create the app
$app = new AppFactory()->createApp();

// Add directories:
$app->dirs()
    ->dir(realpath(__DIR__.'/../'), 'root')
    ->dir(realpath(__DIR__.'/../app/'), 'app')
    ->dir($app->dir('app').'config', 'config', group: 'config')
    ->dir($app->dir('root').'public', 'public')
    ->dir($app->dir('root').'vendor', 'vendor');

// Adding boots
$app->boot(\Tobento\App\Backup\Boot\Backup::class);
$app->booting();

// Implemented interfaces:
$actionsFactory = $app->get(Action\ActionsFactoryInterface::class);
$backupProcessFactory = $app->get(Process\BackupProcessFactoryInterface::class);
$restoreProcessFactory = $app->get(Process\RestoreProcessFactoryInterface::class);
$readableArtifactFactory = $app->get(Artifact\ReadableArtifactFactoryInterface::class);
$writableArtifactFactory = $app->get(Artifact\WritableArtifactFactoryInterface::class);
$manifestFactory = $app->get(Manifest\ManifestFactoryInterface::class);
$backupRepository = $app->get(Repo\BackupRepositoryInterface::class);
$processRepository = $app->get(Repo\ProcessRepositoryInterface::class);
$restoreRepository = $app->get(Repo\RestoreRepositoryInterface::class);
$artifactRegistries = $app->get(Registry\Artifact\ArtifactRegistriesInterface::class);
$actionRegistries = $app->get(Registry\Action\ActionRegistriesInterface::class);
$hooks = $app->get(Hook\HooksInterface::class);

// Run the app
$app->run();
```

You may install the [App Backend](https://github.com/tobento-ch/app-backend) and [boot the backup](https://github.com/tobento-ch/app-backend#adding-boots) in the backend app.

### Backup Config

The backup configuration is located in `app/config/backup.php`.  
Here you define which [features](#features) are enabled, and configure [artifact registries](#available-artifact-registries), [action registries](#available-action-registries), [hooks](#available-hooks), and all required interfaces.

The Backup Boot loads this configuration automatically and registers everything with the application.

## Http Error Handler Boot

The Backup package provides an optional HTTP-level error handler that converts internal backup exceptions into structured, user-friendly responses.  
It is recommended when you want consistent handling of backup-related errors during HTTP requests.

**Example**

```php
use Tobento\App\AppFactory;

// Create the app
$app = new AppFactory()->createApp();

// Add directories:
$app->dirs()
    ->dir(realpath(__DIR__.'/../'), 'root')
    ->dir(realpath(__DIR__.'/../app/'), 'app')
    ->dir($app->dir('app').'config', 'config', group: 'config')
    ->dir($app->dir('root').'public', 'public')
    ->dir($app->dir('root').'vendor', 'vendor');

// Adding boots
$app->boot(\Tobento\App\Backup\Boot\HttpErrorHandler::class);
$app->boot(\Tobento\App\Backup\Boot\Backup::class);
$app->booting();

// Run the app
$app->run();
```

The `HttpErrorHandler` boot registers handlers for backup-specific exceptions and ensures they are returned as structured HTTP responses instead of raw errors. Without this boot, backup exceptions fall through to the default error handling: in dev mode they appear as raw exceptions, while in other environments they typically result in generic 500 errors or follow any globally configured error-handling behavior.

## Features

### Backups Feature

This feature provides a backup page where users can create and run backup operations using the configured [artifact registries](#available-artifact-registries), [action registries](#available-action-registries) and [hooks](#available-hooks).  
In addition to running backups manually from this page, backups can also be executed through scheduled tasks, see [Scheduling Backups](#scheduling-backups).

**Config**

In the [config file](#backup-config) you can configure this feature:

```php
'features' => [
    new Feature\Backups(
        // A menu name to show the translations link, or null for no menu entry.
        menu: 'main',
        menuLabel: 'Backups',
        // A menu parent name (e.g. 'system') or null if none.
        menuParent: null,
        
        // You may disable ACL while testing.
        // Otherwise, only users with the required permissions can access the page.
        withAcl: false,
    ),
],
```

**ACL Permissions**

* ```backups``` User can access and manage backups.

When using the [App Backend](https://github.com/tobento-ch/app-backend), you can assign the permission in the roles or users page.

#### Workflow

A backup operation follows a simple workflow:

1. **Create a new backup**  
   The user selects one or more [artifact registries](#available-artifact-registries) (where to store the backup), chooses [actions](#available-action-registries) (what to store), and may attach [hooks](#available-hooks).

2. **Run the backup**  
   The backup is executed in the background using the queue.  
   Actions run in sequence, artifacts are written, and hooks are triggered.

3. **Viewing backups**  
   Backups can be viewed in the [Restore Feature](#restore-feature).  
   Depending on the artifact type, they may be downloadable (e.g. ZIP artifacts).

To monitor queued backup jobs, view their status, or inspect failures and retries, you may install  
[tobento-ch/app-job](https://github.com/tobento-ch/app-job).

### Restore Feature

The Restore Feature provides a page where users can browse, download, upload, and restore backup artifacts created by the [Backups Feature](#backups-feature).  
It supports all configured [artifact registries](#available-artifact-registries), [action registries](#available-action-registries) and [hooks](#available-hooks) that participate in restore operations.

**Config**

In the [config file](#backup-config) you can configure this feature:

```php
'features' => [
    new Feature\Restore(
        // A menu name to show the translations link, or null for no menu entry.
        menu: 'main',
        menuLabel: 'Restore Backups',
        // A menu parent name (e.g. 'system') or null if none.
        menuParent: null,

        // You may disable ACL while testing.
        // Otherwise, only users with the required permissions can access the page.
        withAcl: false,
    ),
],
```

**ACL Permissions**

* ```backups.restore``` User can access and manage restore backups.

When using the [App Backend](https://github.com/tobento-ch/app-backend), you can assign the permission in the roles or users page.

#### What the Restore Feature Provides

The Restore Feature exposes a CRUD interface for backup artifacts stored by the system.  
Artifacts are collected by the composite restore repository, which aggregates all configured artifact registries implementing the `ReadableArtifactRegistryInterface`.  
Each readable artifact registry contributes its own underlying repository, maps raw items to restore entities, and provides readable artifacts for restore operations.

Depending on the artifact type, users can:

- View available backups  
- Download artifacts (e.g., ZIP files)  
- Upload ZIP artifacts (if a `ZipArtifactRegistry` is available)  
- Run restore actions  
- Delete artifacts  
- Bulk delete artifacts  

When restoring a backup, the user can **select which actions to restore individually**.  
Each action listed in the artifact's manifest is shown with its own restore options, allowing users to restore only specific parts of a backup (e.g., only files, only database tables, only configuration) instead of performing a full restore.

#### Restore Actions

Restore actions are not resolved from action registries.  
Instead, each backup artifact contains a manifest describing the actions that were used during backup.  
During restore, these actions are reconstructed and executed directly:

1. The manifest is read from the backup artifact.
2. Each action listed in the manifest is re-instantiated using the `ActionsFactoryInterface`.
3. User-provided restore options are applied via `configureForRestore()`.
4. The restore process executes each action's `restore()` method in sequence.
5. Action state and progress are persisted in the process repository.

This ensures that restore operations always use the exact action configuration that was present during backup, independent of any currently registered action implementations.

#### Artifact Deletion Behavior

Deletion behavior depends on the type of artifact:

- **File-storage artifacts** (e.g., folders containing many files)  
  These are deleted in the background using the queue system.  
  The deletion job processes files and folders in small steps, respects the
  configured time budget, requeues itself when more work remains, and sends a
  browser notification to the user when deletion is completed.

- **Single-file artifacts** (e.g., ZIP artifacts)  
  These are deleted immediately without queueing, because they consist of a
  single file and require no incremental processing.

This ensures that large artifacts can be deleted reliably without blocking the
UI, while simple artifacts are removed instantly.

### Processes Feature

This feature provides a page where users can browse, inspect, and manage all running and completed backup and restore processes.  
It offers full visibility into action progress, runtime, memory usage, and produced artifacts.

**Config**

In the [config file](#backup-config) you can configure this feature:

```php
'features' => [
    new Feature\Processes(
        // A menu name to show the translations link, or null for no menu entry.
        menu: 'main',
        menuLabel: 'Backup/Restore Processes',
        // A menu parent name (e.g. 'system') or null if none.
        menuParent: null,

        // You may disable ACL while testing.
        // Otherwise, only users with the required permissions can access the page.
        withAcl: false,
    ),
],
```

**ACL Permissions**

* ```backups.processes``` User can access and manage processes.

When using the [App Backend](https://github.com/tobento-ch/app-backend), you can assign the permission in the roles or users page.

#### What the Processes Feature Provides

The Processes Feature exposes a CRUD interface for all backup and restore processes stored in the process repository.  
Each process contains:

- the list of actions executed  
- per-action state and progress  
- timestamps and runtime metrics  
- memory usage  
- produced artifacts  
- status (processing, completed, failed)  
- type and type identifier (backup/restore linkage)  
- user information (who triggered it)  
- queue/runtime metadata (if stored)

Processes are updated automatically by background queue workers.  
The UI allows users to:

- inspect running processes  
- view completed processes  
- inspect action-level state  
- view associated artifacts  
- delete old processes  

This feature is especially useful when monitoring longrunning backups or restores, or when debugging action-level issues.

To monitor queued backup jobs, view their status, or inspect failures and retries, you may install  
[tobento-ch/app-job](https://github.com/tobento-ch/app-job).

## Available Artifact Registries

Artifact registries define where backup artifacts are stored and how they are accessed during backup and restore operations.  
Writable registries create artifacts during backup, while readable registries expose repositories that allow the Restore Feature to discover available artifacts and open them for restore.  
A registry may support writing, reading, or both, depending on its implementation.

You can configure all artifact registries in the `artifacts` section of the [backup config](#backup-config).

### File Storage Artifact Registry

The `FileStorageArtifactRegistry` provides read and write access to backup artifacts stored in a configured file-storage service (such as a private uploads storage).  
It implements both `WritableArtifactRegistryInterface` and `ReadableArtifactRegistryInterface`, allowing it to create artifacts during backup and expose them as readable artifacts during restore.

Unlike the [ZIP registry](#zip-artifact-registry), this registry does **not** bundle artifacts into a single ZIP file.  
Each artifact is written as an individual file under the configured storage folder.  
This makes it especially suitable for very large backup sets - such as directories containing hundreds of thousands or millions of files - where creating a ZIP archive would be slow, memory-intensive, or exceed ZIP format limitations.

Because artifacts remain as individual files, this registry is ideal for external or remote storage systems (FTP, S3-like storage, or any configured file-storage service) and helps avoid local filesystem size limits or quota restrictions.

It also supports time-window feasibility checks through its configurable throughput, which is used to estimate both write and read performance when determining whether an artifact operation can fit into the available time window.

**Config**

In the [config file](#backup-config) you can configure this registry:

```php
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
),
```

#### Deletion Behavior

The `FileStorageArtifactRegistry` implements `QueueDeletionRegistryInterface`, which means artifact deletion is performed through a background job rather than immediately.

Instead of deleting a single file, the registry produces a `FileStorageDeleteProcess` that iterates through all files and folders under the artifact's root directory.  
The delete process itself only determines the next item to delete and performs the actual deletion.

The orchestration is handled by the `DeleteArtifactJobHandler`, which:

- executes the `FileStorageDeleteProcess` step-by-step  
- respects the configured time budget  
- requeues the job when more work remains  
- sends a browser notification when deletion is completed  

This background deletion model is essential for very large artifacts (such as multi-gigabyte file sets or directories containing hundreds of thousands of files), ensuring reliable deletion without blocking the UI or causing request timeouts.

### Zip Artifact Registry

The `ZipArtifactRegistry` provides read and write access to ZIP-based backup artifacts stored on the local filesystem.  
It implements both `WritableArtifactRegistryInterface` and `ReadableArtifactRegistryInterface`, allowing it to create ZIP files during backup and expose them as readable artifacts during restore.

It also supports time-window feasibility checks through its configurable throughput, which is used to estimate both write and read performance when determining whether an artifact operation can fit into the available time window.

**Config**

In the [config file](#backup-config) you can configure this registry:

```php
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
```

#### Deletion Behavior

The `ZipArtifactRegistry` does **not** implement `QueueDeletionRegistryInterface`.  
ZIP artifacts consist of a single file, so deletion is performed immediately by the registry's underlying repository without using a background delete process or the queue system.

Unlike file-storage artifacts, ZIP artifacts do not produce a delete process and are not handled by the `DeleteArtifactJobHandler`.  
Deleting a ZIP artifact simply removes the corresponding ZIP file from the configured storage location.

## Artifact Encryption

Backups may contain sensitive data such as database dumps or configuration files.  
The Backup package supports optional **per-file encryption** of any backup artifact through the `EncryptionWritableArtifact` decorator.

Unlike a feature tied to a specific artifact type, encryption is implemented as an **adapter** that wraps any `WritableArtifactInterface` - ZIP, FileStorage, or any custom artifact registry. This means enabling encryption does not depend on which storage backend is used.

```php
use Tobento\App\Backup\Artifact\EncryptionWritableArtifact;

new EncryptionWritableArtifact(
    inner: $artifact, // any WritableArtifactInterface
    streamFactory: $streamFactory,
    encrypter: $encrypter, // Tobento\Service\Encryption\EncrypterInterface
    // Estimated encryption throughput (bytes/sec), used for time-window feasibility checks.
    throughputInBytes: 50_000_000,
),
```

### How it works

Each file written to the artifact is encrypted individually before being passed to the wrapped (inner) artifact:

- `manifest.json` is **never encrypted**, so restore can always read the backup's structure regardless of encryption settings.
- All other files are read fully into memory, encrypted via the configured `EncrypterInterface`, and written to the inner artifact as an encrypted stream.
- `partialPersist()` and `finalize()` are delegated directly to the inner artifact.

### Time-budget feasibility

Like every artifact operation, encryption participates in the time-budget feasibility check (`canWriteFile()`) before a file is written:

- The decorator estimates encryption time from `fileSize / throughputInBytes`.
- **Permanent impossibility (`NeverFits`)** - if the estimated encryption time exceeds the *full* time-budget window, the file can never be encrypted within a single job run. The backup process **fails** with an exception rather than silently skipping the file, to avoid producing a backup with inconsistent or missing encrypted content.
- **Temporary impossibility (`Defer`)** - if encryption would fit within the full window but not in the time *remaining* in the current job run, the file is deferred to a later incremental step.
- If encryption fits, the check is delegated to the inner artifact's own `canWriteFile()`, so the underlying storage's constraints (e.g. FTP throughput) still apply on top of encryption.

> **Important:** enabling encryption introduces an additional throughput constraint on top of the underlying artifact's own limits. A backup will fail outright if any single file cannot be encrypted within the configured time-budget window - this is intentional, to guarantee that a completed backup is either fully and correctly encrypted or not produced at all.

### UI Configuration

When encryption is available, the Backups Feature UI exposes a simple toggle:

```php
yield new Field\Radios(name: 'meta.encrypt', label: trans('Encrypt Files'))
    ->group(trans('Backup'))
    ->options(['0' => trans('No'), '1' => trans('Yes')])
    ->selected(value: '0', action: 'create|edit')
    ->displayInline()
    ->infoText(new Html\Message(
        text: trans('Backup will fail when a file cannot be encrypted within the allowed processing window.'),
        warning: true,
        attributes: ['class' => 'mt-s mb-m'],
    ));
```

The warning text is not just UI copy - it reflects the actual `NeverFits` failure behavior described above, so users configuring large backups understand that raising the time-budget window (or splitting the backup, e.g. via Partial database mode) may be necessary when encryption is enabled.

> **Encryption Availability**
>
> The Backup package loads the [app-encryption](https://github.com/tobento-ch/app-encryption) bundle automatically (see the Backup Boot class).  
> Encryption support is therefore always available, regardless of which artifact type or storage backend is used.
>
> Encryption is **opt-in per backup**: users enable it via the `Encrypt Files` toggle in the Backups UI.  
> When enabled, all artifact writes are wrapped in `EncryptionWritableArtifact`.

### Reading Encrypted Artifacts

Restoring from an encrypted backup requires the inverse decorator: `EncryptionReadableArtifact`. It wraps any `ReadableArtifactInterface` and transparently decrypts files as they are read during restore.

```php
use Tobento\App\Backup\Artifact\EncryptionReadableArtifact;

new EncryptionReadableArtifact(
    inner: $artifact, // any ReadableArtifactInterface
    streamFactory: $streamFactory,
    encrypter: $encrypter, // Tobento\Service\Encryption\EncrypterInterface
),
```

#### How it works

- The `manifest.json` file is always returned unmodified. It is never encrypted and therefore never passed through the decryption layer.
- The manifest does not store a separate encrypted filename field. Encryption metadata is limited to:  
  `meta.encrypt` - whether the artifact was encrypted (`"1"` or `"0"`).  
  `meta.encrypter` - the name of the encrypter used.
- `files()` returns a `DecryptedFiles` collection, and `file()` returns a single `DecryptedFile` - both wrap the inner artifact's file objects and decrypt their content **lazily**, only when the file's content is actually read. Listing or inspecting files does not trigger decryption.
- `canReadFile()` is delegated directly to the inner artifact, unchanged. Unlike the write side, decryption does **not** add its own throughput estimate to the time-budget feasibility check - it is treated as timing-neutral, so no `throughputInBytes` parameter is needed here.

> **Note the asymmetry with `EncryptionWritableArtifact`:** on write, encryption estimates its own duration and can produce `NeverFits`/`Defer` results in addition to the inner artifact's check. On read, decryption is assumed cheap enough not to require its own feasibility estimate - only the inner artifact's read cost (e.g. FTP throughput) is considered.

### Wiring encryption for backup and restore

Since encryption/decryption is applied by wrapping an artifact rather than by an artifact registry flag, both directions need to be wired consistently wherever an artifact is produced or consumed with encryption enabled - e.g. when the `meta.encrypt` option is set, the backup job wraps the writable artifact in `EncryptionWritableArtifact`, and the corresponding restore path wraps the readable artifact in `EncryptionReadableArtifact` before actions read from it.

### Encrypter Resolution on Restore

Encryption in [app-encryption](https://github.com/tobento-ch/app-encryption) is configured by **name** (see the [Encryption Config](https://github.com/tobento-ch/app-encryption/#encryption-config)) - an application may define multiple named encrypters, each with its own key. `EncrypterInterface` instances are not self-describing, so the backup process must record **which named encrypter was used** at backup time, not just that encryption was enabled.

- The **manifest** stores the encrypter name (e.g. `meta.encrypt_name` or similar) alongside the `meta.encrypt` flag, so restore knows which encrypter to resolve rather than assuming a single default.
- On restore, this name is looked up against the target app's `app-encryption` config to resolve the matching `EncrypterInterface` before wrapping the readable artifact in `EncryptionReadableArtifact`.
- If no encrypter with that name is configured on the target system, restore must fail early and explicitly - with a clear error naming the missing encrypter - rather than falling back to a default encrypter, which would either throw during decryption or silently produce garbage.

> **This matters most for disaster-recovery scenarios**: restoring onto a fresh `app-skeleton` install. A fresh skeleton has no encrypters configured at all by default, so an encrypted backup is only restorable once the target's `config/encryption.php` defines an encrypter with the same name **and the same key/secret** used at backup time. This dependency should be called out explicitly wherever restore-to-fresh-install is documented (e.g. alongside the Restore Feature's upload-ZIP flow) - otherwise a user restoring onto a new server may not realize the encryption key itself is a prerequisite they need to provision *before* uploading the backup artifact, not something the restore process can recover on its own.

## Available Action Registries

Action registries define which backup and restore actions are available to the system.  
Each action represents a specific unit of work - such as backing up application directories, exporting database tables, or capturing configuration files - and also knows how to restore the data it produced.

Only the actions registered here are included when creating a backup and when generating the restore options for a backup.  
By selecting and combining different action registries, you control exactly which parts of the application are backed up and how they can be restored.

You can configure all action registries in the `actions` section of the [backup config](#backup-config).

### App Directory Action Registry

The `AppDirectoryAction` registry provides a backup/restore action for a specific application directory.  
It implements `ActionRegistryInterface` and is responsible for configuring the UI fields and creating the corresponding runtime action (`Action\AppDirectory`) that performs the actual backup and restore.

When enabled for a backup, this registry creates an `Action\AppDirectory` instance, which knows how to capture files from the directory and restore them later using the same structure.

This registry is useful for backing up framework directories, public assets, vendor packages, or any application-specific folder.  
It supports fine-grained control over which subdirectories should be included or excluded through the `onlyDirs` and `exceptDirs` options.

- **onlyDirs**  
  A list of directory names that should be backed up exclusively.  
  If set, only these directories are included.

- **exceptDirs**  
  A list of directory names that should be excluded from backup.  
  The special value `'*'` excludes **all** directories, meaning only root-level files are backed up.

**Config**

In the [config file](#backup-config) you can configure this action registry:

```php
new Registry\Action\AppDirectoryAction(
    // Display name shown in the UI.
    name: 'Vendor Directory',

    // The application identifier (e.g. 'root').
    appId: 'root',

    // The directory inside the application to back up.
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

    // Only include these directories (empty means no directory is explicitly included).
    onlyDirs: [],

    // Exclude all directories; only root-level files are backed up.
    exceptDirs: ['*'],
),
```

**UI Options**

When creating a backup, the UI displays this action as a selectable option.  
Users can choose whether this directory should be included in the backup.  
During restore, each action appears individually with its own restore options, allowing users to restore only the selected directories instead of performing a full restore.

### Apps Directory Action Registry

The `AppsDirectoryAction` registry provides a backup/restore action for all application directories managed by the system.  
It implements `ActionRegistryInterface` and is responsible for configuring the UI fields and creating the corresponding runtime action (`Action\AppsDirectory`) that performs the actual backup and restore.

This registry is useful when you want to back up multiple application directories at once - such as all installed apps - while still retaining control over which subdirectories should be included or excluded.

It supports three filtering options:

- **withSubdirs**  
  A list of subdirectory names that should be included in addition to the app's root directory.  
  These subdirectories are backed up for *every* app.

- **only**  
  A list of app names that should be backed up exclusively.  
  If set, only these apps are included.

- **except**  
  A list of app names that should be excluded from backup.  
  Useful when backing up all apps except X.

**Config**

In the [config file](#backup-config) you can configure this action registry:

```php
new Registry\Action\AppsDirectoryAction(
    // Display name shown in the UI.
    name: 'Apps Directory',

    // Additional subdirectories to include for each app.
    withSubdirs: ['storage'],

    // Only include these apps (optional).
    //only: ['frontend'],

    // Exclude these apps (optional).
    //except: ['api'],
),
```

**UI Options**

When creating a backup, the UI displays this registry as a group of checkboxes - one for each app directory discovered in the system.  
Users can select any number of app directories individually (e.g., `config`, `countries`, `migrations`, `storage/logs`, `views`, ...).  
The registry applies its filters (`withSubdirs`, `only`, `except`) to determine which directories appear and which subdirectories are included.

During restore, each selected app directory becomes its own restore action.  
These actions are shown individually with their own restore options, allowing users to restore specific app directories without restoring all of them.

### Databases Action Registry

The `DatabasesAction` registry provides a backup/restore action for database tables across all applications.  
It implements `ActionRegistryInterface` and is responsible for configuring the UI fields and creating the corresponding runtime action (`Action\Databases`) that performs the actual backup and restore.

This registry automatically discovers all PDO-based database connections from every booted app, collects their tables, and exposes them in the backup UI.  
During backup, users can choose between **complete mode** (all selected tables written into a single dump file per database) or **partial mode** (each selected table written into its own dump file).  
During restore, the action reconstructs the selected tables and restores them either from a single database-level dump file (complete mode, where table selection is no longer possible) or from per-table dump files (partial mode, where users may choose which tables to restore).

**Config**

In the [config file](#backup-config) you can configure this action registry:

```php
new Registry\Action\DatabasesAction(
    name: 'Databases',
),
```

#### UI Options

When creating a backup, the UI displays this registry as a database section containing:

- A Mode dropdown (Complete or Partial)
- A list of databases, each with its own checkbox group of tables

Users can select any number of tables across any number of databases.  
If no tables are selected, the registry produces a NullAction and is skipped.

During restore, each database action appears individually with its own restore options.  
If partial mode was used, the restore UI shows the per-table restore behavior. If complete mode was used, the entire database is restored from a single dump file.

#### Modes

- **Complete restore**  
  Users may still select which tables to include, but all selected tables are written into a **single dump file per database**.  
  This mode is recommended for most cases because the restore process is consistent and atomic.

- **Partial restore**  
  Each selected table is written into its **own dump file**.  
  This allows restoring individual tables and is also useful for **very large databases**, as splitting the dump into multiple files can help avoid timeouts during backup or restore.  
  However, tables are backed up at slightly different moments, which may lead to timing inconsistencies.

#### Table Collection

The registry collects tables from all PDO databases across all apps:

- MySQL: `SHOW TABLES`   
- SQLite: ignored (handled by directory backup)

Tables are grouped by database name, and the UI shows labels such as:

- mysql (root)
- mysql (frontend, backend)

depending on which apps use the connection.

## Available Hooks

Hooks allow you to react to lifecycle events that occur during a backup or restore process.  
They do not perform backup or restore work themselves; instead, they respond to high-level process events and action-level execution steps reported by the backup engine.

The available hooks are defined in the [config file](#backup-config) and can be selected when creating or editing a backup/restore job.  
Each hook listens to one or more of the following events:

- **Process started**  
  Triggered once before any actions begin.  
  Useful for initializing metadata, logging, or preparing resources.

- **Action step executing**  
  Triggered right before an action performs a single incremental step.  
  Allows measuring step runtime, logging activity, or preparing per-step state.

- **Action step executed**  
  Triggered after an action completes a single incremental step.  
  Useful for tracking fine-grained progress or updating per-step metadata.

- **Action finished**  
  Triggered once per action after all of its steps have completed.  
  Allows hooks to finalize action-level state or write summary information.

- **Process finished**  
  Triggered when all actions have completed successfully.  
  Useful for marking the job as finished, sending notifications, or performing cleanup.

- **Process failed**  
  Triggered when the process aborts due to an exception.  
  Allows hooks to log the failure, update job status, notify users, or perform cleanup tasks.

Hooks are typically used to update job metadata, log progress, track action execution, notify users, or perform cleanup after the process completes or fails.

### Delete Failed Backup Data Hook

The **Delete Failed Backup Data Hook** automatically removes (or queues the removal of) any writable backup artifacts when a backup process fails.  
This prevents leftover partial data from accumulating in your restore repositories or file-based artifact stores, and keeps your backup environment clean and predictable.

This hook supports:

- Automatic cleanup of **failed backup artifacts**  
- Safe deletion via the restore repository's `deleteById()` method  
- Queue-based deletion for registries implementing `QueueDeletionRegistryInterface`  
- Logging of deletion failures (e.g., missing artifacts, repository errors)  
- Works with single artifacts, composite artifacts, and adapter-wrapped artifacts

When a backup process fails, the hook inspects all writable artifacts associated with the process.  
For each artifact:

- If the registry supports **immediate deletion**, the artifact is removed directly.  
- If the registry supports **queued deletion**, a `DeleteArtifactJobHandler` job is pushed to the queue.  
- If deletion fails, the hook logs a **warning** containing the artifact ID, process ID, and exception details.

**Config**

Define the hook in your [config file](#backup-config):

```php
'hooks' => [
    new Hook\DeleteFailedBackupData(
        name: 'Auto‑Delete Failed Backup Data',
        group: 'Maintenance',
        defaultSelected: false,
    ),
]
```

This hook may be placed anywhere in the hook list.  
It does not modify the process entity itself, so ordering constraints are minimal.

**Behavior**

When a backup process enters the **failed** lifecycle event, the hook performs a controlled cleanup of all writable artifacts associated with that process:

- Collects all writable artifacts from the failed backup process  
- Attempts to delete each artifact via `RestoreRepositoryInterface::deleteById()`  
- If the registry supports queued deletion, a `DeleteArtifactJobHandler` job is pushed instead  
- Logs a warning when deletion fails (e.g., artifact not found or repository error)  
- The backup process remains failed - cleanup errors never modify the process status

This ensures that failed backup data is cleaned up safely without interfering with failure reporting or diagnostics.

**Logging**

This hook uses the [`LoggerTrait`](https://github.com/tobento-ch/app-logging#logger-trait) to log warnings when deletion fails.

You may configure which logger should be used for this hook in your `app/config/logging.php` file.  
If no alias is defined, the default logger will be used.

```php
'aliases' => [
    // Route deletion warnings to the "daily" logger:
    \Tobento\App\Backup\Hook\DeleteFailedBackupData::class => 'daily',

    // Or disable logging entirely:
    \Tobento\App\Backup\Hook\DeleteFailedBackupData::class => 'null',
];
```

### Delete Finished Process Hook

The **Delete Finished Process Hook** automatically removes the finished backup or restore process entity from the process repository once the job has completed.  
This keeps the process list clean, prevents accumulation of old entries, and is ideal for production environments where only active or failed jobs should remain visible.

This hook supports:

- Automatic deletion of completed **backup** and **restore** processes  
- Safe deletion using the repository's `deleteById()` method  
- Logging of deletion failures (e.g., repository errors)  
- UI integration via `defaultSelected`  

**Config**

Define the hook in your [config file](#backup-config):

```php
'hooks' => [
    // Add this hook last:
    // It deletes the finished process entity,
    // so hooks that read the process entity from the repository must run earlier.
    new Hook\DeleteFinishedProcess(
        name: 'Auto‑Delete Finished Process (removes it from the process list)',
        group: 'Maintenance',
        defaultSelected: false,
    ),
]
```

**Logging**

This hook uses the [`LoggerTrait`](https://github.com/tobento-ch/app-logging#logger-trait) for logging warnings when deletion fails.

You may configure which logger should be used for this hook in your `app/config/logging.php` file.  
If no alias is defined, the default logger will be used.

```php
'aliases' => [
    // Route deletion warnings to the "daily" logger:
    \Tobento\App\Backup\Hook\DeleteFinishedProcess::class => 'daily',

    // Or disable logging entirely:
    \Tobento\App\Backup\Hook\DeleteFinishedProcess::class => 'null',
];
```

### Notify Hook

The **Notify Hook** sends notifications to a **specific, predefined recipient**.  
This recipient is supplied directly in the hook configuration and may represent a developer, administrator, support team, monitoring system, or any other fixed destination.

Unlike user-based notification hooks, this hook **does not resolve the job's user or roles**.  
Instead, it always sends notifications to the exact `RecipientInterface` instance provided in the configuration.

This hook supports:

- Any notifier channel (`mail`, `browser`, `sms`, ...)
- Custom notification subjects
- Queueing via a named queue
- Selecting which backup/restore lifecycle events should trigger a notification
- UI integration via `defaultSelected`
- Both **backup** and **restore** processes

**Config**

Define the hook in your [config file](#backup-config):

```php
'hooks' => [

    new Hook\Notify(
        // Unique identifier:
        id: 'notify.user',

        // Display name shown in the UI:
        name: 'Notify Developer via Mail and SMS',
        
        // Define the recipient:
        recipient: new Recipient(
            email: 'dev@example.com',
            phone: '15556666666',
            channels: ['mail', 'sms'],
        ),

        // Subject template (optional):
        notificationSubject: 'Job :name :event',

        // Queue name (optional):
        queueName: null, // e.g. 'file'

        // Events to notify on:
        notifyOn: [
            'started',
            'stepExecuting',
            'stepExecuted',
            'actionFinished',
            'finished',
            'failed',
        ],

        // Optional grouping label used in the UI (default: 'Notify'):
        group: 'Notify',

        // Should this hook be pre-selected in the UI?
        defaultSelected: true,
    ),
]
```

**Additional Resources**

To learn more about notifications, channels, recipients, and queueing, see:

- [Notifications for Background Jobs](#notifications-for-background-jobs)

- App Notifier  
  https://github.com/tobento-ch/app-notifier

- Service Notifier  
  https://github.com/tobento-ch/service-notifier

### Notify Current User Hook

The **Notify Current User Hook** sends notifications to the **user who triggered the backup or restore job**.  
It is ideal for providing real-time feedback during long-running backup or restore operations.

This hook supports:

- Browser notifications (recommended default)
- Any notifier channel (`mail`, `storage`, `sms`, ...)
- Custom notification subjects
- Queueing
- Selecting which backup/restore lifecycle events should trigger a notification

**Config**

Define the hook in your [config file](#backup-config):

```php
'hooks' => [

    new Hook\NotifyCurrentUser(
        // Unique identifier:
        id: 'notify.current-user',
        
        // Display name shown in the UI:
        name: 'Keep me updated about this job',
        
        // Channels used to notify the current user:
        channels: ['browser'],
        
        // Customize the notification subject (optional):
        notificationSubject: 'Job :name :event',
        
        // Events to notify on (optional):
        notifyOn: [
            'started',
            'stepExecuting',
            'stepExecuted',
            'actionFinished',
            'finished',
            'failed',
        ],
        
        // Send the notification via queue (optional):
        queueName: 'file', // null by default
        
        // Should this hook be pre-selected in the UI?
        defaultSelected: true,
        
        // Optional grouping label used in the UI (default: 'Notify'):
        group: 'Notify',
    ),
]
```

**Additional Resources**

To learn more about notifications, channels, recipients, and queueing, see:

- [Notifications for Background Jobs](#notifications-for-background-jobs)

- App Notifier  
  https://github.com/tobento-ch/app-notifier

- Service Notifier  
  https://github.com/tobento-ch/service-notifier

### Notify Users Hook

The **Notify Users Hook** sends notifications to **all users matching specific roles**.  
It is ideal for notifying administrators, operators, managers, or any internal user group that should be informed about backup or restore activity.

This hook uses the [User Repository](https://github.com/tobento-ch/app-user#user-repositories-and-factories) to look up users by their roles before sending notifications.  
It can notify multiple users at once and supports limiting the maximum number of recipients.

This hook supports:

- Notifying multiple users (role-based)
- Any notifier channel (`storage`, `browser`, `mail`, `sms`, ...)
- Custom notification subjects
- Queueing via a named queue
- Selecting which backup/restore lifecycle events should trigger a notification
- Limiting the number of notified users
- Optional grouping label for UI organization

**Config**

Define the hook in your [config file](#backup-config):

```php
'hooks' => [

    new Hook\NotifyUsers(
        // Unique identifier:
        id: 'notify.administrators',

        // Display name shown in the UI:
        name: 'Notify Administrators via Account and Browser',
        
        // Roles whose users should be notified:
        roles: ['administrator'],
        
        // Channels used to notify these users:
        channels: ['storage', 'browser'],
        
        // Customize the notification subject (optional):
        notificationSubject: 'Job :name :event',
        
        // Maximum number of users to notify:
        limit: 100,
        
        // Send the notification via queue (optional):
        queueName: 'file', // null by default
        
        // Events to notify on:
        notifyOn: [
            'started',
            'stepExecuting',
            'stepExecuted',
            'actionFinished',
            'finished',
            'failed',
        ],
        
        // Should this hook be pre-selected in the UI?
        defaultSelected: false,
        
        // Optional grouping label used in the UI (default: 'Notify'):
        group: 'Notify',
    ),
]
```

**Additional Resources**

To learn more about notifications, channels, recipients, and queueing, see:

- [Notifications for Background Jobs](#notifications-for-background-jobs)

- App Notifier  
  https://github.com/tobento-ch/app-notifier

- Service Notifier  
  https://github.com/tobento-ch/service-notifier

### Process Lifecycle Hook

The **Process Lifecycle Hook** updates the process record during backup and restore execution.  
It is always active and cannot be selected or configured in the backup/restore job editor.  
The backup job handler automatically registers it for every job.

This hook writes process status, runtime information, and memory usage to the process repository at key points in the lifecycle:

- **processStarted**  
  Sets the process status to `processing`.

- **actionStepExecuting**  
  Records the timestamp when the step begins and initializes per-action runtime tracking.

- **actionStepExecuted**  
  Measures the duration of the step, accumulates runtime for the action, and clears the step start timestamp.

- **processFinished**  
  Marks the process as `completed`, stores peak memory usage, and calculates the total runtime across all actions.

- **processFailed**  
  Marks the process as `failed`.

The hook ensures that the process entity always reflects the current execution state and metrics.  
It provides accurate runtime and memory statistics for monitoring, analytics, dashboards, or audit logs.

**Registration**

The hook is registered internally by the backup/restore job handler:

```php
use Tobento\App\Backup\Hook;

protected function mandatoryHooks(): array
{
    return [
        new Hook\ProcessLifecycle(name: 'Process Lifecycle'),
    ];
}
```

This means the hook is always included for every backup or restore job and does not need to be defined in the configuration.

**Customization**

If you need to customize how lifecycle events are handled, you may extend the job handler and override the `mandatoryHooks()` method.  
For example, you can replace or extend the default lifecycle hook:

```php
use Tobento\App\Backup\Queue\BackupJobHandler;

class CustomBackupJobHandler extends BackupJobHandler
{
    protected function mandatoryHooks(): array
    {
        return [
            new MyCustomProcessLifecycleHook(name: 'Custom Process Lifecycle'),
        ];
    }
}
```

Next, register your customized queue handler in the [config file](#backup-config):

```php
use Tobento\App\Backup\Queue\BackupJobHandler;

'interfaces' => [
    BackupJobHandler::class => CustomBackupJobHandler::class,
],
```

This gives you full control over how backup and restore jobs record lifecycle state, runtime information, and repository updates. The same customization mechanism applies to `Tobento\App\Backup\Queue\RestoreJobHandler`.

### Send Generated Backup ZIP To Email Hook

The **Send Generated Backup ZIP to Email Hook** delivers the generated backup ZIP file to a predefined email address once the backup process has finished.  
It is ideal for automated off-site delivery, external archiving, or sending backups to administrators, operators, or external storage systems via email.

This hook supports:

- Sending the final ZIP artifact as an email attachment
- Custom email subjects and message bodies
- Logging failures (e.g., missing ZIP, mailer errors)
- Backup-only operation (restore processes do not generate ZIP artifacts)

**Config**

Define the hook in your [config file](#backup-config):

```php
'hooks' => [

    new Hook\SendGeneratedBackupZipToEmail(
        // Unique identifier:
        id: 'send.backup.zip',

        // Display name shown in the UI:
        name: 'Send Backup ZIP to Email',

        // Recipient email address:
        email: 'admin@example.com',

        // Email subject (supports {name} placeholder):
        subject: 'Backup Completed: {name}',

        // Email message body:
        message: 'Your backup has completed successfully. The ZIP file is attached.',

        // Optional grouping label used in the UI (default: 'Delivery'):
        group: 'Delivery',

        // Should this hook be pre-selected in the UI?
        defaultSelected: false,
    ),
]
```

**Logging**

This hook supports application-level logging using the [`LoggerTrait`](https://github.com/tobento-ch/app-logging#logger-trait).  
This allows the hook to log:

- Missing ZIP artifacts  
- Mailer failures  
- Attachment errors  
- Unexpected exceptions  

By default, the **default logger** is used.  
You may override which logger the hook uses by defining a logger alias in your `app/config/logging.php` file.

```php
'aliases' => [

    // Route backup ZIP delivery logs to the "daily" logger:
    \Tobento\App\Backup\Hook\SendGeneratedBackupZipToEmail::class => 'daily',

    // Or disable logging entirely:
    \Tobento\App\Backup\Hook\SendGeneratedBackupZipToEmail::class => 'null',
];
```

## Learn More

### Scheduling Backups

To allow users to schedule backups directly from the web interface, install the  
[App Task](https://github.com/tobento-ch/app-task) bundle and register a backup task registry in the [task config file](https://github.com/tobento-ch/app-task#task-config).

This makes the backup task available in the scheduling UI, where users can choose the backup to run and define when it should execute.

```php
use Tobento\App\Backup\Task\BackupRegistry;

'registries' => [
    'backup.run' => new BackupRegistry(
        name: 'Run backups',
        
        // You may add task parameters to be always processed:
        parameters: [
            new \Tobento\Service\Schedule\Parameter\WithoutOverlapping(),
        ],
        
        // Define the supported apps where the task can be run:
        supportedAppIds: ['root', 'backend'],
    ),
]
```

The registry exposes a Backup select field in the UI, allowing users to pick the backup configuration they want to run.  
When the scheduled task triggers, it queues a backup job using the Backup Job Handler, ensuring that all lifecycle hooks, notifications, and artifact handling work exactly as they do for manually triggered backups.

### Scheduling Pruning Backups

To allow users to schedule pruning of old backup artifacts and (optionally) backup processes directly from the web interface, install the
[App Task](https://github.com/tobento-ch/app-task) bundle and register a pruning task registry in the [task config file](https://github.com/tobento-ch/app-task#task-config).

This makes the pruning task available in the scheduling UI, where users can define how long backups should be retained and whether backup processes should also be removed.

```php
use Tobento\App\Backup\Task\PruneBackupsRegistry;

'registries' => [
    'backup.prune' => new PruneBackupsRegistry(
        name: 'Prune backups',

        // You may add task parameters to be always processed:
        parameters: [
            new \Tobento\Service\Schedule\Parameter\WithoutOverlapping(),
        ],

        // Define the supported apps where the task can be run:
        supportedAppIds: ['root', 'backend'],
    ),
]
```

**The registry exposes two fields in the scheduling UI:**

- **Retention (days)** - determines how old a backup artifact must be before it is pruned.
- **Include Backup Processes** - optionally removes backup process entries older than the same retention window.

When the scheduled task triggers, it prunes all restore entries (backup artifacts) older than the configured number of days.  
If enabled, it also prunes backup processes from the Processes Feature, ensuring that both the restore list and process list remain clean and manageable over time.

### Advanced Runtime Configuration

Backup and restore jobs run inside a unified execution time budget.  
This budget determines:

- how long a backup or restore job may run on the queue  
- how much processing time is available for chunked actions  
- whether long-running backup/restore processes can complete without being terminated by the worker  

The runtime configuration is provided through `BackupRuntimeConfigInterface` and can be customized in the application's DI configuration in the [backup config file](#backup-config).

```php
'interfaces' => [
    BackupRuntimeConfigInterface::class => function () {
        return new BackupRuntimeConfig(
            // Run backups and restores on a specific queue.
            // If null, the default queue is used.
            // **Synchronous execution (without a queue) is not supported.**
            // Backup and restore processes require queued, time‑budgeted execution.
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
]
```

#### Why this matters

**Queue worker timeout alignment**  
The `jobTimeBudgetInSeconds` must not exceed the worker's timeout.  
If the worker kills the job early, backups or restores may fail mid-process.

**Long-running backups**  
Large backups (e.g., filesystem, database dumps, remote artifacts) may require a higher time budget to complete.

**Encryption throughput**  
The encryption layer uses `encryptThroughputInBytes` to estimate whether an encrypted backup can fit into the time budget.  
Increasing this value allows larger encrypted backups to run without being deferred.

**Default encrypter selection**  
The `encrypter` value determines which encrypter is used when a backup is configured for encryption but does not specify an encrypter explicitly.

#### When to adjust these settings

You should tune the runtime configuration when:

- backups or restores are deferred due to time-budget feasibility checks  
- queue workers terminate jobs prematurely  
- encrypted backups are large and require more throughput  
- you want backups to run on a dedicated queue  
- you need predictable runtime behavior across environments  

### Adding Registries Via App

In addition to defining registries in the [config file](#backup-config), you can register action and artifact registries **dynamically** using the application's [`on`](https://github.com/tobento-ch/app#on) method.  
This is useful when certain registries should only be added under specific conditions (e.g., based on environment, modules, or runtime state).

```php
use Tobento\App\Backup\Registry\Action\ActionRegistriesInterface;
use Tobento\App\Backup\Registry\Action\ActionRegistryInterface;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistryInterface;

$app->on(
    ActionRegistriesInterface::class,
    static function(ActionRegistriesInterface $registries): void {
        $registries->add(
            registry: $registry, // ActionRegistryInterface
        );
    }
);

$app->on(
    ArtifactRegistriesInterface::class,
    static function(ArtifactRegistriesInterface $registries): void {
        $registries->add(
            registry: $registry, // ArtifactRegistryInterface
        );
    }
);
```

This approach allows you to attach registries only when needed, without modifying the global configuration.

### Adding Hooks Via App

In addition to defining hooks in the [config file](#backup-config), you can register hooks **dynamically** using the application's [`on`](https://github.com/tobento-ch/app#on) method.  
This is useful when certain hooks should only be added under specific conditions (e.g., environment-based, module-based, or runtime-based activation).

```php
use Tobento\App\Backup\Hook\HooksInterface;
use Tobento\App\Backup\Hook\SendGeneratedBackupZipToEmail;

$app->on(
    HooksInterface::class,
    static function(HooksInterface $hooks): void {
        $hooks->add(
            id: 'mail.dev',
            hook: new SendGeneratedBackupZipToEmail(
                id: 'mail.dev',
                name: 'Mail Developer',
                email: 'dev@example.com',
                subject: 'Backup Completed: {name}',
                message: 'The backup ZIP is attached.',
            ),
        );
    }
);
```

This approach allows you to attach hooks only when needed, without modifying the global configuration.

### Notifications for Background Jobs

Backup and restore jobs run asynchronously in the queue.  
Notifications are handled through the **configured notification hooks** in the [Backup Config](#backup-config), which are available out of the box and can be selected by the user in the UI.

By default, the Backup package ships with several hooks, including:

- [Notify Current User Hook](#notify-current-user-hook) - browser notifications for the user who triggered the job (pre-selected)
- [Notify Users Hook](#notify-users-hook) - notify users based on roles (e.g., administrators)
- [Notify Hook](#notify-hook) - notify a fixed, predefined recipient (e.g., developer or monitoring system)

Users can choose which hooks should run for each backup or restore job directly in the UI.  
Hooks marked with `defaultSelected: true` (such as `notify.current-user`) are pre-selected automatically.

To receive browser notifications, the user must have the `notifications.browser` ACL permission.  
All other functionality works out of the box.

You may set the permission manually (see [ACL Service](https://github.com/tobento-ch/service-acl#permissions)), or, if you are using the [App Backend](https://github.com/tobento-ch/app-backend), you can assign this permission directly on the Roles or Users page.

# Credits

- [Tobias Strub](https://www.tobento.ch)
- [All Contributors](../../contributors)