<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Mcp;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\McpServerRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Throwable;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;

final class McpToolProvider implements McpToolProviderInterface
{
    /** @var array<string, McpConnection> server_key => McpConnection */
    private array $connections = [];

    /** @var array<string, string> prefixed tool name => server_key */
    private array $toolIndex = [];

    /** @var list<array<string, mixed>> cached active server rows for the current request */
    private array $activeServers = [];

    public function __construct(
        private readonly ExtensionConfiguration $config,
        private readonly McpServerRepository $serverRepository,
        private readonly FrontendInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * No-op for backwards compatibility. Connections are opened lazily.
     *
     * @deprecated Will be removed in a future version. Connections are managed internally.
     */
    public function connect(): void
    {
        // No-op: connections are opened lazily in getToolDefinitions() and executeTool()
    }

    /**
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function getToolDefinitions(): array
    {
        if (!$this->config->isMcpEnabled()) {
            return [];
        }

        $this->activeServers = $this->serverRepository->findAllActive();
        if ($this->activeServers === []) {
            return [];
        }

        $allTools = [];

        foreach ($this->activeServers as $server) {
            $serverKey = is_string($server['server_key'] ?? null) ? $server['server_key'] : '';
            if ($serverKey === '') {
                continue;
            }

            $uidRaw = $server['uid'] ?? 0;
            $uid = is_int($uidRaw) ? $uidRaw : (is_string($uidRaw) || is_float($uidRaw) ? (int) $uidRaw : 0);
            $cacheKey = $this->buildCacheKey($server);

            /** @var list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>|false $cached */
            $cached = $this->cache->get($cacheKey);

            if ($cached !== false) {
                // Cache hit: populate toolIndex, no connection needed
                foreach ($cached as $tool) {
                    $prefixedName = $tool['function']['name'];
                    $this->toolIndex[$prefixedName] = $serverKey;
                }
                $allTools = array_merge($allTools, $cached);
                continue;
            }

            // Cache miss: connect (or reuse existing), list tools, cache, populate toolIndex
            try {
                $connection = $this->connections[$serverKey] ?? $this->openConnection($server);
                $this->connections[$serverKey] = $connection;

                $result = $connection->call('tools/list');
                /** @var array<mixed> $rawTools */
                $rawTools = is_array($result['tools'] ?? null) ? $result['tools'] : [];

                $serverTools = [];
                foreach ($rawTools as $tool) {
                    if (!is_array($tool)) {
                        continue;
                    }
                    /** @var array<string, mixed> $toolData */
                    $toolData = $tool;
                    $originalName = is_string($toolData['name'] ?? null) ? $toolData['name'] : '';
                    $description = is_string($toolData['description'] ?? null) ? $toolData['description'] : '';
                    /** @var array<string, mixed> $inputSchema */
                    $inputSchema = is_array($toolData['inputSchema'] ?? null) ? $toolData['inputSchema'] : [];
                    $parameters = $this->normalizeToolSchema($inputSchema);

                    $prefixedName = $serverKey . '__' . $originalName;
                    $this->toolIndex[$prefixedName] = $serverKey;

                    $serverTools[] = [
                        'type' => 'function',
                        'function' => [
                            'name' => $prefixedName,
                            'description' => $description,
                            'parameters' => $parameters,
                        ],
                    ];
                }

                $this->cache->set($cacheKey, $serverTools);
                $allTools = array_merge($allTools, $serverTools);

                $this->serverRepository->updateConnectionStatus($uid, 'ok');
            } catch (Throwable $e) {
                $this->logger->error('MCP server connection failed', [
                    'server_key' => $serverKey,
                    'error' => $e->getMessage(),
                ]);
                $this->serverRepository->updateConnectionStatus($uid, 'error', $e->getMessage());
                // Skip this server, continue with others
            }
        }

