<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service;

use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\NrLlmUnavailableToolsReader;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Every unit test of the unavailable-tools list hands the reader to the chat
 * service by hand (ADR-017). In production it arrives only through
 * autowiring — the interface has one implementation and no alias, and the
 * reader needs the PSR container — and if either failed the service would get
 * null, the prompt block would never render, and every other suite would stay
 * green. This asserts the container does it.
 */
final class ChatServiceWiringTest extends FunctionalTestCase
{
    // nr_mcp_agent depends on filelist (the FAL picker's element browser).
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    #[Test]
    public function theContainerHandsTheChatTheUnavailableToolsReader(): void
    {
        $service = $this->get(ChatService::class);
        self::assertInstanceOf(ChatService::class, $service);

        $reader = (new ReflectionProperty(ChatService::class, 'unavailableTools'))->getValue($service);

        self::assertInstanceOf(NrLlmUnavailableToolsReader::class, $reader);
    }
}
