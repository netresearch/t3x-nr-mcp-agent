<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Repository\McpServerRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

class McpServerRepositoryTest extends TestCase
{
    private function makeQueryBuilder(): QueryBuilder
    {
        $exprBuilder = $this->createMock(ExpressionBuilder::class);
        $exprBuilder->method('eq')->willReturnCallback(
            static fn(string $field, mixed $value): string => $field . ' = ' . $value,
        );

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('expr')->willReturn($exprBuilder);
        $qb->method('createNamedParameter')->willReturnCallback(
            static fn(mixed $value): string => (string) $value,
        );

        return $qb;
    }

    #[Test]
    public function findAllActiveReturnsRowsFromQueryBuilder(): void
    {
        $expectedRows = [
            ['uid' => 1, 'server_key' => 'typo3', 'name' => 'TYPO3 MCP', 'hidden' => 0, 'deleted' => 0],
            ['uid' => 2, 'server_key' => 'custom', 'name' => 'Custom Tools', 'hidden' => 0, 'deleted' => 0],
        ];

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($expectedRows);

        $qb = $this->makeQueryBuilder();
        $qb->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($qb);

        $repo = new McpServerRepository($connectionPool);
        $rows = $repo->findAllActive();

        self::assertSame($expectedRows, $rows);
    }

    #[Test]
    public function findAllActiveReturnsEmptyArrayWhenNoRows(): void
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $qb = $this->makeQueryBuilder();
        $qb->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($qb);

        $repo = new McpServerRepository($connectionPool);

        self::assertSame([], $repo->findAllActive());
    }

    #[Test]
    public function updateConnectionStatusWritesStatusAndTimestamp(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_nrmcpagent_mcp_server',
                self::callback(static function (array $data): bool {
                    return $data['connection_status'] === 'ok'
                        && $data['connection_error'] === ''
                        && is_int($data['connection_checked'])
                        && $data['connection_checked'] > 0;
                }),
                ['uid' => 42],
            );

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $repo = new McpServerRepository($connectionPool);
        $repo->updateConnectionStatus(42, 'ok');
    }

    #[Test]
    public function updateConnectionStatusWritesErrorMessage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_nrmcpagent_mcp_server',
                self::callback(static function (array $data): bool {
                    return $data['connection_status'] === 'error'
                        && $data['connection_error'] === 'Connection refused';
                }),
                ['uid' => 7],
            );

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $repo = new McpServerRepository($connectionPool);
        $repo->updateConnectionStatus(7, 'error', 'Connection refused');
    }
}
