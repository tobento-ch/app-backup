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

namespace Tobento\App\Backup\Test\Artifact;

use PHPUnit\Framework\TestCase;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Artifact\FileAcceptResult;
use Tobento\App\Backup\Artifact\FileAcceptResultInterface;

class FileAcceptResultTest extends TestCase
{
    public function testImplementsInterface()
    {
        $result = new FileAcceptResult(FileAcceptDecision::Yes);

        $this->assertInstanceOf(FileAcceptResultInterface::class, $result);
    }

    public function testDecisionMethod()
    {
        $result = new FileAcceptResult(FileAcceptDecision::Defer);

        $this->assertSame(FileAcceptDecision::Defer, $result->decision());
    }

    public function testReasonMethod()
    {
        $result = new FileAcceptResult(
            FileAcceptDecision::Yes,
            reason: 'some reason'
        );

        $this->assertSame('some reason', $result->reason());
    }

    public function testShouldThrowMethod()
    {
        $result = new FileAcceptResult(
            FileAcceptDecision::NeverFits,
            reason: 'bad',
            throw: true
        );

        $this->assertTrue($result->shouldThrow());
        
        $this->assertFalse(new FileAcceptResult(FileAcceptDecision::Yes)->shouldThrow());
    }

    public function testShouldSkipMethod()
    {
        $result = new FileAcceptResult(
            FileAcceptDecision::Yes,
            reason: '',
            throw: false,
            skip: true
        );

        $this->assertTrue($result->shouldSkip());
        
        $this->assertFalse(new FileAcceptResult(FileAcceptDecision::Yes)->shouldSkip());
    }
}