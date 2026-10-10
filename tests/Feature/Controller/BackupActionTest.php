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
use Tobento\App\Backup\Queue\BackupJobHandler;
use Tobento\App\Backup\BackupRuntimeConfig;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\Service\Queue\JobInterface;
use Tobento\Service\Queue\Parameter\Duration;

class BackupActionTest extends \Tobento\App\Crud\Testing\AbstractCrudTestCase
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

    public function testIndexActionShowsBackupButton()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'GET', uri: $this->generateIndexUri());

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => []],
        ])->times(1)->create();

        $http->response()
            ->assertStatus(200)
            ->assertCrudIndexButtonsExists(buttons: ['backup'], group: 'entity');
    }

    public function testBackupActionQueuesJobAndRedirects()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: false),
        ]);

        $fakeQueue = $this->fakeQueue();
        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: 'backups/1/backup');

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => []],
        ])->times(1)->create();
        
        $http->response()
            ->assertStatus(302)
            ->assertLocation($this->generateIndexUri());
        
        $fakeQueue->queue(name: 'file')->assertPushed(
            BackupJobHandler::class,
            function (JobInterface $job): bool {
                return $job->getPayload()['backup_id'] === 1
                    && $job->getPayload()['user_id'] === 0;
            }
        );
    }
    
    public function testBackupActionQueuesJobUsingRuntimeConfig()
    {
        $this->onCreateApp(function(AppInterface $app) {
            $app->on(BackupRuntimeConfigInterface::class, function() {
                return new BackupRuntimeConfig(
                    queueName: 'database',
                    jobTimeBudgetInSeconds: 99,
                );
            });
        });
        
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: false),
        ]);

        $fakeQueue = $this->fakeQueue();
        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: 'backups/1/backup');

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => []],
        ])->times(1)->create();
        
        $http->response()->assertStatus(302);
        
        $fakeQueue->queue(name: 'database')->assertPushed(
            BackupJobHandler::class,
            function (JobInterface $job): bool {
                return $job->parameters()->get(Duration::class)?->seconds() === 99;
            }
        );
        
        $fakeQueue->queue(name: 'file')->assertNotPushed(BackupJobHandler::class);
    }

    public function testBackupActionStoresAuthenticatedUserIdInPayload()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: false),
        ]);

        $fakeQueue = $this->fakeQueue();
        $auth = $this->fakeAuth();
        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: 'backups/1/backup');

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => []],
        ])->times(1)->create();

        $user = $auth->getUserRepository()->create(['username' => 'tom']);
        $auth->authenticatedAs($user);

        $http->response()->assertStatus(302);

        $fakeQueue->queue(name: 'file')->assertPushed(
            BackupJobHandler::class,
            function (JobInterface $job): bool {
                return $job->getPayload()['user_id'] === 1;
            }
        );
    }

    public function testBackupActionFailsWhenBackupNotFound()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: false),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: 'backups/999/backup');

        $http->response()->assertStatus(404);
    }

    public function testBackupActionFailsWithoutPermission()
    {
        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: true),
        ]);

        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: 'backups/1/backup');

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => []],
        ])->times(1)->create();
        
        $http->response()->assertStatus(403);
    }

    public function testBackupActionFailsWhenUsingSyncQueue()
    {
        $this->onCreateApp(function(AppInterface $app) {
            // Force sync queue via runtime config
            $app->on(BackupRuntimeConfigInterface::class, function() {
                return new BackupRuntimeConfig(
                    queueName: 'sync',
                    jobTimeBudgetInSeconds: 50,
                );
            });
        });

        $this->fakeConfig()->with('backup.features', [
            new Backups(withAcl: false),
        ]);

        $fakeQueue = $this->fakeQueue();
        $http = $this->fakeHttp();
        $http->request(method: 'POST', uri: 'backups/1/backup');

        $this->getSeedFactory([
            'name' => 'Nightly Backup',
            'options' => ['artifacts' => []],
        ])->times(1)->create();

        // Trigger backup action
        $http->request(method: 'POST', uri: 'backups/1/backup');
        
        // Assert HTTP 500 (exception was thrown inside action)
        $http->response()->assertStatus(500);
        
        // Assert job was NOT queued
        $fakeQueue->queue(name: 'sync')->assertNotPushed(BackupJobHandler::class);
    }
}