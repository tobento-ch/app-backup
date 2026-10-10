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
 
namespace Tobento\App\Backup\Boot;

use Psr\Http\Message\ResponseInterface;
use Tobento\App\Http\Boot\ErrorHandler;
use Tobento\Service\Requester\RequesterInterface;
use Tobento\App\Backup\Exception\BackupException;
use Throwable;

class HttpErrorHandler extends ErrorHandler
{
    public const INFO = [
        'boot' => [
            'Import/Export Error Handler',
        ],
    ];

    protected const HANDLER_PRIORITY = 3000;

    public function handleThrowable(Throwable $t): Throwable|ResponseInterface
    {
        $requester = $this->app->get(RequesterInterface::class);
        
        // General exception
        if ($t instanceof BackupException) {
            return $requester->wantsJson()
                ? $this->renderJson(code: 400, message: $t->getMessage())
                : $this->renderView(code: 400, message: $t->getMessage());
        }

        return $t;
    }
}