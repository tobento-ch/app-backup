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
use Tobento\Service\FileStorage\StorageInterface;

class UploadZipActionTest extends \Tobento\App\Crud\Testing\AbstractCrudTestCase
{
    use \Tobento\App\Backup\Test\ZipHelperTrait;
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
        $this->tmpLocation = sys_get_temp_dir().'/upload-zip-test-'.uniqid('', true).'/';
        mkdir($this->tmpLocation, 0755, true);
    }

    protected function tearDown(): void
    {
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

    protected function generateUploadUri(): string
    {
        return $this->generateDynamicUri(action: 'upload-zip', id: 'upload');
    }
    
    public function testGetUploadZipActionShowsForm()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateUploadUri());

        $http->response()
            ->assertStatus(200)
            ->assertBodyContains('Upload')
            ->assertCrudFormFieldExists('file');
    }    

    public function testPostUploadZipActionStoresZipAndRedirectsToRestore()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        
        $zipFile = $http->getFileFactory()->createFileWithContent(
            filename: 'upload.zip',
            content: $this->createZipFileContent('upload.zip', []),
            mimeType: 'application/zip',
        );
        
        $http->request(
            method: 'POST',
            uri: $this->generateUploadUri(),
            files: ['file' => $zipFile],
        );

        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateDynamicUri(action: 'restore', id: 'upload.zip'));

        $files = glob($this->tmpLocation.'*.zip');
        $this->assertNotEmpty($files);
    }

    public function testPostUploadZipActionFailsForNonZipFile()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();

        $txtFile = $http->getFileFactory()->createFileWithContent(
            filename: 'not-a-zip.txt',
            content: 'invalid',
            mimeType: 'text/plain'
        );

        $http->request(
            method: 'POST',
            uri: $this->generateUploadUri(),
            files: ['file' => $txtFile],
        );
        
        $http->followRedirects()
            ->assertStatus(200)
            ->assertCrudFormFieldExists(field: 'file', errorText: 'The extension txt of the file not-a-zip.txt is disallowed. Allowed extensions are zip.');
    }

    public function testPostUploadZipActionFailsWhenNoZipRegistryAvailable()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', []); // no registry

        $http = $this->fakeHttp();

        $zipFile = $http->getFileFactory()->createFileWithContent(
            filename: 'upload.zip',
            content: $this->createZipFileContent('upload.zip', []),
            mimeType: 'application/zip',
        );

        $http->request(
            method: 'POST',
            uri: $this->generateUploadUri(),
            files: ['file' => $zipFile],
        );

        $http->followRedirects()->assertStatus(404);
        
        $files = glob($this->tmpLocation.'*.zip');
        $this->assertEmpty($files);
    }

    public function testUploadZipActionFailsWithWrongHttpMethod()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->request(method: 'PUT', uri: $this->generateUploadUri());
        
        $http->followRedirects()
            ->assertStatus(200)
            ->assertBodyContains('Action upload-zip not found.');
    }
    
    public function testUploadZipActionFailsWithoutPermission()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: true)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        
        $zipFile = $http->getFileFactory()->createFileWithContent(
            filename: 'upload.zip',
            content: $this->createZipFileContent('upload.zip', []),
            mimeType: 'application/zip',
        );
        
        $http->request(
            method: 'POST',
            uri: $this->generateUploadUri(),
            files: ['file' => $zipFile],
        );

        $http->response()->assertStatus(403);
    }    
}