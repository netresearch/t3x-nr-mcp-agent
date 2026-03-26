<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Mcp;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\McpServerRepository;
use Netresearch\NrMcpAgent\Mcp\McpConnection;
use Netresearch\NrMcpAgent\Mcp\McpToolProvider;
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use stdClass;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

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
    public function getToolDefinitionsReturnsEmptyWhenMcpDisabled(): void
    {
        $provider = $this->createProvider(mcpEnabled: false);
        self::assertSame([], $provider->getToolDefinitions());
    }

    #[Test]
    public function getToolDefinitionsReturnsEmptyWhenNoActiveServers(): void
    {
        $provider = $this->createProvider(mcpEnabled: true, activeServers: []);
        self::assertSame([], $provider->getToolDefinitions());
    }

    #[Test]
    public function connectIsNoOp(): void
    {
        $provider = $this->createProvider(mcpEnabled: false);
        $provider->connect();
        // Should not throw, should not change state
        self::assertSame([], $provider->getToolDefinitions());
    }

    #[Test]
    public function disconnectIsIdempotent(): void
    {
        $provider = $this->createProvider(mcpEnabled: false);
        $provider->disconnect();
        $provider->disconnect();
        self::assertSame([], $provider->getToolDefinitions());
    }

    #[Test]
    public function getToolDefinitionsReturnsCachedToolsWithPrefixedNames(): void
    {
        $cachedTools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'typo3__create_page',
                    'description' => 'Create a page',
                    'parameters' => ['type' => 'object', 'properties' => new stdClass()],
                ],
            ],
        ];

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn($cachedTools);

        $provider = $this->createProvider(
            mcpEnabled: true,
            activeServers: [$this->makeServerRow('typo3', 'TYPO3 MCP Server')],
            cache: $cache,
        );

        $tools = $provider->getToolDefinitions();

        self::assertCount(1, $tools);
        self::assertSame('typo3__create_page', $tools[0]['function']['name']);
    }

    #[Test]
    public function executeToolReturnsErrorForUnknownTool(): void
    {
        $provider = $this->createProvider(mcpEnabled: true, activeServers: []);
        $result = $provider->executeTool('nonexistent__tool', []);

        self::assertStringContainsString('Unknown tool', $result);
    }

    #[Test]
    public function executeToolRoutesCorrectlyAfterCacheHit(): void
    {
        $cachedTools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'typo3__create_page',
                    'description' => 'Create a page',
                    'parameters' => ['type' => 'object', 'properties' => new stdClass()],
                ],
            ],
        ];

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn($cachedTools);

        $server = $this->makeServerRow('typo3', 'TYPO3 MCP Server');
        $provider = $this->createProvider(
            mcpEnabled: true,
            activeServers: [$server],
            cache: $cache,
        );

        // Load tool definitions to populate toolIndex
        $provider->getToolDefinitions();

        // Inject a fake connection for execution
        $connection = $this->createFakeServerConnection([
            'tools/call' => [
                'content' => [
                    ['type' => 'text', 'text' => 'Page created'],
                ],
            ],
        ]);

        $ref = new ReflectionClass($provider);
        $connProp = $ref->getProperty('connections');
        $connProp->setValue($provider, ['typo3' => $connection]);

        $result = $provider->executeTool('typo3__create_page', ['title' => 'Test']);
        self::assertSame('Page created', $result);
    }

    #[Test]
    public function getToolDefinitionsOnCacheMissUsesConnectionAndCaches(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false); // cache miss
        $cache->expects(self::once())->method('set');

        $serverRepo = $this->createMock(McpServerRepository::class);
        $serverRepo->method('findAllActive')->willReturn([
            $this->makeServerRow('typo3', 'TYPO3 MCP Server'),
        ]);
        $serverRepo->expects(self::once())
            ->method('updateConnectionStatus')
            ->with(self::anything(), 'ok', '');

        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(true);

        $provider = new McpToolProvider($config, $serverRepo, $cache, new NullLogger());

        // Pre-inject a fake connection so openConnection() is not called
        $connection = $this->createFakeServerConnection([
            'tools/list' => [
                'tools' => [
                    [
                        'name' => 'create_page',
                        'description' => 'Create a page',
                        'inputSchema' => ['type' => 'object', 'properties' => []],
                    ],
                ],
            ],
        ]);

        $ref = new ReflectionClass($provider);
        $connProp = $ref->getProperty('connections');
        $connProp->setValue($provider, ['typo3' => $connection]);

        $tools = $provider->getToolDefinitions();

        self::assertCount(1, $tools);
        self::assertSame('typo3__create_page', $tools[0]['function']['name']);
        self::assertSame('Create a page', $tools[0]['function']['description']);
        self::assertInstanceOf(stdClass::class, $tools[0]['function']['parameters']['properties']);
    }

    #[Test]
    public function getToolDefinitionsPreservesPopulatedProperties(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);

        $serverRepo = $this->createMock(McpServerRepository::class);
        $serverRepo->method('findAllActive')->willReturn([
            $this->makeServerRow('typo3', 'TYPO3 MCP Server'),
        ]);

        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(true);

        $provider = new McpToolProvider($config, $serverRepo, $cache, new NullLogger());

        $connection = $this->createFakeServerConnection([
            'tools/list' => [
                'tools' => [
                    [
                        'name' => 'create_page',
                        'description' => 'Create a page',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $ref = new ReflectionClass($provider);
        $connProp = $ref->getProperty('connections');
        $connProp->setValue($provider, ['typo3' => $connection]);

        $tools = $provider->getToolDefinitions();

        self::assertCount(1, $tools);
        $params = $tools[0]['function']['parameters'];
        self::assertSame('object', $params['type']);
        self::assertIsArray($params['properties']);
        self::assertArrayHasKey('name', $params['properties']);
    }

    #[Test]
    public function disconnectClearsConnectionsAndToolIndex(): void
    {
        $cachedTools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'typo3__tool',
                    'description' => 'Test',
                    'parameters' => ['type' => 'object', 'properties' => new stdClass()],
                ],
            ],
        ];

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn($cachedTools);

        $provider = $this->createProvider(
            mcpEnabled: true,
            activeServers: [$this->makeServerRow('typo3', 'Test')],
            cache: $cache,
        );

        $provider->getToolDefinitions();
        $provider->disconnect();

        // After disconnect, executeTool should return unknown tool error
        $result = $provider->executeTool('typo3__tool', []);
        self::assertStringContainsString('Unknown tool', $result);
    }

    #[Test]
    public function getActiveServersReturnsLoadedServers(): void
    {
        $servers = [$this->makeServerRow('typo3', 'TYPO3 MCP Server')];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn([]);

        $provider = $this->createProvider(
            mcpEnabled: true,
            activeServers: $servers,
            cache: $cache,
        );

        $provider->getToolDefinitions();
        $active = $provider->getActiveServers();

        self::assertCount(1, $active);
        self::assertSame('typo3', $active[0]['server_key']);
    }

    #[Test]
    public function getToolDefinitionsSkipsServersWithEmptyKey(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn([]);

        $provider = $this->createProvider(
            mcpEnabled: true,
            activeServers: [$this->makeServerRow('', 'Empty Key Server')],
            cache: $cache,
        );

        $tools = $provider->getToolDefinitions();
        self::assertSame([], $tools);
    }

    #[Test]
    public function executeToolHandlesMultipleTextBlocks(): void
    {
        $cachedTools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'typo3__tool',
                    'description' => 'Test',
                    'parameters' => ['type' => 'object', 'properties' => new stdClass()],
                ],
            ],
        ];

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn($cachedTools);

        $provider = $this->createProvider(
            mcpEnabled: true,
            activeServers: [$this->makeServerRow('typo3', 'Test')],
            cache: $cache,
        );

        $provider->getToolDefinitions();

        $connection = $this->createFakeServerConnection([
            'tools/call' => [
                'content' => [
                    ['type' => 'text', 'text' => 'Line 1'],
                    ['type' => 'image', 'data' => 'binary'],
                    ['type' => 'text', 'text' => 'Line 2'],
                ],
            ],
        ]);

        $ref = new ReflectionClass($provider);
        $connProp = $ref->getProperty('connections');
        $connProp->setValue($provider, ['typo3' => $connection]);

        $result = $provider->executeTool('typo3__tool', []);
        self::assertSame("Line 1\nLine 2", $result);
    }

    #[Test]
    public function executeToolReturnsJsonWhenNoTextBlocks(): void
    {
        $cachedTools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'typo3__tool',
                    'description' => 'Test',
                    'parameters' => ['type' => 'object', 'properties' => new stdClass()],
                ],
            ],
        ];

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn($cachedTools);

        $provider = $this->createProvider(
            mcpEnabled: true,
            activeServers: [$this->makeServerRow('typo3', 'Test')],
            cache: $cache,
        );

        $provider->getToolDefinitions();

        $connection = $this->createFakeServerConnection([
            'tools/call' => [
                'content' => [
                    ['type' => 'image', 'data' => 'binary'],
                ],
            ],
        ]);

        $ref = new ReflectionClass($provider);
        $connProp = $ref->getProperty('connections');
        $connProp->setValue($provider, ['typo3' => $connection]);

        $result = $provider->executeTool('typo3__tool', []);
        $decoded = json_decode($result, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('content', $decoded);
    }

    // --- Helpers ---

    /**
     * @return array<string, mixed>
     */
    private function makeServerRow(string $key, string $name, int $uid = 1): array
    {
        return [
            'uid' => $uid,
            'pid' => 0,
            'name' => $name,
            'server_key' => $key,
            'transport' => 'stdio',
            'command' => '',
            'arguments' => 'mcp:server',
            'url' => '',
            'auth_token' => '',
            'hidden' => 0,
            'deleted' => 0,
            'sorting' => 1,
            'connection_status' => 'unknown',
            'connection_checked' => 0,
            'connection_error' => '',
        ];
    }

    private function createProvider(
        bool $mcpEnabled = false,
        ?array $activeServers = null,
        ?FrontendInterface $cache = null,
    ): McpToolProvider {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn($mcpEnabled);

        $serverRepo = $this->createMock(McpServerRepository::class);
        if ($activeServers !== null) {
            $serverRepo->method('findAllActive')->willReturn($activeServers);
        }

        $cache ??= $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);

        return new McpToolProvider($config, $serverRepo, $cache, new NullLogger());
    }

    /**
     * Creates a provider with a fake MCP server subprocess for cache-miss path testing.
     *
     * @param array<string, array<string, mixed>> $responses
     */
    private function createProviderWithFakeServer(
        array $responses,
        ?FrontendInterface $cache = null,
        ?McpServerRepository $serverRepo = null,
    ): McpToolProvider {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(true);

        if ($serverRepo === null) {
            $serverRepo = $this->createMock(McpServerRepository::class);
            $serverRepo->method('findAllActive')->willReturn([
                $this->makeServerRow('typo3', 'TYPO3 MCP Server'),
            ]);
        }

        $cache ??= $this->createMock(FrontendInterface::class);

        $provider = new McpToolProvider($config, $serverRepo, $cache, new NullLogger());

        // Inject a fake connection directly
        $connection = $this->createFakeServerConnection($responses);
        $ref = new ReflectionClass($provider);

        // We need to populate activeServers first by calling getToolDefinitions,
        // but we also need the connection injected before the real open() is attempted.
        // Instead, inject connection into $connections and also set activeServers.
        $connProp = $ref->getProperty('connections');
        $connProp->setValue($provider, ['typo3' => $connection]);

        $serversProp = $ref->getProperty('activeServers');
        $serversProp->setValue($provider, $serverRepo->findAllActive());

        // Now call getToolDefinitions — it will find the connection already open
        // Actually we need a different approach: the method will try to open a new connection.
        // Let me override openConnection behavior by mocking at a higher level.
        // Reset and use a different strategy: directly call the tools/list through the connection
        // and simulate what getToolDefinitions does.
        $connProp->setValue($provider, []);
        $serversProp->setValue($provider, []);

        // Instead, use reflection to set a pre-opened connection keyed by server_key
        // We'll need to make the provider think the cache missed but the connection is already there.
        // The cleanest way: inject the connection, then let getToolDefinitions populate the tools from it.
        // But getToolDefinitions calls openConnection() which calls McpConnection::open() with proc_open.
        // So we need to intercept that. Let's use the approach from the old test: inject after construction.

        // Actually, the simplest fix: just don't let it go through openConnection.
        // We already have a working connection. Let's intercept by pre-populating.
        $connProp->setValue($provider, ['typo3' => $connection]);

        return $provider;
    }

    /**
     * @param array<string, array<string, mixed>> $responses
     */
    private function createFakeServerConnection(array $responses): McpConnection
    {
        $responseJson = json_encode($responses, JSON_THROW_ON_ERROR);
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

        $this->connectionsToCleanup[] = $connection;

        return $connection;
    }
}
