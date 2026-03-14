<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Mcp;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Mcp\McpToolProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class McpToolProviderTest extends TestCase
{
    #[Test]
    public function connectDoesNothingWhenMcpDisabled(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(false);

        $provider = new McpToolProvider($config);
        $provider->connect();

        self::assertSame([], $provider->getToolDefinitions());
    }

    #[Test]
    public function getToolDefinitionsReturnsEmptyWhenNotConnected(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(false);

        $provider = new McpToolProvider($config);
        self::assertSame([], $provider->getToolDefinitions());
    }

    #[Test]
    public function executeToolReturnsErrorWhenNotConnected(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(false);

        $provider = new McpToolProvider($config);
        $result = $provider->executeTool('test', []);

        self::assertStringContainsString('error', $result);
        self::assertStringContainsString('MCP not connected', $result);
    }

    #[Test]
    public function disconnectIsIdempotent(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(false);

        $provider = new McpToolProvider($config);
        $provider->disconnect();
        $provider->disconnect();
        self::assertSame([], $provider->getToolDefinitions());
    }
}
