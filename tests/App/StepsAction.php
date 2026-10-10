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

namespace Tobento\App\Backup\Test\App;

use Psr\Container\ContainerInterface;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Process\RestoreProcessInterface;
use Tobento\App\Crud\Action\ActionInterface as CrudActionInterface;
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Input\InputInterface;

class StepsAction implements ActionInterface
{
    private int $step = 0;
    
    public function __construct(
        private string $name,
        private int $steps,
    ) {}
    
    public function id(): string
    {
        return $this->name;
    }
    
    public function backup(BackupProcessInterface $process, ContainerInterface $container): void
    {
        if ($process->isActionFinished(action: $this)) {
            return;
        }
        
        $state = $process->stateFor(action: $this);
        $step = $state['step'] ?? 0;
        $step++;
        $state['step'] = $step;
        $process->updateStateFor(action: $this, state: $state);
        
        if ($step >= $this->steps) {
            $process->markActionFinished($this);
        }
    }
    
    public function restore(RestoreProcessInterface $process, ContainerInterface $container): void
    {
        if ($process->isActionFinished(action: $this)) {
            return;
        }
        
        $state = $process->stateFor(action: $this);
        $step = $state['step'] ?? 0;
        $step++;
        $state['step'] = $step;
        $process->updateStateFor(action: $this, state: $state);
        
        if ($step >= $this->steps) {
            $process->markActionFinished($this);
        }
    }
    
    public function configureRestoreFields(CrudActionInterface $action): iterable
    {
        return [];
    }
    
    public function configureForRestore(InputInterface $input): ActionInterface
    {
        return clone $this;
    }
    
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'steps' => $this->steps,
        ];
    }
}