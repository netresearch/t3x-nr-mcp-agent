<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service;

use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\WorkerChatProcessor;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * With processingStrategy = worker the controller must get the worker processor,
 * so no ai-chat:process is started next to the long-running worker.
 */
final class ChatProcessorWorkerBindingTest extends FunctionalTestCase
{
    // nr_mcp_agent depends on filelist (the FAL picker's element browser).
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_mcp_agent' => ['processingStrategy' => 'worker'],
        ],
    ];

    #[Test]
    public function theWorkerStrategyBindsTheWorkerProcessor(): void
    {
        self::assertInstanceOf(WorkerChatProcessor::class, $this->get(ChatProcessorInterface::class));
    }
}
