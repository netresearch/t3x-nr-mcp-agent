<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Mcp;

use RuntimeException;
use stdClass;

final class McpConnection
{
    /** @var resource|null */
    private $process;
    /** @var resource|null */
    private $stdin;
    /** @var resource|null */
    private $stdout;
    private int $requestId = 0;
    private bool $initialized = false;

    /**
     * @param list<string> $args
     */
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

        $process = proc_open(
            [$command, ...$args],
            $descriptors,
            $pipes,
            $cwd ?: null,
        );

        if ($process === false) {
            throw new RuntimeException('Failed to start MCP server process');
        }

        $this->process = $process;

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        fclose($pipes[2]);

        stream_set_blocking($this->stdout, false);

        $this->call('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new stdClass(),
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

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $params
     */
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
            $status = proc_get_status($this->process);
            if (is_array($status) && ($status['running'] ?? false)) {
                proc_terminate($this->process);
            }
            @proc_close($this->process);
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
            throw new RuntimeException('MCP connection not open');
        }
        fwrite($this->stdin, $data . "\n");
        fflush($this->stdin);
    }

    /**
     * @return array<string, mixed>
     */
    private function readResponse(int $expectedId, float $timeoutSeconds = 30.0): array
    {
        if ($this->stdout === null) {
            throw new RuntimeException('MCP connection not open');
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

            /** @var mixed $decoded */
            $decoded = json_decode(trim($buffer), true);
            if (!is_array($decoded)) {
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
                /** @var array<string, mixed> $error */
                $error = is_array($decoded['error']) ? $decoded['error'] : [];
                $errCode = is_int($error['code'] ?? null) ? $error['code'] : -1;
                $errMsg = is_string($error['message'] ?? null) ? $error['message'] : 'Unknown error';
                throw new RuntimeException(
                    sprintf('MCP error %d: %s', $errCode, $errMsg),
                );
            }

            /** @var array<string, mixed> $result */
            $result = $decoded['result'] ?? [];
            return $result;
        }

        throw new RuntimeException(
            sprintf('MCP server timeout after %.1fs waiting for response to request %d', $timeoutSeconds, $expectedId),
        );
    }
}
