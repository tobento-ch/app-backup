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

namespace Tobento\App\Backup\Test\Feature\Crud;

use Tobento\App\AppInterface;
use Tobento\App\Backup\Feature\Restore;
use Tobento\App\Backup\Registry\Artifact\ZipArtifactRegistry;
use ZipArchive;

class DownloadZipActionTest extends \Tobento\App\Crud\Testing\AbstractCrudTestCase
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

        $this->tmpLocation = sys_get_temp_dir().'/tobento-download-zip-test-'.uniqid('', true).'/';
        mkdir($this->tmpLocation, 0755, true);
    }

    protected function tearDown(): void
    {
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

    protected function generateDownloadZipUri(string $id): string
    {
        return $this->generateDynamicUri(action: 'download-zip', id: $id);
    }

    public function testHandleDownloadsZipFile()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedRealZipFile('backup-1.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateDownloadZipUri('backup-1.zip'));

        $http->response()
            ->assertStatus(200)
            ->assertHasHeader('Content-Type', 'application/zip');
    }

    public function testHandleFailsWhenEntityNotFound()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateDownloadZipUri('does-not-exist.zip'));

        $http->response()->assertStatus(404);
    }

    public function testHandleFailsForNonZipFile()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        // Seed a non-zip file directly into the same location the
        // registry's repository lists files from.
        file_put_contents($this->tmpLocation.'notes.txt', 'not a zip');

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateDownloadZipUri('notes.txt'));

        $http->response()->assertStatus(422);
    }

    public function testHandleFailsWithNonGetMethod()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedRealZipFile('non-get-method-test.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: $this->generateDownloadZipUri('non-get-method-test.zip'));
        
        $http->followRedirects()->assertStatus(404);
    }

    public function testHandleFailsWithoutPermission()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: true)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedRealZipFile('backup-1.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateDownloadZipUri('backup-1.zip'));

        $http->response()->assertStatus(403);
    }
    
    public function testHandleWorksWithFilenameContainingSpecialChars()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        // - spaces
        // - multiple dots
        // - underscore
        // - hyphen
        // - unicode letters (ä, ö, ü)
        // - mixed casing
        $filename = 'Sicherung Länder_v2.final-ÄÖÜ Backup.zip';

        $this->seedRealZipFile('Sicherung Länder_v2.final-ÄÖÜ Backup.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateDownloadZipUri($filename));

        $http->response()
            ->assertStatus(200)
            ->assertHasHeader('Content-Type', 'application/zip');
    }
}