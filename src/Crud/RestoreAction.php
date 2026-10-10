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
 
namespace Tobento\App\Backup\Crud;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Tobento\App\Backup\Action\ActionsFactoryInterface;
use Tobento\App\Backup\Hook\HooksInterface;
use Tobento\App\Backup\Manifest\ManifestFactoryInterface;
use Tobento\App\Backup\Queue\RestoreJobHandler;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Crud\Action\ActionInterface;
use Tobento\App\Crud\Action;
use Tobento\App\Crud\ActionProcessorInterface;
use Tobento\App\Crud\Action\AbstractAction;
use Tobento\App\Crud\Button;
use Tobento\App\Crud\Button\Buttons;
use Tobento\App\Crud\Button\ButtonsInterface;
use Tobento\App\Crud\Entity\Entity;
use Tobento\App\Crud\Entity\EntityInterface;
use Tobento\App\Crud\Exception\ActionNotFoundException;
use Tobento\App\Crud\Exception\EntityNotFoundException;
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\Fields;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Input\Input;
use Tobento\App\User\AddressRepositoryInterface;
use Tobento\App\User\UserInterface;
use Tobento\Service\Queue\Job;
use Tobento\Service\Queue\Parameter\DisallowQueue;
use Tobento\Service\Queue\QueueInterface;
use Tobento\Service\Queue\SyncQueue;
use Tobento\Service\Requester\RequesterInterface;
use Tobento\Service\Responser\ResponserInterface;
use Tobento\Service\Routing\RouterInterface;
use Tobento\Service\Translation\TranslatorInterface;
use function Tobento\App\Translation\trans;

final class RestoreAction extends AbstractAction
{
    use Action\Traits\InteractsWithRequest;
    
    public function __construct(
        null|string|Closure $title = null,
    ) {
        $this->title = $title;
        $this->route('{name}.dynamic', function(EntityInterface $entity): array {
            return ['id' => $entity->id(), 'action' => 'restore'];
        });
        $this->linkToAction('restore');
    }
    
    public function name(): string
    {
        return 'restore';
    }
    
    /**
     * Returns the handler processing the action.
     *
     * @return callable(mixed...): \Psr\Http\Message\ResponseInterface
     */
    public function getHandler(): callable
    {
        return [$this, 'handle'];
    }
    
    /**
     * Handle action.
     *
     * @param int|string $id
     * @param ActionProcessorInterface $actionProcessor
     * @param RequesterInterface $requester
     * @return ResponseInterface
     */
    public function handle(
        int|string $id,
        ActionProcessorInterface $actionProcessor,
        RequesterInterface $requester,
    ): ResponseInterface {

        if ($requester->method() === 'GET') {
            return $actionProcessor->call([$this, 'handleGet'], ['id' => $id]);
        }

        if ($requester->method() === 'POST') {
            return $actionProcessor->call([$this, 'handlePost'], ['id' => $id]);
        }

        throw new ActionNotFoundException(actionName: $this->name());
    }
    
    /**
     * Handle GET request action.
     *
     * @param int|string $id
     * @param ActionProcessorInterface $actionProcessor
     * @param RequesterInterface $requester
     * @param ResponserInterface $responser
     * @return ResponseInterface
     */
    public function handleGet(
        int|string $id,
        ActionProcessorInterface $actionProcessor,
        RequesterInterface $requester,
        ResponserInterface $responser,
    ): ResponseInterface {
        $controller = $this->controller();
        
        $actionProcessor->preprocessAction(action: $this);
        
        // Handle entity:
        $entity = $controller->repository()->findById($id);
        
        if ($entity === null) {
            throw new EntityNotFoundException($id, $this);
        }

        $this->setEntity($controller->createEntityFromObject($entity));
        
        // Handle input:
        $this->setInput($this->fetchInput(requester: $requester, action: $this, fresh: true));
        
        // Set the configured fields:
        $fields = Fields::fromIterable($this->configureFields($this));
        $this->setFields($fields->creatable());
        
        // Process action:
        $this->fieldActionType('create');
        $controller->isActionProcessable($this);
        $actionProcessor->processAction(action: $this);
        
        $this->view('crud/create');
        
        return $responser->render(
            view: $this->getView(),
            data: [
                'action' => $this->setFields($this->fields()->parent(null)),
            ],
        );
    }
    
