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

namespace Tobento\App\Backup\Registry\Action;

use PDO;
use Psr\Container\ContainerInterface;
use Tobento\App\AppInterface;
use Tobento\App\Backup\Action;
use Tobento\App\Backup\Action\ActionInterface;
use Tobento\App\Backup\Exception\ActionCreateException;
use Tobento\App\Backup\Repo\BackupEntityInterface;
use Tobento\App\Backup\BackupRuntimeConfigInterface;
use Tobento\App\Crud\Action\ActionInterface as CrudActionInterface;
use Tobento\App\Crud\Field;
use Tobento\App\Crud\Field\FieldInterface;
use Tobento\App\Crud\Field\FieldsInterface;
use Tobento\App\Crud\Html;
use Tobento\Apps\AppsInterface;
use Tobento\Service\Database\DatabaseInterface;
use Tobento\Service\Database\DatabasesInterface;
use Tobento\Service\Database\PdoDatabaseInterface;
use function Tobento\App\Translation\trans;

class DatabasesAction implements ActionRegistryInterface
{
    /**
     * Create a new instance.
     *
     * @param string $name Registry display name.
     */
    final public function __construct(
        protected string $name,
    ) {}
    
    /**
     * Unique identifier.
     *
     * @return string
     */
    public function id(): string
    {
        return 'databases';
    }
    
    /**
     * Returns the registry name.
     *
     * @return string
     */
    public function name(): string
    {
        return trans($this->name);
    }
    
    /**
     * Returns the configured fields.
     *
     * @param CrudActionInterface $action
     * @return iterable<FieldInterface>|FieldsInterface
     */
    public function configureFields(CrudActionInterface $action): iterable|FieldsInterface
    {
        yield new Field\Select(
            name: 'options.databases.mode',
            label: trans('Mode'),
        )
            ->group(trans('Databases'))
            ->options([
                'complete' => trans('Complete restore'),
                'partial' => trans('Partial restore'),
            ])
            ->selected(value: 'complete', action: 'create|edit')
                ->infoText(new Html\Message(
                    summary: trans('Mode Descriptions'),
                    keyedList: [
                        trans('Complete mode') => trans('Writes all selected tables into a single dump file per database. In this mode, table selection during restore is not available.'),
                        
                        trans('Partial mode') => trans('Writes one dump file per table, which allows restoring individual tables and is also useful for very large databases, as splitting the dump into multiple files can help avoid timeouts during backup or restore. However, tables are backed up at slightly different moments, which may lead to timing inconsistencies (for example, orphaned references).'),
                    ],
                    open: false,
                ));
        
        $container = $action->container();
        $connections = $this->getCollectedTables($container);

        foreach ($connections as $databaseName => $info) {

            $tables = $info['tables'];
            $apps = $info['apps'];

            sort($tables);
            
            // Build label: "mysql (frontend, backend)"
            $label = $databaseName;
            if (!empty($apps)) {
                $label .= ' (' . implode(', ', $apps) . ')';
            }

            yield new Field\Checkboxes(
                name: 'options.databases.tables.' . $databaseName,
                label: $label,
            )
                ->group($this->name())
                ->options(array_combine($tables, $tables));
        }
    }
    
    /**
     * Create action.
     *
     * @param ContainerInterface $container
     * @param BackupEntityInterface $entity
     * @return ActionInterface
     * @throws ActionCreateException
     */
    public function createAction(ContainerInterface $container, BackupEntityInterface $entity): ActionInterface
    {
        $selectedTables = $entity->get('options.databases.tables', []);

        if (empty($selectedTables)) {
            return new Action\NullAction(id: $this->id());
        }
        
        $runtime = $container->get(BackupRuntimeConfigInterface::class);
        
        return new Action\Databases(
            tables: $selectedTables,
            perTableDumpFiles: $entity->get('options.databases.mode') === 'partial',
            timeBudgetInSeconds: $runtime->jobTimeBudgetInSeconds(),
        );
    }

    protected function getCollectedTables(ContainerInterface $container): array
    {
        $app = $container->get(AppInterface::class);

        $apps = [$app]; // default: single app

        if ($app->has(AppsInterface::class)) {
            $apps = [];
            $appsInterface = $app->get(AppsInterface::class);

            // Boot all apps
            foreach ($appsInterface->all() as $appBoot) {
                $appBoot->app()->booting();
                $apps[] = $appBoot->app();
            }
        }

        $connections = []; // signature => ['database' => ..., 'apps' => [...]]

        foreach ($apps as $application) {

            if (! $application->has(DatabasesInterface::class)) {
                continue;
            }

            $databases = $application->get(DatabasesInterface::class);

            foreach ($databases->names() as $databaseName) {
                $database = $databases->get($databaseName);

                if (! $database instanceof PdoDatabaseInterface) {
                    continue;
                }
                
                if (! $database instanceof DatabaseInterface) {
                    continue;
                }

                $signature = spl_object_id($database->pdo());

                $connections[$signature]['database'] = $database;
                $connections[$signature]['apps'][] = $application->id();
            }
        }

        // Restore main app container if multi-app
        if (isset($appsInterface)) {
            $appsInterface->bootingApp($app);
        }

        // Collect tables once per connection
        $result = [];
        
        foreach ($connections as $info) {
            $database = $info['database'];

            $tables = $this->collectTablesFromDatabase($database);

            if (!empty($tables)) {
                $result[$database->name()] = [
                    'tables' => $tables,
                    'apps' => $info['apps'],
                ];
            }
        }

        return $result;
    }

    protected function collectTablesFromDatabase(PdoDatabaseInterface $database): array
    {
        $pdo = $database->pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'mysql' => $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
            
            // not yet supported as service-database (Dumper) and service-storage does not support it anyway.
            // 'pgsql' => $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN),
            
            // file-based, handled by directory backup
            'sqlite' => [],
            default => [],
        };
    }
}