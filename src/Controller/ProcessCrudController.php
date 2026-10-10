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
 
namespace Tobento\App\Backup\Controller;

use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\Registry\Action\ActionRegistriesInterface;
use Tobento\App\Backup\Repo\ProcessEntity;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Crud\AbstractCrudController;
use Tobento\App\Crud\Action;
use Tobento\App\Crud\Action\ActionInterface;
use Tobento\App\Crud\Action\ActionsInterface;
use Tobento\App\Crud\Button;
use Tobento\App\Crud\Button\ButtonInterface;
use Tobento\App\Crud\Entity\EntityInterface;
use Tobento\App\Http\Exception\ForbiddenException;
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\Fields;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Filter;
use Tobento\App\Crud\Filter\FiltersInterface;
use Tobento\App\Crud\Filter\FilterInterface;
use Tobento\App\User\AddressRepositoryInterface;
use Tobento\App\User\UserInterface;
use Tobento\Service\Requester\RequesterInterface;
use Tobento\Service\View\ViewInterface;
use function Tobento\App\Translation\trans;

class ProcessCrudController extends AbstractCrudController
{
    /**
     * Must be unique, lowercase and only of [a-z-] characters.
     */
    public const RESOURCE_NAME = 'backups-processes';
    
    /**
     * Create a new instance.
     *
     * @param ProcessRepositoryInterface $repository
     */
    public function __construct(
        ProcessRepositoryInterface $repository,
    ) {
        $this->repository = $repository;
    }
    
    /**
     * Returns the configured fields.
     *
     * @param ActionInterface $action
     * @return iterable<FieldInterface>|FieldsInterface
     */
    protected function configureFields(ActionInterface $action): iterable|FieldsInterface
    {
        if  (!in_array($action->name(), ['index', 'show'])) {
            return [];
        }
            
        yield new Field\PrimaryId(name: 'id')
            ->group(trans('Process'));

        yield new Field\Select(name: 'status', label: trans('Status'))
            ->group(trans('Process'))
            ->options([
                'processing' => trans('processing'),
                'completed' => trans('completed'),
                'failed' => trans('failed'),
            ])
            ->formatValue(new Field\Formatter\Badge(classes: [
                'processing' => 'text-highlight',
                'completed' => 'text-success',
                'failed' => 'text-error',
            ]));
        
        yield new Field\Text(name: 'name', label: trans('Name'))
            ->group(trans('Process'));
        
        yield new Field\Text(name: 'type', label: trans('Type'))
            ->group(trans('Process'))
            ->formatValue(
                formatter: new Field\Formatter\Badge(
                    classes: ['backup' => 'text-black', 'restore' => 'text-black'],
                    fallbackClass: 'text-black',
                )
            );

        yield new Field\Text(name: 'created_at', label: trans('Created At'))
            ->group(trans('Process'))
            ->formatValue(new Field\Formatter\Date(format: 'EEEE, dd. MMMM yyyy, HH:mm'));

        yield new Field\Select(name: 'user_id', label: trans('Created by User'))
            ->group(trans('Process'))
            ->options(
                fn (AddressRepositoryInterface $repo) => 
                $repo->findColumn('name', 'user_id', ['key' => 'primary'])+['0' => '-']
            )
            ->emptyOption(value: 'none', label: '---')
            ->optionalText('')
            ->disabled();
        
        yield new Field\Text(name: 'runtime_seconds', label: trans('Runtime In Seconds'))
            ->group(trans('Performance'));
        
        yield new Field\Text(name: 'memory_peak_bytes', label: trans('Peak Memory (Bytes)'))
            ->group(trans('Performance'));
        
        if ($action->name() === 'show') {
            yield $this->processedActionsField($action);
        }
    }

