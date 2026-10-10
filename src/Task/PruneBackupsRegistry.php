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

use Psr\Clock\ClockInterface;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Backup\Repo\RestoreRepositoryInterface;
use Tobento\App\Crud\Action\ActionInterface;
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Task\Registry\Task;
use Tobento\Service\Schedule\ParameterInterface;
use Tobento\Service\Schedule\Task\AbstractTask;
use Tobento\Service\Schedule\Task\CallableTask;
use function Tobento\App\Translation\trans;

class PruneBackupsRegistry extends Task
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
        yield new Field\Text(name: 'data.backup.days', label: trans('Retention (days)'))
            ->group(trans('Options'))
            ->type('number')
            ->validate('required|minNum:1|maxNum:365')
            ->infoText(trans('Delete backups older than the specified number of days.'));

        yield new Field\Radios(name: 'data.backup.with_processes', label: trans('Include Backup Processes'))
            ->group(trans('Options'))
            ->options(['0' => trans('No'), '1' => trans('Yes')])
            ->selected(value: '1', action: 'create|edit')
            ->displayInline()
            ->infoText(trans('Whether to also prune backup processes older than the specified number of days.'));
        
        foreach (parent::configureFields($action) as $field) {
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
                RestoreRepositoryInterface $restoreRepository,
                ProcessRepositoryInterface $processRepository,
                ClockInterface $clock,
                int $days,
                bool $withProcesses,
            ): string {
                // we need to do it this as some restore repos may not support delete method!
                $foundEntites = $restoreRepository->findAll(where: [
                    'created_at' => ['<' => $clock->now()->modify(sprintf('-%d days', $days))->getTimestamp()],
                ]);
                
                $deleteCount = 0;
                
                foreach($foundEntites as $entity) {
                    $restoreRepository->deleteById(id: $entity->id());
                    $deleteCount++;
                }
                
                $processCount = 0;
                
                if ($withProcesses) {
                    $deletedProcesses = $processRepository->delete(where: [
                        'type' => 'backup',
                        'created_at' => ['<' => $clock->now()->modify(sprintf('-%d days', $days))->getTimestamp()],
                    ]);
                    
                    $processCount = is_array($deletedProcesses)
                        ? count($deletedProcesses)
                        : iterator_count($deletedProcesses);
                }

                return sprintf(
                    '%d backups and %d processes older than %d days have been pruned.',
                    $deleteCount,
                    $processCount,
                    $days,
                );
            },
            params: [
                'days' => (int)$entity->data('backup.days'),
                'withProcesses' => $entity->data('backup.with_processes') === '1',
            ],
        );
    }
}