    /**
     * Handle POST request action.
     */
    public function handlePost(
        int|string $id,
        ActionProcessorInterface $actionProcessor,
        RequesterInterface $requester,
        QueueInterface $queue,
        BackupRuntimeConfigInterface $runtimeConfig,
        RouterInterface $router,
        ResponserInterface $responser,
    ): ResponseInterface {
        // Load restore entity (same as GET)
        $restoreEntity = $this->controller()->repository()->findById($id);

        if ($restoreEntity === null) {
            throw new EntityNotFoundException($id, $this);
        }

        // Create fake Store action for validation
        $storeAction = new Action\Store();
        $storeAction->setController($this->controller());

        // IMPORTANT: set entity so configureFields() works
        $storeAction->setEntity(
            $this->controller()->createEntityFromObject($restoreEntity)
        );

        $actionProcessor->preprocessAction(action: $storeAction);

        $storeAction->setInput(
            $this->fetchInput(requester: $requester, action: $storeAction, fresh: true)
        );

        $fields = Fields::fromIterable($this->configureFields($storeAction));
        $storeAction->setFields($fields->creatable());

        $actionProcessor->processFields(action: $storeAction, entity: new Entity());

        $input = $storeAction->getInput();
        $user = $requester->request()->getAttribute(UserInterface::class);
        
        // Queue restore job
        $job = new Job(
            name: RestoreJobHandler::class,
            payload: [
                'restore_id' => $id,
                'user_id' => $user instanceof UserInterface ? $user->id() : null,
                'input' => $input->all(),
            ],
        );

        if ($runtimeConfig->queueName()) {
            $job->queue($runtimeConfig->queueName());
        }
        
        $job->duration(seconds: $runtimeConfig->jobTimeBudgetInSeconds());
        
        $job->retry(3)
            ->priority(-10);
        
        $job->parameter(new DisallowQueue(
            names: ['sync'],
            classNames: [SyncQueue::class],
            reason: 'Restores cannot run on the sync queue.'
        ));
        
        $queue->push($job);

        // Respond
        $responser->messages()->add(
            level: 'success',
            message: 'Restore has started and will continue in the background.',
        );

        return $responser->redirect(uri: $router->url('backups-restore.index'));
    }
    
    /**
     * Returns the configured fields.
     *
     * @param ActionInterface $action
     * @return iterable<FieldInterface>|FieldsInterface
     * @psalm-suppress PossiblyUnusedParam
     */
    protected function configureFields(ActionInterface $action): iterable|FieldsInterface
    {
        yield new Field\PrimaryId(name: 'id');
        
        yield new Field\Text(name: 'name', label: trans('Name'))
            ->group(trans('Manifest'))
            ->optionalText(text: '', action: 'restore')
            ->disabled();
        
        yield new Field\Select(name: 'user_id', label: trans('Created by User'))
            ->group(trans('Manifest'))
            ->options(
                fn (AddressRepositoryInterface $repo) => 
                $repo->findColumn('name', 'user_id', ['key' => 'primary'])+['0' => '-']
            )
            ->emptyOption(value: 'none', label: '---')
            ->optionalText(text: '', action: 'restore')
            ->disabled();
        
        yield new Field\Text(name: 'domain', label: trans('Domain'))
            ->group(trans('Manifest'))
            ->optionalText(text: '', action: 'restore')
            ->disabled();
        
        yield new Field\Text(name: 'created_at', label: trans('Created At'))
            ->group(trans('Manifest'))
            ->formatValue(
                formatter: new Field\Formatter\Date(format: 'EEEE, dd. MMMM yyyy, HH:mm'),
                action: 'restore',
            )
            ->optionalText(text: '', action: 'restore')
            ->disabled();
        
        $container = $action->container();
        $restoreEntity = $action->controller()->repository()->findById($action->entity()->id());
        
        $manifestFactory = $container->get(ManifestFactoryInterface::class);
        $manifest = $manifestFactory->createManifestFromRestoreEntity($restoreEntity);
        $actionsFactory = $container->get(ActionsFactoryInterface::class);
        $actions  = $actionsFactory->createActionsFromArray($manifest->actions());
        
        foreach($actions as $backupAction) {
            $actionFields = $backupAction->configureRestoreFields($action);
            
            foreach($actionFields as $actionField) {
                yield $actionField;
            }
        }
        
        $hooks = $container->get(HooksInterface::class)->restore();
        
        foreach ($hooks->groupNames() as $groupKey => $group) {

            $options = [];
            $selected = [];

            foreach ($hooks->group($group) as $hookKey => $hook) {
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
                ->selected(value: $selected, action: 'restore');
        }
    }
    
    /**
     * Returns the configured buttons.
     *
     * @return ButtonsInterface
     */
    public function buttons(): ButtonsInterface
    {
        if ($this->buttons instanceof ButtonsInterface) {
            return $this->buttons;
        }
        
        $this->buttons = new Buttons(
            new Button\Link(label: $this->trans('Cancel'), group: 'entity')
                ->name('cancel')
                ->linkToAction('index'),
            new Button\Button(label: $this->trans('Restore'), group: 'entity')
                ->name('restore')
                ->attr(name: 'name', value: 'next_action')
                ->attr(name: 'value', value: 'restore')
                ->askConfirmation($this->trans('Do you really want to restore this backup?'))
                ->primary(),
        );
        
        return $this->applyButtonsConfig($this->buttons);
    }
}