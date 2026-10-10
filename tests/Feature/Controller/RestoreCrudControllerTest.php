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

namespace Tobento\App\Backup\Test\Feature\Controller;

use Tobento\App\AppInterface;
use Tobento\App\Backup\Feature\Restore;
use Tobento\App\Backup\Registry\Artifact\ZipArtifactRegistry;
use ZipArchive;

class RestoreCrudControllerTest extends \Tobento\App\Crud\Testing\AbstractCrudTestCase
{
    use \Tobento\App\Testing\Database\RefreshDatabases;

    protected string $tmpLocation;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        return $app;
    }

    protected function getCrudController(): string
    {
        return \Tobento\App\Backup\Controller\RestoreCrudController::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpLocation = sys_get_temp_dir().'/tobento-restore-test-'.uniqid().'/';
        mkdir($this->tmpLocation, 0755, true);
    }

    protected function tearDown(): void
    {
        // On Windows, ZipArchive handles opened during the test (e.g. via
        // ReadableZipArtifact reading manifest.json) may only release their
        // file lock when the object is garbage collected, not immediately
        // on going out of scope. Force collection before cleanup so unlink()
        // doesn't silently fail due to a still-open handle.
        gc_collect_cycles();

        $this->removeDir($this->tmpLocation);

        parent::tearDown();
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            // Best-effort: a locked file from a still-open handle shouldn't
            // fail the whole test run during cleanup.
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    protected function fakeZipArtifactRegistry(): ZipArtifactRegistry
    {
        return new ZipArtifactRegistry(
            id: 'zip.local',
            name: 'ZIP (Local Private Storage)',
            location: $this->tmpLocation,
        );
    }

    protected function seedRealZipFile(string $filename = 'backup-1.zip', array $manifest = ['name' => 'Test Backup']): void
    {
        $path = $this->tmpLocation.$filename;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
    }

    public function testIndexAction()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedRealZipFile('backup-1.zip');
        $this->seedRealZipFile('backup-2.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateIndexUri());

        $http->response()
            ->assertStatus(200)
            ->assertBodyContains(value: 'Restore Backups', escape: true)
            ->assertCrudIndexHeaderColumnsExists(columns: ['id', 'name', 'type', 'created_at'])
            ->assertCrudIndexEntityCount(2);
    }

    public function testIndexActionFailsWithoutPermission()
    {
        $this->fakeConfig()->with('backup.features', [
            new Restore(withAcl: true),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateIndexUri());

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You don\'t have a required "backups.restore" permission.');
    }

    public function testIndexActionShowsUploadZipButton()
    {
        $this->fakeConfig()->with('backup.features', [
            new Restore(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateIndexUri());

        $http->response()
            ->assertStatus(200)
            ->assertCrudIndexButtonsExists(buttons: ['upload-zip'], group: 'global');
    }

    public function testIndexActionShowsRestoreAndDownloadButtonsForZipType()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedRealZipFile('backup-1.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateIndexUri());

        $http->response()
            ->assertStatus(200)
            ->assertCrudIndexButtonsExists(buttons: ['restore', 'download-zip'], group: 'entity');
    }

    public function testCreateActionIsDisabled()
    {
        $this->fakeConfig()->with('backup.features', [
            new Restore(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateCreateUri());

        $http->response()->assertStatus(404);
    }
    
    public function testStoreActionIsDisabled()
    {
        $this->fakeConfig()->with('backup.features', [
            new Restore(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: $this->generateStoreUri());

        $http->response()->assertStatus(404);
    }

    public function testEditActionIsDisabled()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedRealZipFile('backup-1.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateEditUri(id: 'backup-1.zip'));

        $http->response()->assertStatus(404);
    }
    
    public function testUpdateActionIsDisabled()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedRealZipFile('backup-1.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'PATCH', uri: $this->generateUpdateUri(id: 'backup-1.zip'));

        $http->response()->assertStatus(404);
    }

    public function testShowActionIsDisabled()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedRealZipFile('backup-1.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateShowUri(id: 'backup-1.zip'));

        $http->response()->assertStatus(404);
    }

    public function testDeleteAction()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedRealZipFile('backup-1.zip');
        $this->seedRealZipFile('backup-2.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'DELETE', uri: $this->generateDeleteUri(id: 'backup-1.zip'));

        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateIndexUri());

        $http->followRedirects()
            ->assertStatus(200)
            ->assertCrudIndexEntityCount(1);

        $this->assertFileDoesNotExist($this->tmpLocation.'backup-1.zip');
    }
    
    public function testDeleteActionWorksWithFilenameContainingSpecialCharsMethod()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        // One filename containing ALL special character categories:
        // - spaces
        // - unicode letters (ÄÖÜ)
        // - underscore
        // - hyphen
        // - multiple dots
        // - mixed casing
        $filename = 'Sicherung Länder_v2.final-ÄÖÜ Backup.zip';

        // Seed two files so index count can be asserted
        $this->seedRealZipFile($filename);
        $this->seedRealZipFile('other-file.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'DELETE', uri: $this->generateDeleteUri(id: $filename));

        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateIndexUri());

        $http->followRedirects()
            ->assertStatus(200)
            ->assertCrudIndexEntityCount(1);

        $this->assertFileDoesNotExist($this->tmpLocation.$filename);
    }

    public function testDeleteActionFailsWithoutPermission()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: true)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedRealZipFile('backup-1.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'DELETE', uri: $this->generateDeleteUri(id: 'backup-1.zip'));

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You don\'t have a required "backups.restore" permission.');
    }

    public function testBulkDeleteAction()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedRealZipFile('backup-1.zip');
        $this->seedRealZipFile('backup-2.zip');
        $this->seedRealZipFile('backup-3.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: $this->generateBulkUri(action: 'bulk-delete'))->body([
            'ids' => ['backup-2.zip', 'backup-3.zip'],
        ]);

        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateIndexUri());

        $http->followRedirects()
            ->assertStatus(200)
            ->assertCrudIndexEntityCount(1);

        $this->assertFileExists($this->tmpLocation.'backup-1.zip');
        $this->assertFileDoesNotExist($this->tmpLocation.'backup-2.zip');
    }
}