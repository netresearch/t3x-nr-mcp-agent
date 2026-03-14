<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Mcp;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;

final class McpToolProvider
{
    private ?McpConnection $connection = null;

    public function __construct(
        private readonly ExtensionConfiguration $config,
    ) {}

    public function connect(): void
    {
        if (!$this->config->isMcpEnabled()) {
            return;
        }

        $command = $this->config->getMcpServerCommand()
            ?: Environment::getProjectPath() . '/vendor/bin/typo3';
        $args = $this->config->getMcpServerArgs() ?: ['mcp:server'];

        $this->connection = new McpConnection();
        $this->connection->open($command, $args, Environment::getProjectPath());
    }

    public function getToolDefinitions(): array
    {
        if ($this->connection === null || !$this->connection->isOpen()) {
            return [];
        }

        $result = $this->connection->call('tools/list');

        return array_map(
            static fn(array $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['inputSchema'] ?? ['type' => 'object'],
                ],
            ],
            $result['tools'] ?? []
        );
    }

    public function executeTool(string $toolName, array $input): string
    {
        if ($this->connection === null || !$this->connection->isOpen()) {
            return json_encode(['error' => 'MCP not connected']);
        }

        $result = $this->connection->call('tools/call', [
            'name' => $toolName,
            'arguments' => $input,
        ]);

        $texts = [];
        foreach ($result['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $texts[] = $block['text'];
            }
        }

        return implode("\n", $texts) ?: json_encode($result);
    }

    public function disconnect(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }
}
