<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Mcp;

interface McpToolProviderInterface
{
    /**
     * @deprecated Will be removed in a future version. Connections are managed internally.
     */
    public function connect(): void;

    /**
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function getToolDefinitions(): array;

    /**
     * @param array<string, mixed> $input
     */
    public function executeTool(string $toolName, array $input): string;

    public function disconnect(): void;

    /**
     * Returns the active server rows loaded during getToolDefinitions().
     *
     * @return list<array<string, mixed>>
     */
    public function getActiveServers(): array;
}
