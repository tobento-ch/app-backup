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

namespace Tobento\App\Backup\Hook;

use Psr\Container\ContainerInterface;
use Throwable;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Process\ProcessInterface;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Logging\LoggerTrait;
use Tobento\Service\Repository\RepositoryDeleteException;
use function Tobento\App\Translation\trans;

final class DeleteFinishedProcess implements HookInterface
{
    /**
     * Create a new instance.
     *
     * @param string $name The display name of the hook.
     * @param string $group The hook group shown in the UI.
     * @param bool $defaultSelected Whether the hook is selected by default.
     */
    public function __construct(
        private string $name,
        private string $group = 'Maintenance',
        private bool $defaultSelected = false,
    ) {}

    /**
     * Unique identifier.
     *
     * @return string
     */
    public function id(): string
    {
        return 'delete.finished.process';
    }
    
    /**
     * Returns the hook name.
     *
     * @return string
     */
    public function name(): string
    {
        return trans($this->name);
    }
    
    /**
     * Returns the hook group.
     *
     * @return string
     */
    public function group(): string
    {
        return trans($this->group);
    }
    
    /**
     * Whether the hook should be selected by default in the UI.
     *
     * @return bool
     */
    public function defaultSelected(): bool
    {
        return $this->defaultSelected;
    }
    
    /**
     * Whether this hook applies to backup processes.
     *
     * @return bool
     */
    public function supportsBackup(): bool
    {
        return true;
    }

    /**
     * Whether this hook applies to restore processes.
     *
     * @return bool
     */
    public function supportsRestore(): bool
    {
        return true;
    }

    /**
     * Create hook handler.
     *
     * @param ContainerInterface $container
     * @return HookHandlerInterface
     */
    public function createHookHandler(ContainerInterface $container): HookHandlerInterface
    {
        $repository = $container->get(ProcessRepositoryInterface::class);

        return new class(
            $repository,
        ) extends AbstractHookHandler {
            
            use LoggerTrait;
            
            public function __construct(
                private ProcessRepositoryInterface $repository,
            ) {}

            public function processFinished(ProcessInterface $process): void
            {
                $this->delete($process);
            }

            private function delete(ProcessInterface $process): void
            {
                try {
                    $this->repository->deleteById(id: $process->id());
                } catch (RepositoryDeleteException $e) {
                    $this->getLogger()->warning(
                        message: 'Failed to auto-delete finished backup/restore process entity',
                        context: [
                            'processId' => $process->id(),
                            'exception' => $e,
                        ]
                    );
                }
            }
        };
    }
}