        /** @var list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> $allTools */
        return $allTools;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function executeTool(string $toolName, array $input): string
    {
        $serverKey = $this->toolIndex[$toolName] ?? null;
        if ($serverKey === null) {
            return json_encode(['error' => 'Unknown tool: ' . $toolName]) ?: '{"error":"Unknown tool"}';
        }

        // Strip prefix to get original MCP tool name
        $originalName = substr($toolName, strlen($serverKey) + 2); // +2 for '__'

        // Lazy connection: open if not already connected (cache-hit path)
        if (!isset($this->connections[$serverKey])) {
            $server = $this->findServerByKey($serverKey);
            if ($server === null) {
                return json_encode(['error' => "MCP server '" . $serverKey . "' not found"]) ?: '{"error":"Server not found"}';
            }

            try {
                $this->connections[$serverKey] = $this->openConnection($server);
                $uidRaw = $server['uid'] ?? 0;
                $this->serverRepository->updateConnectionStatus(
                    is_int($uidRaw) ? $uidRaw : (is_string($uidRaw) || is_float($uidRaw) ? (int) $uidRaw : 0),
                    'ok',
                );
            } catch (Throwable $e) {
                $this->logger->error('MCP server connection failed during executeTool', [
                    'server_key' => $serverKey,
                    'error' => $e->getMessage(),
                ]);
                return json_encode(['error' => "MCP server '" . $serverKey . "' not connected: " . $e->getMessage()])
                    ?: '{"error":"Connection failed"}';
            }
        }

        $result = $this->connections[$serverKey]->call('tools/call', [
            'name' => $originalName,
            'arguments' => $input,
        ]);

        $texts = [];
        /** @var array<mixed> $contentBlocks */
        $contentBlocks = is_array($result['content'] ?? null) ? $result['content'] : [];
        foreach ($contentBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            /** @var array<string, mixed> $blockData */
            $blockData = $block;
            if (($blockData['type'] ?? '') === 'text') {
                $texts[] = is_string($blockData['text'] ?? null) ? $blockData['text'] : '';
            }
        }

        return implode("\n", $texts) ?: (json_encode($result) ?: '{}');
    }

    public function disconnect(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
        $this->toolIndex = [];
        $this->activeServers = [];
        // Cache is NOT cleared — persists in cache framework
    }

    /**
     * Returns the active server rows loaded during getToolDefinitions().
     *
     * @return list<array<string, mixed>>
     */
    public function getActiveServers(): array
    {
        return $this->activeServers;
    }

    /**
     * Opens a new McpConnection for the given server record.
     *
     * @param array<string, mixed> $server
     */
    private function openConnection(array $server): McpConnection
    {
        $transport = is_string($server['transport'] ?? null) ? $server['transport'] : 'stdio';

        if ($transport === 'sse') {
            // SSE transport is not yet implemented in McpConnection
            throw new RuntimeException('SSE transport is not yet supported');
        }

        $command = is_string($server['command'] ?? null) ? $server['command'] : '';
        if ($command === '') {
            $command = Environment::getProjectPath() . '/vendor/bin/typo3';
        }

        $argsRaw = is_string($server['arguments'] ?? null) ? $server['arguments'] : '';
        $args = $argsRaw !== '' ? array_values(array_filter(
            array_map(trim(...), explode("\n", $argsRaw)),
            static fn(string $line): bool => $line !== '',
        )) : [];

        $connection = new McpConnection();
        $connection->open($command, $args, Environment::getProjectPath());

        return $connection;
    }

    /**
     * Finds a server row by key from the active servers list.
     *
     * @return array<string, mixed>|null
     */
    private function findServerByKey(string $serverKey): ?array
    {
        foreach ($this->activeServers as $server) {
            if (($server['server_key'] ?? '') === $serverKey) {
                return $server;
            }
        }
        return null;
    }

    /**
     * Builds a cache key for a server's tool list.
     *
     * @param array<string, mixed> $server
     */
    private function buildCacheKey(array $server): string
    {
        $serverKey = is_string($server['server_key'] ?? null) ? $server['server_key'] : '';
        $command = is_string($server['command'] ?? null) ? $server['command'] : '';
        $arguments = is_string($server['arguments'] ?? null) ? $server['arguments'] : '';
        $url = is_string($server['url'] ?? null) ? $server['url'] : '';

        return 'mcp_tools_' . $serverKey . '_' . md5($command . '|' . $arguments . '|' . $url);
    }

    /**
     * Normalize an MCP inputSchema to a valid OpenAI function parameters schema.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeToolSchema(array $schema): array
    {
        if ($schema === [] || !isset($schema['type'])) {
            return ['type' => 'object', 'properties' => new stdClass()];
        }

        if (isset($schema['properties']) && is_array($schema['properties']) && $schema['properties'] === []) {
            $schema['properties'] = new stdClass();
        }

        return $schema;
    }
}
