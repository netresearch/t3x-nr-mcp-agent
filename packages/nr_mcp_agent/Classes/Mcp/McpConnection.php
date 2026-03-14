<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Mcp;

final class McpConnection
{
    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $stdin = null;
    /** @var resource|null */
    private $stdout = null;
    private int $requestId = 0;
    private bool $initialized = false;

    public function open(string $command, array $args = [], string $cwd = ''): void
    {
        if ($this->process !== null) {
            $this->close();
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->process = proc_open(
            [$command, ...$args],
            $descriptors,
            $pipes,
            $cwd ?: null,
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException('Failed to start MCP server process');
        }

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        fclose($pipes[2]);

        stream_set_blocking($this->stdout, false);

        $this->call('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new \stdClass(),
            'clientInfo' => [
                'name' => 'nr-mcp-agent',
                'version' => '0.1.0',
            ],
        ]);

        $this->notify('notifications/initialized');
        $this->initialized = true;
    }

    public function isOpen(): bool
    {
        return $this->initialized && $this->process !== null;
    }

    public function call(string $method, array $params = []): array
    {
        $id = ++$this->requestId;

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->write($request);

        return $this->readResponse($id);
    }

    public function notify(string $method, array $params = []): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->write($request);
    }

    public function close(): void
    {
        if ($this->stdin !== null) {
            fclose($this->stdin);
            $this->stdin = null;
        }
        if ($this->stdout !== null) {
            fclose($this->stdout);
            $this->stdout = null;
        }
        if ($this->process !== null) {
            proc_close($this->process);
            $this->process = null;
        }
        $this->initialized = false;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function write(string $data): void
    {
        if ($this->stdin === null) {
            throw new \RuntimeException('MCP connection not open');
        }
        fwrite($this->stdin, $data . "\n");
        fflush($this->stdin);
    }

    private function readResponse(int $expectedId, float $timeoutSeconds = 30.0): array
    {
        if ($this->stdout === null) {
            throw new \RuntimeException('MCP connection not open');
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = fgets($this->stdout);
            if ($chunk === false || $chunk === '') {
                usleep(10_000);
                continue;
            }

            $buffer .= $chunk;

            $decoded = json_decode(trim($buffer), true);
            if ($decoded === null) {
                continue;
            }

            $buffer = '';

            if (!isset($decoded['id'])) {
                continue;
            }

            if ($decoded['id'] !== $expectedId) {
                continue;
            }

            if (isset($decoded['error'])) {
                throw new \RuntimeException(
                    sprintf('MCP error %d: %s',
                        $decoded['error']['code'] ?? -1,
                        $decoded['error']['message'] ?? 'Unknown error'
                    )
                );
            }

            return $decoded['result'] ?? [];
        }

        throw new \RuntimeException(
            sprintf('MCP server timeout after %.1fs waiting for response to request %d', $timeoutSeconds, $expectedId)
        );
    }
}
