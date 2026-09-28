<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service;

use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\ExecChatProcessor;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Without a processingStrategy setting the default, exec, still applies.
 */
final class ChatProcessorDefaultBindingTest extends FunctionalTestCase
{
    // nr_mcp_agent depends on filelist (the FAL picker's element browser).
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    #[Test]
    public function theDefaultStrategyBindsTheExecProcessor(): void
    {
        self::assertInstanceOf(ExecChatProcessor::class, $this->get(ChatProcessorInterface::class));
    }
}
