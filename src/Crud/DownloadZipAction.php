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
use Tobento\App\Crud\Action\ActionInterface;
use Tobento\App\Crud\Action\AbstractAction;
use Tobento\App\Crud\Entity\EntityInterface;
use Tobento\App\Crud\Exception\ActionNotFoundException;
use Tobento\App\Crud\Exception\EntityNotFoundException;
use Tobento\App\Http\Exception\HttpException;
use Tobento\Service\FileStorage\FileInterface;
use Tobento\Service\Requester\RequesterInterface;
use Tobento\Service\Responser\FileResponser;

final class DownloadZipAction extends AbstractAction
{
    public function __construct(
        null|string|Closure $title = null,
    ) {
        $this->title = $title;
        $this->route('{name}.dynamic', function(EntityInterface $entity): array {
            return ['id' => $entity->id(), 'action' => 'download-zip'];
        });
        $this->linkToAction('upload-zip');
    }
    
    public function name(): string
    {
        return 'download-zip';
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
     * @param RequesterInterface $requester
     * @param FileResponser $fileResponser
     * @return ResponseInterface
     */
    public function handle(
        int|string $id,
        RequesterInterface $requester,
        FileResponser $fileResponser,
    ): ResponseInterface {
        
        if ($requester->method() !== 'GET') {
            throw new ActionNotFoundException(actionName: $this->name());
        }

        $controller = $this->controller();
        
        $entity = $controller->repository()->findById($id);
        
        if ($entity === null) {
            throw new EntityNotFoundException($id, $this);
        }
        
        if (! $entity->raw() instanceof FileInterface) {
            throw new HttpException(
                statusCode: 422,
                message: 'Backup artifact is not a file.',
            );
        }

        if ($entity->raw()->mimeType() !== 'application/zip') {
            throw new HttpException(
                statusCode: 422,
                message: 'Backup artifact is not a ZIP file.',
            );
        }
        
        // Download ZIP
        return $fileResponser->download(
            file: $entity->raw()->stream(),
            name: $entity->raw()->name(),
            contentType: 'application/zip',
        );
    }
}