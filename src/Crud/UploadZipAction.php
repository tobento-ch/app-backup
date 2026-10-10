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
use Tobento\App\Backup\Registry\Artifact\ArtifactRegistriesInterface;
use Tobento\App\Backup\Registry\Artifact\ZipArtifactRegistry;
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
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\Fields;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Input\Input;
use Tobento\App\FileStorage\FilesystemStorageFactory;
use Tobento\App\Http\Exception\HttpException;
use Tobento\Service\FileStorage\StorageInterface;
use Tobento\Service\Requester\RequesterInterface;
use Tobento\Service\Responser\ResponserInterface;
use Tobento\Service\Routing\RouterInterface;
use Tobento\Service\Upload\FileStorageWriter;
use Tobento\Service\Upload\FileStorageWriterInterface;
use function Tobento\App\Translation\trans;

final class UploadZipAction extends AbstractAction
{
    use Action\Traits\InteractsWithRequest;
    
    public function __construct(
        null|string|Closure $title = null,
    ) {
        $this->title = $title;
        $this->route('{name}.dynamic', function(EntityInterface $entity): array {
            return ['id' => $entity->id(), 'action' => 'upload-zip'];
        });
        $this->linkToAction('upload-zip');
    }
    
    public function name(): string
    {
        return 'upload-zip';
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
     * @param ActionProcessorInterface $actionProcessor
     * @param RequesterInterface $requester
     * @return ResponseInterface
     */
    public function handle(
        ActionProcessorInterface $actionProcessor,
        RequesterInterface $requester,
    ): ResponseInterface {

        if ($requester->method() === 'GET') {
            return $actionProcessor->call([$this, 'handleGet']);
        }

        if ($requester->method() === 'POST') {
            return $actionProcessor->call([$this, 'handlePost']);
        }

        throw new ActionNotFoundException(actionName: $this->name());
    }
    
    /**
     * Handle GET request action.
     *
     * @param ActionProcessorInterface $actionProcessor
     * @param RequesterInterface $requester
     * @param ResponserInterface $responser
     * @return ResponseInterface
     */
    public function handleGet(
        ActionProcessorInterface $actionProcessor,
        RequesterInterface $requester,
        ResponserInterface $responser,
    ): ResponseInterface {
        $controller = $this->controller();
        
        $actionProcessor->preprocessAction(action: $this);

        $this->setEntity(new Entity());
        
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
     *
     * @param ActionProcessorInterface $actionProcessor
     * @param RequesterInterface $requester
     * @param RouterInterface $router
     * @param ResponserInterface $responser
     * @return ResponseInterface
     */
    public function handlePost(
        ActionProcessorInterface $actionProcessor,
        RequesterInterface $requester,
        RouterInterface $router,
        ResponserInterface $responser,
    ): ResponseInterface {
        // Create fake Store action for validation
        $storeAction = new Action\Store();
        $storeAction->setController($this->controller());

        $actionProcessor->preprocessAction(action: $storeAction);

        $storeAction->setInput(
            $this->fetchInput(requester: $requester, action: $storeAction, fresh: true)
        );

        $fields = Fields::fromIterable($this->configureFields($storeAction));
        $storeAction->setFields($fields->creatable());

        $actionProcessor->processFields(action: $storeAction, entity: new Entity());
        
        return $responser->redirect(
            uri: $router->url('backups-restore.dynamic', [
                'id' => $storeAction->getInput()->get('file'),
                'action' => 'restore',
            ])
        );
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
        yield new Field\FileSource(
            name: 'file',
            label: trans('ZIP File'),
        )
            ->allowedExtensions('zip')
            ->required()
            ->fileStorageWriter(static function() use ($action): FileStorageWriterInterface {
                
                $container = $action->container();
                
                // Find first ZipArtifactRegistry
                $registries = $container->get(ArtifactRegistriesInterface::class);
                $zipRegistry = $registries
                    ->filter(fn($r) => $r instanceof ZipArtifactRegistry)
                    ->first();
                
                if (is_null($zipRegistry)) {
                    throw new HttpException(
                        statusCode: 421,
                        message: 'ZIP restore registry not available.',
                    );
                }

                // Create a storage instance pointing to the registry location
                $storageFactory = $container->get(FilesystemStorageFactory::class);
                $artifactStorage = $storageFactory->createStorage(name: 'backup', config: [
                    'location' => $zipRegistry->location(),
                    'storage_type' => 'private',
                ]);
                
                return new FileStorageWriter(
                    storage: $artifactStorage,
                    filenames: FileStorageWriter::ALNUM, // RENAME, ALNUM, KEEP
                    duplicates: FileStorageWriter::RENAME, // RENAME, OVERWRITE, DENY
                    folders: FileStorageWriter::ALNUM, // or KEEP
                    folderDepthLimit: 5,
                    writers: [],
                );
            });
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
            new Button\Button(label: $this->trans('Upload'), group: 'entity')
                ->name('restore')
                ->attr(name: 'name', value: 'next_action')
                ->attr(name: 'value', value: 'restore')
                ->attr(name: 'data-loading', value: 'true')
                ->ajaxAction()
                ->primary(),
        );
        
        return $this->applyButtonsConfig($this->buttons);
    }
}