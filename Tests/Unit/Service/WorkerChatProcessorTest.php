<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Service\WorkerChatProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class WorkerChatProcessorTest extends TestCase
{
    #[Test]
    public function dispatchIsNoOp(): void
    {
        $processor = new WorkerChatProcessor();
        $processor->dispatch(42);

        // No exception, no side-effect — the worker strategy is intentionally a no-op
        self::assertTrue(true);
    }
}
