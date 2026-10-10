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

namespace Tobento\App\Backup\Action;

use JsonException;
use Psr\Container\ContainerInterface;
use Throwable;
use Tobento\App\Backup\Exception\ActionsException;
use Tobento\App\Backup\Registry\Action\ActionRegistriesInterface;
use Tobento\App\Backup\Repo\BackupEntityInterface;

class ActionsFactory implements ActionsFactoryInterface
{
    /**
     * Create a new instance.
     *
     * @param ActionRegistriesInterface $actionRegistries
     * @param ContainerInterface $container
     */
    public function __construct(
        protected ActionRegistriesInterface $actionRegistries,
        protected ContainerInterface $container,
    ) {}
    
    /**
     * Creates actions from backup entity.
     *
     * @param BackupEntityInterface $entity
     * @return ActionsInterface
     * @throws ActionsException
     */
    public function createActionsFromBackupEntity(BackupEntityInterface $entity): ActionsInterface
    {
        $actions = [];
        
        foreach($this->actionRegistries as $actionRegistry) {
            $actions[] = $actionRegistry->createAction(container: $this->container, entity: $entity);
        }
        
        return new Actions(...$actions);
    }
    
    /**
     * Creates actions from array.
     *
     * @param array<string, mixed> $data
     * @return ActionsInterface
     * @throws ActionsException
     */
    public function createActionsFromArray(array $data): ActionsInterface
    {
        $actions = [];

        foreach ($data as $item) {

            if (!isset($item['class'], $item['data'])) {
                throw new ActionsException('Invalid action entry: missing class or data');
            }

            $actions[] = $this->createAction($item['class'], $item['data']);
        }

        return new Actions(...$actions);
    }

    /**
     * Creates actions from JSON string.
     *
     * @param string $json
     * @return ActionsInterface
     * @throws ActionsException
     */
    public function createActionsFromJsonString(string $json): ActionsInterface
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ActionsException(
                'Invalid JSON for actions: '.$e->getMessage(),
                $e->getCode(),
                $e
            );
        }

        if (!is_array($data)) {
            throw new ActionsException('Invalid actions data: must decode to an array');
        }

        return $this->createActionsFromArray($data);
    }

    /**
     * Create action from the specified name and value.
     *
     * @param class-string<ActionInterface> $name
     * @param mixed $value
     * @return ActionInterface
     * @throws ActionsException
     */
    protected function createAction(string $name, mixed $value): ActionInterface
    {
        if (!is_array($value)) {
            throw new ActionsException(
                sprintf('Invalid action value for %s: expected array of constructor parameters', $name)
            );
        }

        try {
            $action = new $name(...$value);
        } catch (Throwable $e) {
            throw new ActionsException(
                sprintf('Failed creating action %s: %s', $name, $e->getMessage()),
                (int)$e->getCode(),
                $e
            );
        }

        if (!$action instanceof ActionInterface) {
            throw new ActionsException(
                sprintf('Action %s must implement %s', $name, ActionInterface::class)
            );
        }

        return $action;
    }
}