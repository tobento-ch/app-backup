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

use Tobento\App\Backup\Crud\DownloadZipAction;
use Tobento\App\Backup\Crud\RestoreAction;
use Tobento\App\Backup\Crud\UploadZipAction;
use Tobento\App\Backup\Hook\HooksInterface;
use Tobento\App\Backup\Registry\Action\ActionRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\ZipArtifactRegistry;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Repo\RestoreRepositoryInterface;
use Tobento\App\Crud\AbstractCrudController;
use Tobento\App\Crud\Action;
use Tobento\App\Crud\Action\ActionInterface;
use Tobento\App\Crud\Action\ActionsInterface;
use Tobento\App\Crud\Button;
use Tobento\App\Crud\Button\ButtonInterface;
use Tobento\App\Crud\Entity\Entity;
use Tobento\App\Crud\Entity\EntityInterface;
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\Fields;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Filter;
use Tobento\App\Crud\Filter\FiltersInterface;
use Tobento\App\Crud\Filter\FilterInterface;
use Tobento\App\Crud\Html\Message;
use Tobento\App\Crud\Input\InputInterface;
use Tobento\App\Http\Exception\ForbiddenException;
use Tobento\App\Http\Exception\HttpException;
use Tobento\App\User\AddressRepositoryInterface;
use Tobento\Service\View\ViewInterface;
use function Tobento\App\Translation\trans;

class RestoreCrudController extends AbstractCrudController
{
    /**
     * Must be unique, lowercase and only of [a-z-] characters.
     */
    public const RESOURCE_NAME = 'backups-restore';
    
    /**
     * Create a new instance.
     *
     * @param RestoreRepositoryInterface $repository
     * @param ArtifactRegistriesInterface $artifactRegistries
     */
    public function __construct(
        RestoreRepositoryInterface $repository,
        protected ArtifactRegistriesInterface $artifactRegistries,
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
            
            yield new Field\PrimaryId(name: 'id', label: trans('ID'));
            
            yield new Field\Text(name: 'name', label: trans('Name'));
            
            yield new Field\Text(name: 'type', label: trans('Type'));
            
            yield new Field\Text(name: 'created_at', label: trans('Created At'))
                ->formatValue(new Field\Formatter\Date(format: 'EEEE, dd. MMMM yyyy, HH:mm'));
            
            yield new Field\Select(name: 'user_id', label: trans('Created by User'))
                ->options(
                    fn (AddressRepositoryInterface $repo) => 
                    $repo->findColumn('name', 'user_id', ['key' => 'primary'])+['0' => '-']
                )
                ->emptyOption(value: 'none', label: '---')
                ->optionalText('')
                ->disabled();
            
            yield new Field\Text(name: 'domain', label: trans('Domain'));

            return;
        }
        
        return [];
    }
    
    /**
     * Returns the configured actions.
     *
     * @return iterable<ActionInterface>|ActionsInterface
     */
    protected function configureActions(): iterable|ActionsInterface
    {
        yield new Action\Index(title: trans('Restore Backups'))
            ->addButton(new Button\Link(label: trans('Upload ZIP'), group: 'global')
                ->name('upload-zip')
                ->linkToAction('upload-zip')
            )
            ->addButton(new Button\Link(label: trans('Restore'), group: 'entity')
                ->name('restore')
                ->linkToAction('restore')
                ->raw()
            )
            ->addButton(new Button\Link(label: trans('Download'), group: 'entity')
                ->name('download-zip')
                ->linkToAction('download-zip')
                ->raw()
            )
            ->removeButton('create', 'edit', 'copy')
            ->groupButtons(
                except: ['restore'],
                button: new Button\Dropdown(label: '', icon: 'dots', group: 'entity')
                    ->name('more')
                    ->raw(),
            )
            ->displayButtonIf('download-zip', function(EntityInterface $entity): bool {
                return strtolower($entity->get('type', '')) === 'zip';
            });
        
        yield new RestoreAction(title: trans('Restore'));
        
        yield new DownloadZipAction(title: trans('Download'));

        if ($this->artifactRegistries->filter(fn($r) => $r instanceof ZipArtifactRegistry)->first()) {
            yield new UploadZipAction(title: trans('Upload ZIP'));
        }

        yield new Action\Delete()
            ->deleteMessage(trans('Backup scheduled for deletion.'));

        yield new Action\BulkDelete()
            ->deleteMessage(fn(int $count): string => trans(':count backup(s) scheduled for deletion.', [':count' => $count]));
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
                ->except('type', 'domain')
                ->toFilters(),
            
            new Filter\Select(name: 'type', field: 'type')
                ->options($this->repository()->distinctValues('type'))
                ->group('field'),
            
            new Filter\Select(name: 'domain', field: 'domain')
                ->options($this->repository()->distinctValues('domain'))
                ->group('field'),
            
            new Filter\FieldsSortOrder()
                ->addDefault(name: 'created_at', value: 'desc'),
            
            new Filter\ModalButton()->group('header'),
            
            new Filter\Group(name: 'group-columns')->group('modal')->label(trans('Columns'))->open(false),
            
            new Filter\Columns()
                ->group('group-columns')
                ->default('id', 'name', 'type', 'created_at', 'actions'),
            
            new Filter\Group(name: 'group-pagination')->group('modal')->label(trans('Pagination'))->open(false),
            
            new Filter\PaginationItemsPerPage()
                ->group('group-pagination')
                ->open(false),
            
            new Filter\Pagination()->group('footer'),
        ];
    }
    
    /**
     * @return RestoreRepositoryInterface
     */
    public function repository(): RestoreRepositoryInterface
    {
        return $this->repository;
    }
}