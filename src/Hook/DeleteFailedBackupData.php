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
use Tobento\App\Backup\Artifact\ArtifactAdapterInterface;
use Tobento\App\Backup\Artifact\WritableArtifactInterface;
use Tobento\App\Backup\Artifact\WritableArtifacts;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Process\ProcessInterface;
use Tobento\App\Backup\Repo\RestoreRepositoryInterface;
use Tobento\App\Logging\LoggerTrait;
use Tobento\Service\Repository\RepositoryDeleteException;
use function Tobento\App\Translation\trans;

final class DeleteFailedBackupData implements HookInterface
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
        return 'delete.failed.backup.data';
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
        return false;
    }

    /**
     * Create hook handler.
     *
     * @param ContainerInterface $container
     * @return HookHandlerInterface
     */
    public function createHookHandler(ContainerInterface $container): HookHandlerInterface
    {
        $restoreRepository = $container->get(RestoreRepositoryInterface::class);

        return new class(
            $restoreRepository,
        ) extends AbstractHookHandler {

            use \Tobento\App\Logging\LoggerTrait;

            public function __construct(
                private RestoreRepositoryInterface $restoreRepository,
            ) {}

            public function processFailed(ProcessInterface $process, \Throwable $exception): void
            {
                $this->deleteArtifacts($process);
            }

            private function deleteArtifacts(ProcessInterface $process): void
            {
                // Only backup processes has artifact method
                if (!$process instanceof BackupProcessInterface) {
                    return;
                }
                
                $artifact = $process->artifact();

                // Unwrap adapters (EncryptionWritableArtifact, etc.)
                while ($artifact instanceof ArtifactAdapterInterface) {
                    $artifact = $artifact->adapted();
                }

                $artifacts = [];

                // Case 1: Single artifact
                if ($artifact instanceof WritableArtifactInterface
                    && ! $artifact instanceof WritableArtifacts) {

                    $artifacts[] = $artifact;

                // Case 2: Composite artifact container
                } elseif ($artifact instanceof WritableArtifacts) {

                    foreach ($artifact->artifacts() as $inner) {
                        // Unwrap nested adapters
                        while ($inner instanceof ArtifactAdapterInterface) {
                            $inner = $inner->adapted();
                        }

                        $artifacts[] = $inner;
                    }
                }
                
                foreach ($artifacts as $a) {
                    
                    $artifactId = $a->id();

                    try {
                        $this->restoreRepository->deleteById(id: $artifactId);
                    } catch (RepositoryDeleteException $e) {
                        $this->getLogger()->warning(
                            message: 'Failed to auto-delete failed backup artifact',
                            context: [
                                'artifactId' => $artifactId,
                                'processId' => $process->id(),
                                'exception' => $e,
                            ]
                        );
                    }
                }
            }
        };
    }
}