<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Utility;

use Netresearch\NrMcpAgent\Utility\BackendUserInitializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class BackendUserInitializerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function initializeThrowsExceptionWhenUserNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backend user 999 not found');

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1 = 1');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($expressionBuilder);
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($qb);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        GeneralUtility::addInstance(BackendUserAuthentication::class, $backendUser);

        BackendUserInitializer::initialize(999, $connectionPool);
    }

    #[Test]
    public function initializeSetsGlobalBeUserOnSuccess(): void
    {
        $userRecord = [
            'uid' => 7,
            'username' => 'testuser',
            'admin' => 0,
            'deleted' => 0,
            'disable' => 0,
        ];

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')->willReturn($userRecord);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1 = 1');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($expressionBuilder);
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($qb);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->expects(self::once())->method('fetchGroupData');
        GeneralUtility::addInstance(BackendUserAuthentication::class, $backendUser);

        BackendUserInitializer::initialize(7, $connectionPool);

        self::assertSame($backendUser, $GLOBALS['BE_USER']);
        self::assertSame($userRecord, $backendUser->user);
    }
}
