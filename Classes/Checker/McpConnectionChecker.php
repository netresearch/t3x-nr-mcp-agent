<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Checker;

use Netresearch\NrMcpAgent\Domain\Repository\McpServerRepository;
use Netresearch\NrMcpAgent\Mcp\McpConnection;
use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests connectivity to a single MCP server record and persists the result.
 *
 * Note: designed to be instantiated via GeneralUtility::makeInstance() from hook
 * contexts where the DI container may not be available, so dependencies are
 * resolved internally rather than via constructor injection.
 */
final class McpConnectionChecker
{
    private McpServerRepository $serverRepository;

    public function __construct()
    {
        $this->serverRepository = new McpServerRepository(
            GeneralUtility::makeInstance(ConnectionPool::class),
        );
    }

    /**
     * Opens a connection to the given server, calls tools/list to verify it works,
     * updates the connection_status record, and closes the connection again.
     *
     * @param array<string, mixed> $server Full server record row
     * @return string|null null on success, error message on failure
     */
    public function check(array $server): ?string
    {
        $uidRaw = $server['uid'] ?? 0;
        $uid = is_int($uidRaw) ? $uidRaw : (is_string($uidRaw) || is_float($uidRaw) ? (int) $uidRaw : 0);
        $transport = is_string($server['transport'] ?? null) ? $server['transport'] : 'stdio';

        if ($transport !== 'stdio') {
            return null;
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

        try {
            $connection = new McpConnection();
            $connection->open($command, $args, Environment::getProjectPath());
            $connection->call('tools/list');
            $connection->close();
            $this->serverRepository->updateConnectionStatus($uid, 'ok');
            return null;
        } catch (Throwable $e) {
            $this->serverRepository->updateConnectionStatus($uid, 'error', $e->getMessage());
            return $e->getMessage();
        }
    }
}
