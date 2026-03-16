<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Mcp;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Mcp\McpConnection;
use Netresearch\NrMcpAgent\Mcp\McpToolProvider;
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class McpToolProviderTest extends TestCase
{
    /** @var list<McpConnection> */
    private array $connectionsToCleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->connectionsToCleanup as $conn) {
            $conn->close();
        }
        $this->connectionsToCleanup = [];
        parent::tearDown();
    }

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

    #[Test]
    public function disconnectClearsCachedTools(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(false);

        $provider = new McpToolProvider($config);
        $provider->disconnect();
        self::assertSame([], $provider->getToolDefinitions());
    }

    #[Test]
    public function implementsMcpToolProviderInterface(): void
    {
        $reflection = new ReflectionClass(McpToolProvider::class);
        self::assertTrue($reflection->implementsInterface(McpToolProviderInterface::class));
    }

    #[Test]
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(McpToolProvider::class);
        self::assertTrue($reflection->isFinal());
    }

    #[Test]
    public function getToolDefinitionsWhenConnectedCallsToolsList(): void
    {
        $provider = $this->createProviderWithFakeServer([
            'tools/list' => [
                'tools' => [
                    [
                        'name' => 'my_tool',
                        'description' => 'A test tool',
                        'inputSchema' => ['type' => 'object', 'properties' => []],
                    ],
                ],
            ],
        ]);

        $tools = $provider->getToolDefinitions();

        self::assertCount(1, $tools);
        self::assertSame('function', $tools[0]['type']);
        self::assertSame('my_tool', $tools[0]['function']['name']);
        self::assertSame('A test tool', $tools[0]['function']['description']);
        $params = $tools[0]['function']['parameters'];
        self::assertSame('object', $params['type']);
        self::assertInstanceOf(\stdClass::class, $params['properties']);
    }

    #[Test]
    public function getToolDefinitionsCachesResults(): void
    {
        $provider = $this->createProviderWithFakeServer([
            'tools/list' => [
                'tools' => [
                    ['name' => 'cached_tool', 'description' => 'Cached', 'inputSchema' => ['type' => 'object']],
                ],
            ],
        ]);

        $first = $provider->getToolDefinitions();
        $second = $provider->getToolDefinitions();

        self::assertSame($first, $second);
        self::assertCount(1, $second);
    }

    #[Test]
    public function executeToolWhenConnectedParsesTextBlocks(): void
    {
        $provider = $this->createProviderWithFakeServer([
            'tools/call' => [
                'content' => [
                    ['type' => 'text', 'text' => 'Tool result output'],
                ],
            ],
        ]);

        $result = $provider->executeTool('my_tool', ['key' => 'value']);

        self::assertSame('Tool result output', $result);
    }

    #[Test]
    public function executeToolParsesMultipleTextBlocks(): void
    {
        $provider = $this->createProviderWithFakeServer([
            'tools/call' => [
                'content' => [
                    ['type' => 'text', 'text' => 'Line 1'],
                    ['type' => 'image', 'data' => 'binary'],
                    ['type' => 'text', 'text' => 'Line 2'],
                ],
            ],
        ]);

        $result = $provider->executeTool('tool', []);

        self::assertSame("Line 1\nLine 2", $result);
    }

    #[Test]
    public function executeToolReturnsJsonWhenNoTextBlocks(): void
    {
        $provider = $this->createProviderWithFakeServer([
            'tools/call' => [
                'content' => [
                    ['type' => 'image', 'data' => 'binary'],
                ],
            ],
        ]);

        $result = $provider->executeTool('tool', []);

        $decoded = json_decode($result, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('content', $decoded);
    }

    #[Test]
    public function executeToolHandlesEmptyContentBlocks(): void
    {
        $provider = $this->createProviderWithFakeServer([
            'tools/call' => [
                'content' => [],
            ],
        ]);

        $result = $provider->executeTool('tool', []);

        $decoded = json_decode($result, true);
        self::assertIsArray($decoded);
    }

    #[Test]
    public function getToolDefinitionsHandlesNonArrayTools(): void
    {
        $provider = $this->createProviderWithFakeServer([
            'tools/list' => [
                'tools' => [
                    'not-an-array',
                    ['name' => 'valid_tool', 'description' => 'Valid'],
                    42,
                ],
            ],
        ]);

        $tools = $provider->getToolDefinitions();

        self::assertCount(1, $tools);
        self::assertSame('valid_tool', $tools[0]['function']['name']);
    }

    #[Test]
    public function getToolDefinitionsHandlesMissingInputSchema(): void
    {
        $provider = $this->createProviderWithFakeServer([
            'tools/list' => [
                'tools' => [
                    ['name' => 'tool_no_schema', 'description' => 'No schema'],
                ],
            ],
        ]);

        $tools = $provider->getToolDefinitions();

        self::assertCount(1, $tools);
        $params = $tools[0]['function']['parameters'];
        self::assertSame('object', $params['type']);
        self::assertInstanceOf(\stdClass::class, $params['properties']);
    }

    #[Test]
    public function disconnectClearsConnectionAndCache(): void
    {
        $provider = $this->createProviderWithFakeServer([
            'tools/list' => [
                'tools' => [
                    ['name' => 'tool', 'description' => 'Test'],
                ],
            ],
        ]);

        $provider->getToolDefinitions();
        $provider->disconnect();

        self::assertSame([], $provider->getToolDefinitions());
    }

    /**
     * Creates a McpToolProvider with a real McpConnection backed by a fake MCP server process.
     *
     * @param array<string, array<string, mixed>> $responses method => result mapping
     */
    private function createProviderWithFakeServer(array $responses): McpToolProvider
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $provider = new McpToolProvider($config);

        $connection = $this->createFakeServerConnection($responses);
        $this->connectionsToCleanup[] = $connection;

        $providerRef = new ReflectionClass($provider);
        $connProp = $providerRef->getProperty('connection');
        $connProp->setValue($provider, $connection);

        return $provider;
    }

    /**
     * Creates a McpConnection with internals wired to a fake PHP process that
     * reads JSON-RPC requests from stdin and responds with pre-configured results.
     *
     * @param array<string, array<string, mixed>> $responses
     */
    private function createFakeServerConnection(array $responses): McpConnection
    {
        $responseJson = json_encode($responses, JSON_THROW_ON_ERROR);
        // Base64 encode to avoid shell escaping issues
        $encodedResponses = base64_encode($responseJson);

        $fakeServer = <<<PHP
\$responses = json_decode(base64_decode('{$encodedResponses}'), true);
while (\$line = fgets(STDIN)) {
    \$request = json_decode(trim(\$line), true);
    if (!is_array(\$request) || !isset(\$request['method'])) continue;
    \$method = \$request['method'];
    \$id = \$request['id'] ?? null;
    \$result = \$responses[\$method] ?? [];
    echo json_encode(['jsonrpc' => '2.0', 'id' => \$id, 'result' => \$result]) . "\\n";
}
PHP;

        $connection = new McpConnection();
        $reflection = new ReflectionClass($connection);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, '-r', $fakeServer],
            $descriptors,
            $pipes,
        );

        if ($process === false) {
            self::markTestSkipped('Cannot create fake MCP server process');
        }

        fclose($pipes[2]);
        stream_set_blocking($pipes[1], false);

        $processProp = $reflection->getProperty('process');
        $processProp->setValue($connection, $process);
        $stdinProp = $reflection->getProperty('stdin');
        $stdinProp->setValue($connection, $pipes[0]);
        $stdoutProp = $reflection->getProperty('stdout');
        $stdoutProp->setValue($connection, $pipes[1]);
        $initializedProp = $reflection->getProperty('initialized');
        $initializedProp->setValue($connection, true);

        return $connection;
    }
}