    protected function processedActionsField(ActionInterface $action): FieldInterface
    {
        $container = $action->container();
        $actionRegistries = $container->get(ActionRegistriesInterface::class);
        $entity = $action->entity();
        
        return new Field\Value(name: 'processed_actions', label: trans('Processed Actions'))
            ->group(trans('Process'))
            ->formatValue(
                formatter: function (mixed $value) use ($actionRegistries, $entity): string {
                    if (!is_array($value)) {
                        return '-';
                    }
                    
                    $names = $actionRegistries->names();
                    $executed = $entity->get('actions', []);

                    // Collect ids that were NullAction (no-op) placeholders, keyed by
                    // their own declared id, not by array position.
                    $nullActionIds = [];
                    foreach ($executed as $item) {
                        if (($item['class'] ?? '') === NullAction::class) {
                            $id = $item['data']['id'] ?? null;
                            if ($id !== null) {
                                $nullActionIds[$id] = true;
                            }
                        }
                    }

                    $list = [];
                    foreach ($value as $id) {
                        if (isset($nullActionIds[$id])) {
                            continue;
                        }
                        if (array_key_exists($id, $names)) {
                            $list[] = $names[$id];
                        }
                    }

                    return implode(', ', array_unique($list));
                },
                action: 'show',
            )
            ->showable(true);
    }

    /**
     * Returns the configured actions.
     *
     * @return iterable<ActionInterface>|ActionsInterface
     */
    protected function configureActions(): iterable|ActionsInterface
    {
        yield new Action\Index(title: trans('Backup/Restore Processes'))
            ->addButton(new Button\Link(label: trans('Edit Backup'), group: 'entity')
                ->name('type.record')
                ->linkToRoute('backups.edit', function(EntityInterface $entity): array {
                    return ['id' => $entity->get('type_id')];
                })
                ->raw()
            )
            ->modifyButton('type.record', function(ButtonInterface $button, EntityInterface $entity): void {
                if ($entity->get('type') === 'restore') {
                    $button
                        ->label(trans('Edit Restore'))
                        ->linkToRoute('backups-restore.dynamic', function(EntityInterface $entity): array {
                            return ['id' => $entity->get('type_id'), 'action' => 'restore'];
                        });
                }
            })
            ->removeButton('copy')
            ->groupButtons(
                except: ['show'],
                button: new Button\Dropdown(label: '', icon: 'dots', group: 'entity')
                    ->name('more')
                    ->raw(),
            );

        yield new Action\Show(title: trans('Process'));
        
        yield new Action\ShowJson();
        
        yield new Action\Delete();

        yield new Action\BulkDelete();
    }
    
    /**
     * Returns the configured filters.
     *
     * @param ActionInterface $action
     * @return iterable<FilterInterface>|FiltersInterface
     */
    protected function configureFilters(ActionInterface $action): iterable|FiltersInterface
    {
        return [
            ...new Filter\Fields()
                ->fields($action->fields())
                ->except('user_id', 'type', 'created_at', 'runtime_seconds', 'memory_peak_bytes')
                ->toFilters(),
            
            new Filter\Select(name: 'type', field: 'type')
                ->options($this->repository()->distinctValues('type'))
                ->group('field'),
            
            new Filter\FieldsSortOrder()
                ->addDefault(name: 'created_at', value: 'desc'),
            
            new Filter\ModalButton()->group('header'),
            
            new Filter\Group(name: 'group-columns')->group('modal')->label(trans('Columns'))->open(false),
            
            new Filter\Columns()
                ->group('group-columns')
                ->default('name', 'status', 'type', 'created_at', 'actions'),
            
            new Filter\Group(name: 'group-pagination')->group('modal')->label(trans('Pagination'))->open(false),
            
            new Filter\PaginationItemsPerPage()
                ->group('group-pagination')
                ->open(false),
            
            new Filter\Pagination()->group('footer'),
        ];
    }
    
    /**
     * Determines if action is processable.
     *
     * @param ActionInterface $action
     * @return void
     * @throws \Throwable
     */
    public function isActionProcessable(ActionInterface $action): void
    {
        if (
            in_array($action->name(), ['delete', 'bulk-delete'])
            && in_array($action->entity()->get('status'), ['processing'])
        ) {
            throw new ForbiddenException(trans('You cannot delete resources while they are processing.'));
        }
        
        parent::isActionProcessable(action: $action);
    }
    
    /**
     * @return ProcessRepositoryInterface
     */
    public function repository(): ProcessRepositoryInterface
    {
        return $this->repository;
    }
}