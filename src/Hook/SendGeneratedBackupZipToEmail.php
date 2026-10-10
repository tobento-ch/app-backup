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
use Tobento\App\Backup\Artifact\ArtifactAdapterInterface;
use Tobento\App\Backup\Artifact\WritableArtifacts;
use Tobento\App\Backup\Artifact\WritableZipArtifact;
use Tobento\App\Backup\Process\BackupProcessInterface;
use Tobento\App\Backup\Process\ProcessInterface;
use Tobento\App\Backup\Repo\ProcessRepositoryInterface;
use Tobento\App\Logging\LoggerTrait;
use Tobento\Service\Mail\MailerException;
use Tobento\Service\Mail\MailerInterface;
use Tobento\Service\Mail\Message;
use Tobento\Service\Mail\Parameter;
use function Tobento\App\Translation\trans;

final class SendGeneratedBackupZipToEmail implements HookInterface
{
    /**
     * Create a new instance.
     *
     * @param string $id A unique identifier.
     * @param string $name The display name of the hook.
     * @param string $email
     * @param string $subject The email subject.
     * @param string $message The email message.
     * @param string $group The hook group shown in the UI.
     * @param bool $defaultSelected Whether the hook is selected by default.
     */
    public function __construct(
        private string $id,
        private string $name,
        private string $email,
        private string $subject,
        private string $message,        
        private string $group = 'Delivery',
        private bool $defaultSelected = false,
    ) {}

    /**
     * Unique identifier.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->id;
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
        $mailer = $container->get(MailerInterface::class);

        return new class(
            $mailer,
            $this->email,
            $this->subject,
            $this->message,
        ) extends AbstractHookHandler {

            use LoggerTrait;

            public function __construct(
                private MailerInterface $mailer,
                private string $email,
                private string $subject,
                private string $message,
            ) {}

            public function processFinished(ProcessInterface $process): void
            {
                // Only backup processes generate ZIP artifacts
                if (!$process instanceof BackupProcessInterface) {
                    return;
                }

                $artifact = $process->artifact();
                $zipArtifact = null;

                while ($artifact instanceof ArtifactAdapterInterface) {
                    $artifact = $artifact->adapted();
                }

                // Case 1: The artifact itself is a ZIP
                if ($artifact instanceof WritableZipArtifact) {
                    $zipArtifact = $artifact;

                // Case 2: The artifact is a composite container
                } elseif ($artifact instanceof WritableArtifacts) {

                    foreach ($artifact->artifacts() as $a) {

                        // Unwrap nested adapters inside the composite
                        $inner = $a;
                        while ($inner instanceof ArtifactAdapterInterface) {
                            $inner = $inner->adapted();
                        }

                        if ($inner instanceof WritableZipArtifact) {
                            $zipArtifact = $inner;
                            break;
                        }
                    }
                }

                if ($zipArtifact === null) {
                    $this->getLogger()->info(
                        'No backup ZIP artifact to send email with ZIP',
                        ['processId' => $process->id()]
                    );
                    return;
                }
                
                $zip = $zipArtifact->zipPath();

                if (!is_file($zip)) {
                    $this->getLogger()->warning(
                        'Backup ZIP not found for email hook',
                        ['processId' => $process->id()]
                    );
                    return;
                }

                try {
                    $message = new Message()
                        ->to($this->email)
                        ->subject(str_replace('{name}', $process->name(), $this->subject))
                        ->text($this->message)
                        ->parameter(new Parameter\File(file: $zip));
                    
                    $this->mailer->send($message);

                } catch (MailerException $e) {
                    $this->getLogger()->error(
                        'Failed to send backup ZIP via email',
                        [
                            'processId' => $process->id(),
                            'exception' => $e,
                        ]
                    );
                }
            }
        };
    }
}