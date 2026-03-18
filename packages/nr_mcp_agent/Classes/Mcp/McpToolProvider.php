<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Mcp;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use stdClass;
use TYPO3\CMS\Core\Core\Environment;

final class McpToolProvider implements McpToolProviderInterface
{
    private ?McpConnection $connection = null;
    /** @var list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>|null */
    private ?array $cachedTools = null;

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

    /**
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function getToolDefinitions(): array
    {
        if ($this->connection === null || !$this->connection->isOpen()) {
            return [];
        }

        if ($this->cachedTools !== null) {
            return $this->cachedTools;
        }

        $result = $this->connection->call('tools/list');

        /** @var array<mixed> $rawTools */
        $rawTools = is_array($result['tools'] ?? null) ? $result['tools'] : [];

        $tools = [];
        foreach ($rawTools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            /** @var array<string, mixed> $toolData */
            $toolData = $tool;
            $name = is_string($toolData['name'] ?? null) ? $toolData['name'] : '';
            $description = is_string($toolData['description'] ?? null) ? $toolData['description'] : '';
            /** @var array<string, mixed> $inputSchema */
            $inputSchema = is_array($toolData['inputSchema'] ?? null) ? $toolData['inputSchema'] : [];
            // OpenAI requires parameters to be a JSON Schema object with type "object"
            // and "properties" as an object (not an empty array).
            // MCP tools may return [], omit inputSchema, or have properties: [].
            $parameters = $this->normalizeToolSchema($inputSchema);
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $description,
                    'parameters' => $parameters,
                ],
            ];
        }

        $this->cachedTools = $tools;

        return $this->cachedTools;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function executeTool(string $toolName, array $input): string
    {
        if ($this->connection === null || !$this->connection->isOpen()) {
            return json_encode(['error' => 'MCP not connected']) ?: '{"error":"MCP not connected"}';
        }

        $result = $this->connection->call('tools/call', [
            'name' => $toolName,
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

    /**
     * Normalize an MCP inputSchema to a valid OpenAI function parameters schema.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeToolSchema(array $schema): array
    {
        // Empty or missing schema → parameterless tool
        if ($schema === [] || !isset($schema['type'])) {
            return ['type' => 'object', 'properties' => new stdClass()];
        }

        // Ensure properties is always an object, not an empty array.
        // json_decode("[]", true) and json_decode("{}", true) both produce [],
        // but OpenAI requires properties to be a JSON object.
        if (isset($schema['properties']) && is_array($schema['properties']) && $schema['properties'] === []) {
            $schema['properties'] = new stdClass();
        }

        return $schema;
    }

    public function disconnect(): void
    {
        $this->connection?->close();
        $this->connection = null;
        $this->cachedTools = null;
    }
}
