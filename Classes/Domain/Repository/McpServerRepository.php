<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * DBAL-based repository for MCP server records.
 */
readonly class McpServerRepository
{
    private const TABLE = 'tx_nrmcpagent_mcp_server';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Returns all active (non-hidden, non-deleted) MCP server records ordered by sorting.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllActive(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Creates a default TYPO3 MCP server record if the table is empty.
     *
     * Called automatically by McpToolProvider when no server records exist,
     * covering both UI-based and deployment-based extension configuration.
     */
    public function initDefault(): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->insert(self::TABLE, [
            'pid' => 0,
            'name' => 'TYPO3 MCP Server',
            'server_key' => 'typo3',
            'transport' => 'stdio',
            'command' => '',
            'arguments' => 'mcp:server',
            'sorting' => 1,
        ]);
    }

    /**
     * Updates the connection status fields for a given server record.
     */
    public function updateConnectionStatus(int $uid, string $status, string $error = ''): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'connection_status' => $status,
            'connection_checked' => time(),
            'connection_error' => $error,
        ], ['uid' => $uid]);
    }
}
