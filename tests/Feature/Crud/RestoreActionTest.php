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
use Tobento\App\Backup\Queue\RestoreJobHandler;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Backup\Registry\Artifact\ZipArtifactRegistry;
use Tobento\Service\Queue\JobInterface;
use Tobento\Service\Queue\Parameter\Duration;

class RestoreActionTest extends \Tobento\App\Crud\Testing\AbstractCrudTestCase
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
        $this->tmpLocation = sys_get_temp_dir().'/restore-action-test-'.uniqid('', true).'/';
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

    protected function seedZip(
        string $filename,
        array $manifest = ['process_id' => 1, 'name' => 'Restore Test']
    ): void {
        $zip = new \ZipArchive();
        $zip->open($this->tmpLocation.$filename, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
    }

    protected function generateRestoreUri(string $id): string
    {
        return $this->generateDynamicUri(action: 'restore', id: $id);
    }

    public function testGetRestoreActionShowsForm()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedZip(filename: 'restore-test.zip', manifest: [
            'process_id' => 1,
            'name' => 'Restore Test',
            'actions' => [
                ['class' => 'Tobento\\App\\Backup\\Action\\AppsDirectory', 'data' => [
                    'dirs' => ['root' => ['config', 'countries']],
                    'targetFolder' => 'apps-directory/',
                    'timeBudgetInSeconds' => 20,
                ]],
            ],
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateRestoreUri('restore-test.zip'));
        

        $http->response()
            ->assertStatus(200)
            ->assertBodyContains('Restore')
            ->assertCrudFormFieldExists(field: 'name')
            ->assertCrudFormFieldExists(field: 'user_id')
            ->assertCrudFormFieldExists(field: 'domain')
            ->assertCrudFormFieldExists(field: 'options.apps.dirs.root')
            ->assertCrudFormFieldExists(field: 'options.hooks.0');
    }

    public function testPostRestoreActionQueuesJob()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedZip('restore-job.zip');

        $fakeQueue = $this->fakeQueue();
        $http = $this->fakeHttp();

        $http->request(method: 'POST', uri: $this->generateRestoreUri('restore-job.zip'));

        $http->response()->assertStatus(302);

        $fakeQueue->queue(name: 'file')->assertPushed(
            RestoreJobHandler::class,
            fn(JobInterface $job) =>
                $job->getPayload()['restore_id'] === 'restore-job.zip'
        );
    }

    public function testPostRestoreActionUsesRuntimeConfig()
    {
        $this->onCreateApp(function(AppInterface $app) {
            $app->on(BackupRuntimeConfigInterface::class, function() {
                return new \Tobento\App\Backup\BackupRuntimeConfig(
                    queueName: 'database',
                    jobTimeBudgetInSeconds: 99,
                );
            });
        });

        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedZip('restore-config.zip');

        $fakeQueue = $this->fakeQueue();
        $http = $this->fakeHttp();

        $http->request(method: 'POST', uri: $this->generateRestoreUri('restore-config.zip'));
        
        $http->response()->assertStatus(302);
        
        $fakeQueue->queue(name: 'database')->assertPushed(
            RestoreJobHandler::class,
            fn(JobInterface $job) => $job->parameters()->get(Duration::class)?->seconds() === 99,
        );

        $fakeQueue->queue(name: 'file')->assertNotPushed(RestoreJobHandler::class);
    }

    public function testPostRestoreActionStoresAuthenticatedUser()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedZip('restore-auth.zip');

        $auth = $this->fakeAuth();
        $fakeQueue = $this->fakeQueue();
        $http = $this->fakeHttp();

        $http->request(method: 'POST', uri: $this->generateRestoreUri('restore-auth.zip'));
        
        $this->bootingApp();
        $user = $auth->getUserRepository()->create(['username' => 'tom']);
        $auth->authenticatedAs($user);
        
        $http->response()->assertStatus(302);
        
        $fakeQueue->queue(name: 'file')->assertPushed(
            RestoreJobHandler::class,
            fn(JobInterface $job) => $job->getPayload()['user_id'] === $user->id()
        );
    }

    public function testRestoreActionFailsWhenEntityNotFound()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateRestoreUri('missing.zip'));

        $http->response()->assertStatus(404);
    }

    public function testRestoreActionFailsWithoutPermission()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: true)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedZip('restore-acl.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateRestoreUri('restore-acl.zip'));

        $http->response()->assertStatus(403);
    }

    public function testRestoreActionFailsWithWrongHttpMethod()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $this->seedZip('restore-wrong-method.zip');

        $http = $this->fakeHttp();
        $http->request(method: 'PUT', uri: $this->generateRestoreUri('restore-wrong-method.zip'));

        $http->followRedirects()->assertStatus(404);
    }

    public function testRestoreActionFailsWhenUsingSyncQueue()
    {
        $this->onCreateApp(function(AppInterface $app) {
            $app->on(BackupRuntimeConfigInterface::class, function() {
                return new \Tobento\App\Backup\BackupRuntimeConfig(
                    queueName: 'sync',
                );
            });
        });

        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        // Seed restore artifact (same as your working restore tests)
        $this->seedZip('restore-config.zip');

        $fakeQueue = $this->fakeQueue();
        $http = $this->fakeHttp();

        // Trigger restore action
        $http->request(method: 'POST', uri: $this->generateRestoreUri('restore-config.zip'));
        
        // Assert HTTP 500 (exception thrown inside action)
        $http->response()->assertStatus(500);

        // Assert job was NOT queued on sync queue
        $fakeQueue->queue(name: 'sync')->assertNotPushed(RestoreJobHandler::class);
    }

    public function testRestoreActionWorksWithFilenameContainingSpecialChars()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Restore(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $filename = 'Sicherung Länder_v2.final-ÄÖÜ Backup.zip';

        $this->seedZip($filename);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateRestoreUri($filename));

        $http->response()->assertStatus(200);
    }
}