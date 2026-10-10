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

use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Process\ProcessInterface;

abstract class AbstractHookHandler implements HookHandlerInterface
{
    /**
     * Triggered when the process starts.
     *
     * Called once before any actions are executed. Allows initialization
     * such as updating status, logging, or preparing resources.
     *
     * @param ProcessInterface $process The process context.
     * @return void
     */
    public function processStarted(ProcessInterface $process): void
    {
        //
    }

    /**
     * Triggered right before an action executes a single incremental step.
     *
     * Provides a timing boundary for step‑level execution. Useful for
     * measuring actual runtime, initializing per‑step state, logging
     * activity, or preparing resources before the step runs.
     *
     * @param ActionInterface $action The action about to execute a step.
     * @param ProcessInterface $process The process context.
     * @return void
     */
    public function actionStepExecuting(
        ActionInterface $action,
        ProcessInterface $process
    ): void {
        //
    }

    /**
     * Triggered after an action completes a single incremental step.
     *
     * Called once per step, not once per action. Useful for tracking
     * fine‑grained progress, measuring step‑level runtime, logging activity,
     * or updating per‑step metadata.
     *
     * @param ActionInterface $action  The action that executed the step.
     * @param ProcessInterface $process The process context.
     * @return void
     */
    public function actionStepExecuted(
        ActionInterface $action,
        ProcessInterface $process
    ): void {
        //
    }

    /**
     * Triggered when an action has fully completed.
     *
     * Called once per action after all of its steps have finished.
     *
     * @param ActionInterface $action The completed action.
     * @param ProcessInterface $process The process context.
     * @return void
     */
    public function actionFinished(
        ActionInterface $action,
        ProcessInterface $process
    ): void {
        //
    }

    /**
     * Triggered when the entire process has completed.
     *
     * Called after all actions have finished successfully.
     *
     * @param ProcessInterface $process The final process context.
     * @return void
     */
    public function processFinished(ProcessInterface $process): void
    {
        //
    }

    /**
     * Triggered when the process fails due to an exception.
     *
     * Allows hooks to update status, log the failure, notify users,
     * or perform cleanup tasks.
     *
     * @param ProcessInterface $process The process context at failure time.
     * @param \Throwable $exception The exception that caused the failure.
     * @return void
     */
    public function processFailed(ProcessInterface $process, \Throwable $exception): void
    {
        //
    }
    
    /**
     * Returns the deepest exception.
     *
     * @param \Throwable $e
     * @return \Throwable
     */
    protected function getDeepestException(\Throwable $e): \Throwable
    {
        while (($prev = $e->getPrevious()) !== null) {
            $e = $prev;
        }
        return $e;
    }
}