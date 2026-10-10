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

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Throwable;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Process\ProcessInterface;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use function Tobento\App\Translation\trans;

final class ProcessLifecycle implements HookInterface
{
    /**
     * Create a new instance.
     *
     * @param string $name The display name of the hook.
     * @param string $group The group of the hook.
     */
    public function __construct(
        private string $name,
        private string $group = 'Process Lifecycle',
    ) {}
    
    /**
     * Unique identifier.
     *
     * @return string
     */
    public function id(): string
    {
        return 'process.lifecycle';
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
        return false;
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
        $repo = $container->get(ProcessRepositoryInterface::class);
        $clock = $container->get(ClockInterface::class);

        return new class($repo, $clock) extends AbstractHookHandler {
            public function __construct(
                private ProcessRepositoryInterface $repo,
                private ClockInterface $clock,
            ) {}

            public function processStarted(ProcessInterface $process): void
            {
                $this->repo->updateById(
                    id: $process->id(),
                    attributes: [
                        'status' => 'processing',
                    ]
                );
            }

            public function actionStepExecuting(
                ActionInterface $action,
                ProcessInterface $process
            ): void {
                $state = $process->stateFor($action);

                $state['runtime'] ??= 0;
                $state['step_started_at'] = microtime(true);

                $process->updateStateFor($action, $state);
            }
            
            public function actionStepExecuted(
                ActionInterface $action,
                ProcessInterface $process
            ): void {
                $state = $process->stateFor($action);

                $startedAt = $state['step_started_at'] ?? null;

                if (is_float($startedAt)) {
                    $finishedAt = microtime(true);

                    $state['runtime'] = (float)($state['runtime'] ?? 0)
                        + ($finishedAt - $startedAt);
                }

                $state['step_started_at'] = null;

                $process->updateStateFor($action, $state);
            }
            
            public function processFinished(ProcessInterface $process): void
            {
                $entity = $this->repo->findById($process->id());

                $newPeak = memory_get_peak_usage(true);
                $oldPeak = $entity?->memoryPeakBytes() ?? 0;

                $attributes = [
                    'status' => 'completed',
                    'memory_peak_bytes' => max($newPeak, $oldPeak),
                ];
                
                $totalRuntime = 0;

                foreach ($process->actions() as $action) {
                    $state = $process->stateFor($action);
                    $totalRuntime += $state['runtime'] ?? 0;
                }

                $attributes['runtime_seconds'] = $totalRuntime;

                $this->repo->updateById(
                    id: $process->id(),
                    attributes: $attributes
                );
            }

            public function processFailed(
                ProcessInterface $process,
                Throwable $exception
            ): void {
                $this->repo->updateById(
                    id: $process->id(),
                    attributes: [
                        'status' => 'failed',
                    ]
                );
            }
        };
    }
}