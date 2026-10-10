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
use Tobento\App\Backup\Feature\Processes;
use Tobento\App\Backup\Feature\Restore;

class ProcessCrudControllerTest extends \Tobento\App\Crud\Testing\AbstractCrudTestCase
{
    use \Tobento\App\Testing\Database\RefreshDatabases;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        return $app;
    }

    protected function getCrudController(): string
    {
        return \Tobento\App\Backup\Controller\ProcessCrudController::class;
    }

    public function testIndexAction()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateIndexUri());

        $this->getSeedFactory([
            'status' => 'completed',
            'name' => 'Nightly Backup',
            'type' => 'backup',
            'type_id' => 1,
        ])->times(2)->create();

        $http->response()
            ->assertStatus(200)
            ->assertBodyContains(value: 'Backup/Restore Processes', escape: true)
            ->assertCrudIndexHeaderColumnsExists(columns: ['name', 'status', 'type', 'created_at'])
            ->assertCrudIndexEntityCount(2);
    }

    public function testIndexActionFailsWithoutPermission()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: true),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateIndexUri());

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You don\'t have a required "backups.processes" permission.');
    }

    public function testIndexActionShowsEditBackupButtonForBackupType()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
            new Backups(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateIndexUri());

        $this->getSeedFactory([
            'status' => 'completed',
            'type' => 'backup',
            'type_id' => 1,
        ])->times(1)->create();

        $http->response()
            ->assertStatus(200)
            ->assertBodyContains('Edit Backup');
    }

    public function testIndexActionShowsEditRestoreButtonForRestoreType()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
            new Restore(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateIndexUri());

        $this->getSeedFactory([
            'status' => 'completed',
            'type' => 'restore',
            'type_id' => 1,
        ])->times(1)->create();

        $http->response()
            ->assertStatus(200)
            ->assertBodyContains('Edit Restore');
    }

    public function testShowAction()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateShowUri(id: 1));

        $this->getSeedFactory([
            'status' => 'completed',
            'name' => 'Nightly Backup',
            'type' => 'backup',
            'type_id' => 1,
            'actions' => [
                ['class' => 'SomeAction', 'data' => []],
            ],
            'processed_actions' => [0],
        ])->times(1)->create();

        $http->response()
            ->assertStatus(200)
            ->assertCrudFormFieldExists(field: 'processed_actions');
    }
    
    public function testShowActionDisplaysProcessedActions()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateShowUri(id: 1));

        $this->getSeedFactory([
            'type' => 'backup',
            'type_id' => 1,
            'actions' => [
                ['class' => 'Tobento\\App\\Backup\\Action\\AppsDirectory', 'data' => [
                    'dirs' => ['root' => ['config', 'countries']],
                    'targetFolder' => 'apps-directory/',
                    'timeBudgetInSeconds' => 20,
                ]],
                ['class' => 'Tobento\\App\\Backup\\Action\\NullAction', 'data' => ['id' => 'app.dir.root.vendor']],
                ['class' => 'Tobento\\App\\Backup\\Action\\NullAction', 'data' => ['id' => 'app.dir.root.public']],
                ['class' => 'Tobento\\App\\Backup\\Action\\NullAction', 'data' => ['id' => 'app.dir.root.root']],
                ['class' => 'Tobento\\App\\Backup\\Action\\NullAction', 'data' => ['id' => 'databases']],
            ],
            'processed_actions' => [
                'apps-directory/root/config/',
                'apps-directory/root/countries/',
                'apps.directory',
                'app.dir.root.vendor',
                'app.dir.root.public',
                'app.dir.root.root',
                'databases',
            ],
            'status' => 'completed',
            'name' => 'Backup Config and Countries',
            'artifacts' => ['zip.local'],
            'hooks' => [],
        ])->times(1)->create();

        $http->response()
            ->assertStatus(200)
            ->assertCrudFormFieldExists(field: 'processed_actions')
            ->assertBodyContains('Apps Directory')
            ->assertBodyNotContains('apps-directory/root/config/')
            ->assertBodyNotContains('apps-directory/root/countries/');
    }

    public function testShowActionFailsWithoutPermission()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: true),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateShowUri(id: 1));

        $this->getSeedFactory([
            'status' => 'completed',
            'type' => 'backup',
            'type_id' => 1,
        ])->times(1)->create();

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You don\'t have a required "backups.processes" permission.');
    }

    public function testCreateActionIsDisabled()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateCreateUri());

        $http->response()->assertStatus(404);
    }
    
    public function testStoreActionIsDisabled()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: $this->generateStoreUri());

        $http->response()->assertStatus(404);
    }

    public function testEditActionIsDisabled()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateEditUri(id: 1));

        $this->getSeedFactory([
            'status' => 'completed',
            'type' => 'backup',
            'type_id' => 1,
        ])->times(1)->create();

        $http->response()->assertStatus(404);
    }
    
    public function testUpdateActionIsDisabled()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'PATCH', uri: $this->generateUpdateUri(id: 1));

        $this->getSeedFactory([
            'status' => 'completed',
            'type' => 'backup',
            'type_id' => 1,
        ])->times(1)->create();
        
        $http->response()->assertStatus(404);
    }

    public function testDeleteAction()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'DELETE', uri: $this->generateDeleteUri(id: 1));

        $this->getSeedFactory([
            'status' => 'completed',
            'type' => 'backup',
            'type_id' => 1,
        ])->times(2)->create();

        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateIndexUri());

        $http->followRedirects()
            ->assertStatus(200)
            ->assertCrudIndexEntityCount(1);

        $this->assertSame(1, $this->getCrudRepository()->count());
    }

    public function testDeleteActionFailsWhenProcessing()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'DELETE', uri: $this->generateDeleteUri(id: 1));

        $this->getSeedFactory([
            'status' => 'processing',
            'type' => 'backup',
            'type_id' => 1,
        ])->times(1)->create();

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You cannot delete resources while they are processing.');

        $this->assertSame(1, $this->getCrudRepository()->count());
    }

    public function testDeleteActionFailsWithoutPermission()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: true),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'DELETE', uri: $this->generateDeleteUri(id: 1));

        $this->getSeedFactory([
            'status' => 'completed',
            'type' => 'backup',
            'type_id' => 1,
        ])->times(1)->create();

        $http->response()
            ->assertStatus(403)
            ->assertBodyContains('You don\'t have a required "backups.processes" permission.');
    }

    public function testBulkDeleteAction()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: $this->generateBulkUri(action: 'bulk-delete'))->body([
            'ids' => [2, 3],
        ]);

        $this->getSeedFactory([
            'status' => 'completed',
            'type' => 'backup',
            'type_id' => 1,
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

    public function testBulkDeleteActionFailsWhenAnyProcessing()
    {
        $this->fakeConfig()->with('backup.features', [
            new Processes(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: $this->generateBulkUri(action: 'bulk-delete'))->body([
            'ids' => [1],
        ]);

        $this->getSeedFactory([
            'status' => 'processing',
            'type' => 'backup',
            'type_id' => 1,
        ])->times(1)->create();

        $http->followRedirects()
            ->assertStatus(200)
            ->assertBodyContains('You cannot delete resources while they are processing.');

        $this->assertSame(1, $this->getCrudRepository()->count());
    }
}