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
 
namespace Tobento\App\Backup;

class BackupRuntimeConfig implements BackupRuntimeConfigInterface
{
    /**
     * Create a new instance.
     *
     * @param null|string $queueName
     * @param int $jobTimeBudgetInSeconds
     * @param string $encrypter
     * @param int $encryptThroughputInBytes
     */
    public function __construct(
        protected null|string $queueName = null,
        protected int $jobTimeBudgetInSeconds = 20,
        protected string $encrypter = 'default',
        protected int $encryptThroughputInBytes = 50_000_000,
    ) {
        if ($jobTimeBudgetInSeconds < 10) {
            throw new \InvalidArgumentException(
                'jobTimeBudgetInSeconds must be at least 10 seconds.'
            );
        }
    }
    
    /**
     * Returns the queue name used for dispatching backup and restore jobs.
     * If null is returned, the default queue will be used.
     *
     * @return null|string The queue name or null for the default queue.
     */
    public function queueName(): null|string
    {
        return $this->queueName;
    }
    
    /**
     * Returns the maximum execution time budget in seconds for a single
     * backup or restore job. This value is used both as the queue job
     * duration and as the time budget for action execution (chunking and
     * resumability).
     *
     * The value must not exceed the queue worker timeout.
     *
     * @return int The unified time budget for job execution and action processing.
     */
    public function jobTimeBudgetInSeconds(): int
    {
        return $this->jobTimeBudgetInSeconds;
    }

    /**
     * Returns the name of the encrypter to be used for encryption.
     *
     * @return string The configured encrypter name.
     */
    public function encrypter(): string
    {
        return $this->encrypter;
    }

    /**
     * Returns the estimated encryption throughput in bytes per second.
     *
     * @return int
     */
    public function encryptThroughputInBytes(): int
    {
        return $this->encryptThroughputInBytes;
    }
}