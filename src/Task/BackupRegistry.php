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
 
namespace Tobento\App\Backup\Task;

use Tobento\App\Backup\Queue\BackupJobHandler;
use Tobento\App\Backup\Repo\BackupRepositoryInterface;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Crud\Action\ActionInterface;
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Task\Registry\Task;
use Tobento\Service\Queue\Job;
use Tobento\Service\Queue\QueueInterface;
use Tobento\Service\Schedule\ParameterInterface;
use Tobento\Service\Schedule\Task\AbstractTask;
use Tobento\Service\Schedule\Task\CallableTask;
use function Tobento\App\Translation\trans;

class BackupRegistry extends Task
{
    /**
     * Create a new instance.
     *
     * @param string $name
     * @param array<array-key, ParameterInterface> $parameters
     * @param array<array-key, string> $supportedAppIds
     */
    public function __construct(
        protected string $name,
        protected array $parameters = [],
        protected array $supportedAppIds = ['root'],
    ) {}
    
    /**
     * Returns the configured fields.
     *
     * @param ActionInterface $action
     * @return iterable<FieldInterface>|FieldsInterface
     */
    public function configureFields(ActionInterface $action): iterable|FieldsInterface
    {
        yield new Field\Select(name: 'data.backup.id', label: trans('Backup'))
            ->group(trans('Options'))
            ->options(fn(BackupRepositoryInterface $repo): array => $repo->findColumn(column: 'name', key: 'id'))
            ->validate('required')
            ->infoText(trans('Choose the backup you want to schedule.'));
        
        foreach(parent::configureFields($action) as $field) {
            yield $field;
        }
    }
    
    /**
     * Returns the task.
     *
     * @return AbstractTask
     */
    protected function getTask(): AbstractTask
    {
        $entity = $this->taskEntity();

        return new CallableTask(
            callable: static function (
                BackupRepositoryInterface $backupRepository,
                BackupRuntimeConfigInterface $runtimeConfig,
                QueueInterface $queue,
                int $id,
            ): string {

                $backupEntity = $backupRepository->findById($id);

                if (is_null($backupEntity)) {
                    return 'Backup not found.';
                }

                // Queue backup job
                $job = new Job(
                    name: BackupJobHandler::class,
                    payload: [
                        'user_id' => null,
                        'backup_id' => $backupEntity->id(),
                    ],
                );

                if ($runtimeConfig->queueName()) {
                    $job->queue($runtimeConfig->queueName());
                }

                $job->duration(seconds: $runtimeConfig->jobTimeBudgetInSeconds());
                $job->retry(3);
                $job->priority(-50);

                $queue->push($job);

                return sprintf(
                    'Backup "%s" has started and will continue in the background.',
                    $backupEntity->name(),
                );
            },
            params: ['id' => (int)$entity->data('backup.id')],
        );
    }
}