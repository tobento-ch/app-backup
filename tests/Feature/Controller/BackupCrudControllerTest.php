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
use Tobento\App\Backup\Feature\Backups;
use Tobento\App\Backup\Registry\Artifact\ZipArtifactRegistry;
use function Tobento\App\directory;

class BackupCrudControllerTest extends \Tobento\App\Crud\Testing\AbstractCrudTestCase
{
    use \Tobento\App\Testing\Database\RefreshDatabases;
    use \Tobento\App\Testing\FileStorage\RefreshFileStorages;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        return $app;
    }

    protected function getCrudController(): string
    {
        return \Tobento\App\Backup\Controller\BackupCrudController::class;
    }

    protected function fakeZipArtifactRegistry(): ZipArtifactRegistry
    {
        return new ZipArtifactRegistry(
            id: 'zip.local',
            name: 'ZIP (Local Private Storage)',
            location: sys_get_temp_dir().'/tobento-backup-test-'.uniqid().'/',
        );
    }

    public function testIndexAction()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateIndexUri());

        $this->getSeedFactory()->times(2)->create();

        $http->response()
            ->assertStatus(200)
            ->assertBodyContains(value: 'Backups', escape: true)
            ->assertCrudIndexHeaderColumnsExists(columns: ['name', 'created_at'])
            ->assertCrudIndexEntityCount(2);
    }

    public function testIndexActionFailsWithoutPermission()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: true),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateIndexUri());

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You don\'t have a required "backups" permission.');
    }

    public function testCreateAction()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Backups(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateCreateUri());

        $http->response()
            ->assertStatus(200)
            ->assertBodyContains('New Backup')
            ->assertCrudFormFieldExists(field: 'name')
            ->assertCrudFormFieldExists(field: 'options.artifacts')
            ->assertCrudFormFieldExists(field: 'options.apps.dirs.root')
            ->assertCrudFormFieldExists(field: 'options.app.dir.root.vendor')
            ->assertCrudFormFieldExists(field: 'options.apps.dirs.root')
            ->assertCrudFormFieldExists(field: 'meta.encrypt');
    }

    public function testCreateActionFailsWithoutPermission()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: true),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateCreateUri());

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You don\'t have a required "backups" permission.');
    }

    public function testStoreAction()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Backups(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->previousUri($this->generateCreateUri());
        $http->request(method: 'POST', uri: $this->generateStoreUri())->body([
            'name' => 'Nightly Backup',
            'options' => [
                'artifacts' => ['zip.local'],
            ],
            'meta' => [
                'encrypt' => '0',
            ],
        ]);

        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateIndexUri());

        $http->followRedirects()
            ->assertStatus(200)
            ->assertCrudIndexEntityCount(1);

        $this->assertSame(1, $this->getCrudRepository()->count());

        $backup = $this->getCrudRepository()->findById(1);

        $this->assertSame('Nightly Backup', $backup->name());
        $this->assertSame(0, $backup->userId());
    }
    
    public function testStoreActionStoresCurrentUserIdIfAvailable()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Backups(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $auth = $this->fakeAuth();
        $http = $this->fakeHttp();
        $http->previousUri($this->generateCreateUri());
        $http->request(method: 'POST', uri: $this->generateStoreUri())->body([
            'name' => 'Nightly Backup',
            'options' => [
                'artifacts' => ['zip.local'],
            ],
        ]);
        
        $this->bootingApp();
        $user = $auth->getUserRepository()->create(['username' => 'tom']);
        $auth->authenticatedAs($user);

        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateIndexUri());

        $http->followRedirects()->assertStatus(200);

        $backup = $this->getCrudRepository()->findById(1);
        $this->assertSame(1, $backup->userId());
    }

    public function testStoreActionFailsWithoutArtifactSelected()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Backups(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->previousUri($this->generateCreateUri());
        $http->request(method: 'POST', uri: $this->generateStoreUri())->body([
            'name' => 'Nightly Backup',
            'options' => [
                'artifacts' => [],
            ],
        ]);

        $http->followRedirects()
            ->assertStatus(200)
            ->assertBodyContains('required');

        $this->assertSame(0, $this->getCrudRepository()->count());
    }

    public function testStoreActionFailsWithoutPermission()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Backups(withAcl: true)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->previousUri($this->generateCreateUri());
        $http->request(method: 'POST', uri: $this->generateStoreUri())->body([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => ['zip.local']],
        ]);

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You don\'t have a required "backups" permission.');
    }

    public function testEditAction()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Backups(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateEditUri(id: 1));

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => ['zip.local']],
        ])->times(1)->create();

        $http->response()
            ->assertStatus(200)
            ->assertBodyContains('Edit Backup')
            ->assertCrudFormFieldExists(field: 'name')
            ->assertCrudFormFieldExists(field: 'options.artifacts')
            ->assertCrudFormFieldExists(field: 'options.apps.dirs.root')
            ->assertCrudFormFieldExists(field: 'options.app.dir.root.vendor')
            ->assertCrudFormFieldExists(field: 'options.hooks.0')
            ->assertCrudFormFieldExists(field: 'meta.encrypt');

        $backup = $this->getCrudRepository()->findById(1);

        $this->assertSame('Nightly Backup', $backup->name());
    }

    public function testEditActionFailsWithoutPermission()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Backups(withAcl: true)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateEditUri(id: 1));

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => ['zip.local']],
        ])->times(1)->create();

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You don\'t have a required "backups" permission.');
    }

    public function testUpdateAction()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Backups(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->previousUri($this->generateIndexUri());
        $http->request(method: 'PATCH', uri: $this->generateUpdateUri(id: 1))->body([
            'name' => 'Nightly Backup Renamed',
            'options' => [
                'artifacts' => ['zip.local'],
            ],
        ]);

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => ['zip.local']],
        ])->times(1)->create();

        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateIndexUri());

        $http->followRedirects()->assertStatus(200);

        $backup = $this->getCrudRepository()->findById(1);
        $this->assertSame('Nightly Backup Renamed', $backup->name());
        $this->assertSame(0, $backup->userId());
    }

    public function testUpdateActionFailsWithoutPermission()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Backups(withAcl: true)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);

        $http = $this->fakeHttp();
        $http->previousUri($this->generateIndexUri());
        $http->request(method: 'PATCH', uri: $this->generateUpdateUri(id: 1))->body([
            'name' => 'Nightly Backup Renamed',
        ]);

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => ['zip.local']],
        ])->times(1)->create();

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You don\'t have a required "backups" permission.');
    }
    
    public function testUpdateActionStoresCurrentUserIdIfAvailable()
    {
        $this->fakeConfig()
            ->with('backup.features', [new Backups(withAcl: false)])
            ->with('backup.artifacts', [$this->fakeZipArtifactRegistry()]);
        
        $auth = $this->fakeAuth();
        $http = $this->fakeHttp();
        $http->previousUri($this->generateIndexUri());
        $http->request(method: 'PATCH', uri: $this->generateUpdateUri(id: 1))->body([
            'name' => 'Nightly Backup Renamed',
            'options' => [
                'artifacts' => ['zip.local'],
            ],
        ]);

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => ['zip.local']],
        ])->times(1)->create();
        
        $user = $auth->getUserRepository()->create(['username' => 'tom']);
        $auth->authenticatedAs($user);

        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateIndexUri());

        $http->followRedirects()->assertStatus(200);

        $backup = $this->getCrudRepository()->findById(1);
        $this->assertSame(1, $backup->userId());
    }

    public function testShowActionIsDisabled()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateShowUri(id: 1));

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => []],
        ])->times(1)->create();

        $http->response()->assertStatus(404);
    }

    public function testCopyActionIsDisabled()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateCopyUri(id: 1));

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => []],
        ])->times(1)->create();

        $http->response()->assertStatus(404);
    }

    public function testDeleteAction()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'DELETE', uri: $this->generateDeleteUri(id: 1));

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => []],
        ])->times(2)->create();

        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateIndexUri());

        $http->followRedirects()
            ->assertStatus(200)
            ->assertCrudIndexEntityCount(1);

        $this->assertSame(1, $this->getCrudRepository()->count());
    }

    public function testDeleteActionFailsWithoutPermission()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: true),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'DELETE', uri: $this->generateDeleteUri(id: 1));

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => []],
        ])->times(1)->create();

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You don\'t have a required "backups" permission.');
    }

    public function testBulkDeleteAction()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: $this->generateBulkUri(action: 'bulk-delete'))->body([
            'ids' => [2, 3],
        ]);

        $this->getSeedFactory([
            'options' => ['artifacts' => []],
        ])->times(5)->create();

        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateIndexUri());

        $http->followRedirects()
            ->assertStatus(200)
            ->assertCrudIndexEntityCount(3);

        $this->assertNotNull($this->getCrudRepository()->findById(1));
        $this->assertNull($this->getCrudRepository()->findById(2));
        $this->assertNull($this->getCrudRepository()->findById(3));
    }
}