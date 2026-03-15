<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Mcp;

interface McpToolProviderInterface
{
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
}
