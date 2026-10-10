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

namespace Tobento\App\Backup\Test\Action;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Action\Actions;
use Tobento\App\Backup\Action\ActionsFactory;
use Tobento\App\Backup\Action\ActionsFactoryInterface;
use Tobento\App\Backup\Action\ActionsInterface;
use Tobento\App\Backup\Action\NullAction;
use Tobento\App\Backup\Exception\ActionsException;
use Tobento\App\Backup\Registry\Action\ActionRegistryInterface;
use Tobento\App\Backup\Registry\Action\ActionRegistries;
use Tobento\App\Backup\Repo\BackupEntity;
use Tobento\App\Backup\Repo\BackupEntityInterface;
use Tobento\App\Crud\Action\ActionInterface as CrudActionInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\Service\Container\Container;

class ActionsFactoryTest extends TestCase
{
    protected function createActionRegistry(string $id): ActionRegistryInterface
    {
        return new class($id) implements ActionRegistryInterface {

            public function __construct(
                protected string $id
            ) {}

            public function id(): string
            {
                return $this->id;
            }

            public function name(): string
            {
                return 'Registry-'.$this->id;
            }

            public function configureFields(CrudActionInterface $action): iterable|FieldsInterface
            {
                return [];
            }

            public function createAction(
                ContainerInterface $container,
                BackupEntityInterface $entity
            ): ActionInterface {
                return new NullAction($this->id);
            }
        };
    }

    public function testImplementsActionsFactoryInterface()
    {
        $factory = new ActionsFactory(
            new ActionRegistries(),
            new Container(),
        );

        $this->assertInstanceOf(ActionsFactoryInterface::class, $factory);
    }

    public function testCreateActionsFromBackupEntity()
    {
        $entity = new BackupEntity(['id' => 1]);

        $registries = new ActionRegistries();
        $registries->add($this->createActionRegistry('a1'));
        $registries->add($this->createActionRegistry('a2'));

        $factory = new ActionsFactory($registries, new Container());

        $actions = $factory->createActionsFromBackupEntity($entity);

        $this->assertInstanceOf(ActionsInterface::class, $actions);
        $this->assertSame(['a1', 'a2'], $actions->ids());
    }

    public function testCreateActionsFromArray()
    {
        $factory = new ActionsFactory(
            new ActionRegistries(),
            new Container()
        );

        $data = [
            [
                'class' => NullAction::class,
                'data'  => ['id' => 'x'],
            ],
            [
                'class' => NullAction::class,
                'data'  => ['id' => 'y'],
            ],
        ];

        $actions = $factory->createActionsFromArray($data);

        $this->assertSame(['x', 'y'], $actions->ids());
    }

    public function testCreateActionsFromArrayThrowsOnMissingKeys()
    {
        $factory = new ActionsFactory(
            new ActionRegistries(),
            new Container()
        );

        $this->expectException(ActionsException::class);

        $factory->createActionsFromArray([
            ['class' => NullAction::class] // missing 'data'
        ]);
    }
    
    public function testCreateActionsFromArrayThrowsOnNonArrayValue()
    {
        $factory = new ActionsFactory(
            new ActionRegistries(),
            new Container()
        );

        $this->expectException(ActionsException::class);

        $factory->createActionsFromArray([
            [
                'class' => NullAction::class,
                'data'  => 'not-an-array', // invalid
            ],
        ]);
    }
    
    public function testCreateActionsFromArrayThrowsOnInvalidClass()
    {
        $factory = new ActionsFactory(
            new ActionRegistries(),
            new Container()
        );

        $this->expectException(ActionsException::class);

        $factory->createActionsFromArray([
            [
                'class' => \stdClass::class, // invalid
                'data'  => [],
            ],
        ]);
    }
    
    public function testCreateActionsFromArrayThrowsOnConstructorFailure()
    {
        $factory = new ActionsFactory(
            new ActionRegistries(),
            new Container()
        );

        $this->expectException(ActionsException::class);

        $factory->createActionsFromArray([
            [
                'class' => NullAction::class,
                'data'  => ['foo' => 'foo'],
            ],
        ]);
    }

    public function testCreateActionsFromJsonString()
    {
        $factory = new ActionsFactory(
            new ActionRegistries(),
            new Container()
        );

        $json = json_encode([
            ['class' => NullAction::class, 'data' => ['id' => 'a']],
            ['class' => NullAction::class, 'data' => ['id' => 'b']],
        ]);

        $actions = $factory->createActionsFromJsonString($json);

        $this->assertSame(['a', 'b'], $actions->ids());
    }

    public function testCreateActionsFromJsonStringThrowsOnInvalidJson()
    {
        $factory = new ActionsFactory(
            new ActionRegistries(),
            new Container()
        );

        $this->expectException(ActionsException::class);

        $factory->createActionsFromJsonString('{invalid json}');
    }

    public function testCreateActionsFromJsonStringThrowsOnNonArray()
    {
        $factory = new ActionsFactory(
            new ActionRegistries(),
            new Container()
        );

        $this->expectException(ActionsException::class);

        $factory->createActionsFromJsonString('"string-not-array"');
    }
}