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

use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Backup\Hook\HooksInterface;
use Tobento\App\Backup\Registry\Action\ActionRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Repo\BackupRepositoryInterface;
use Tobento\App\Crud\AbstractCrudController;
use Tobento\App\Crud\Action;
use Tobento\App\Crud\Action\ActionInterface;
use Tobento\App\Crud\Action\ActionsInterface;
use Tobento\App\Crud\Button;
use Tobento\App\Crud\Button\ButtonInterface;
use Tobento\App\Crud\Entity\EntityInterface;
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\Fields;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Filter;
use Tobento\App\Crud\Filter\FiltersInterface;
use Tobento\App\Crud\Filter\FilterInterface;
use Tobento\App\Crud\Html;
use Tobento\App\User\AddressRepositoryInterface;
use Tobento\App\User\UserInterface;
use Tobento\Service\Requester\RequesterInterface;
use function Tobento\App\Translation\trans;

class BackupCrudController extends AbstractCrudController
{
    /**
     * Must be unique, lowercase and only of [a-z-] characters.
     */
    public const RESOURCE_NAME = 'backups';
    
    /**
     * Create a new instance.
     *
     * @param BackupRepositoryInterface $repository
     * @param ArtifactRegistriesInterface $artifactRegistries
     * @param ActionRegistriesInterface $actionRegistries
     * @param HooksInterface $hooks
     * @param BackupRuntimeConfigInterface $runtimeConfig
     */
    public function __construct(
        BackupRepositoryInterface $repository,
        protected ArtifactRegistriesInterface $artifactRegistries,
        protected ActionRegistriesInterface $actionRegistries,
        protected HooksInterface $hooks,
        protected BackupRuntimeConfigInterface $runtimeConfig,
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
        if  ($action->name() === 'index') {
            
            yield new Field\PrimaryId(name: 'id');
            
            yield new Field\Text(name: 'name', label: trans('Name'));
            
            yield new Field\Select(name: 'user_id', label: trans('Last Edited by User'))
                ->options(
                    fn (AddressRepositoryInterface $repo) => 
                    $repo->findColumn('name', 'user_id', ['key' => 'primary'])+['0' => '-']
                )
                ->emptyOption(value: 'none', label: '---')
                ->optionalText('')
                ->disabled();

            yield new Field\Text(name: 'created_at', label: trans('Created At'))
                ->group(trans('Process'))
                ->formatValue(new Field\Formatter\Date(format: 'EEEE, dd. MMMM yyyy, HH:mm'));
            
            return;
        }
        
        yield new Field\PrimaryId(name: 'id')
            ->group(trans('Backup'));
        
        yield new Field\Text(name: 'name', label: trans('Name'))
            ->group(trans('Backup'))
            ->validate('required|string|htmlclean|maxLen:200');
        
        if ($action->name() === 'store' || $action->name() === 'update') {
            $requester = $action->container()->get(RequesterInterface::class);
            $user = $requester->request()->getAttribute(UserInterface::class);
            $userId = $user instanceof UserInterface ? $user->id() : null;

            yield new Field\Value(name: 'user_id')
                ->group(trans('Backup'))
                ->value((string)$userId);
        }
        
        yield new Field\Checkboxes(name: 'options.artifacts', label: trans('Storage Locations'))
            ->group(trans('Backup'))
            ->options($this->artifactRegistries->names())
            ->validate('required|minItems:1');
        
        yield new Field\Radios(name: 'meta.encrypt', label: trans('Encrypt Files'))
            ->group(trans('Backup'))
            ->options(['0' => trans('No'), '1' => trans('Yes')])
            ->selected(value: '0', action: 'create|edit')
            ->displayInline()
            ->infoText(new Html\Message(
                text: trans('Backup will fail when a file cannot be encrypted within the allowed processing window.'),
                warning: true,
                attributes: ['class' => 'mt-s mb-m'],
            ));
        
        yield new Field\Value(name: 'meta.encrypter')
            ->group(trans('Backup'))
            ->value($this->runtimeConfig->encrypter());
        
        foreach($this->actionRegistries as $actionRegistry) {
            $actionRegistryFields = $actionRegistry->configureFields($action);
            
            foreach($actionRegistryFields as $actionRegistryField) {
                yield $actionRegistryField;
            }
        }
        
        if (in_array($action->name(), ['create', 'store', 'edit', 'update'])) {
            $backupHooks = $this->hooks->backup();
            
            foreach ($backupHooks->groupNames() as $groupKey => $group) {

                $options = [];
                $selected = [];

                foreach ($backupHooks->group($group) as $hookKey => $hook) {
                    $options[$hookKey] = $hook->name();

                    if ($hook->defaultSelected()) {
                        $selected[] = $hookKey;
                    }
                }

                yield new Field\Checkboxes(
                    name: 'options.hooks.'.$groupKey,
                    label: $group
                )
                    ->group(trans('Hooks'))
                    ->options($options)
                    ->selected(value: $selected, action: 'create|edit');
            }
        }
    }
    
    /**
     * Returns the configured actions.
     *
     * @return iterable<ActionInterface>|ActionsInterface
     */
    protected function configureActions(): iterable|ActionsInterface
    {
        yield new Action\Index(title: trans('Backups'))
            ->addButton(new Button\Form(label: trans('Backup'), group: 'entity')
                ->name('backup')
                ->linkToRoute('backups.backup', function(EntityInterface $entity): array {
                    return ['id' => $entity->id()];
                })
                ->raw()
                ->ajaxAction()
            )
            ->removeButton('copy')
            ->groupButtons(
                except: ['edit', 'backup'],
                button: new Button\Dropdown(label: '', icon: 'dots', group: 'entity')
                    ->name('more')
                    ->raw(),
            );

        yield new Action\Create(title: trans('New Backup'))
            ->removeButton('copy', 'new');

        yield new Action\Store();

        yield new Action\Edit(title: trans('Edit Backup'))
            ->removeButton('copy', 'new');

        yield new Action\Update();

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
                ->except('user_id', 'created_at')
                ->toFilters(),
            
            new Filter\FieldsSortOrder()
                ->addDefault(name: 'created_at', value: 'desc'),
            
            new Filter\ModalButton()->group('header'),
            
            new Filter\Group(name: 'group-columns')->group('modal')->label(trans('Columns'))->open(false),
            
            new Filter\Columns()
                ->group('group-columns')
                ->default('name', 'created_at', 'actions'),
            
            new Filter\Group(name: 'group-pagination')->group('modal')->label(trans('Pagination'))->open(false),
            
            new Filter\PaginationItemsPerPage()
                ->group('group-pagination')
                ->open(false),
            
            new Filter\Pagination()->group('footer'),
        ];
    }
}