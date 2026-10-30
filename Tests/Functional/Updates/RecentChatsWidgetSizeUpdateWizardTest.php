<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Updates;

use Netresearch\NrMcpAgent\Updates\RecentChatsWidgetSizeUpdateWizard;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The stored dashboards are written as the dashboard writes them
 * (DashboardAjaxController::saveWidgetPositions() in 13.4 and 14.3): per
 * widget hash the identifier and, per column count, {x, y, width, height}.
 * The wizard is built by hand, as in ClearStoredApprovalNoticeUpdateWizardTest.
 */
class RecentChatsWidgetSizeUpdateWizardTest extends FunctionalTestCase
{
    private const TABLE = 'be_dashboards';

    /** A dashboard arranged under 0.25, when the widget was small and of medium height. */
    private const ARRANGED_BEFORE = '{"a1":{"identifier":"nrMcpAgentPrompt","positions":{"4":{"x":1,"y":0,"width":1,"height":1},"1":{"x":0,"y":2,"width":1,"height":1}},"settings":{}},'
        . '"b2":{"identifier":"nrMcpAgentAiChat","positions":{"4":{"x":0,"y":0,"width":1,"height":2},"2":{"x":0,"y":0,"width":1,"height":2},"1":{"x":0,"y":0,"width":1,"height":2}}}}';

    protected array $coreExtensionsToLoad = ['filelist', 'dashboard'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private RecentChatsWidgetSizeUpdateWizard $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new RecentChatsWidgetSizeUpdateWizard($this->get(ConnectionPool::class));
    }

    #[Test]
    public function nothingIsNecessaryWithoutDashboards(): void
    {
        self::assertFalse($this->subject->updateNecessary());
    }

    #[Test]
    public function removesTheTooSmallPositionsOfTheWidgetAndNothingElse(): void
    {
        $uid = $this->insertDashboard(self::ARRANGED_BEFORE);

        self::assertTrue($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());

        $widgets = $this->widgets($uid);
        self::assertSame(
            '{"a1":{"identifier":"nrMcpAgentPrompt","positions":{"4":{"x":1,"y":0,"width":1,"height":1},"1":{"x":0,"y":2,"width":1,"height":1}},"settings":{}},'
            . '"b2":{"identifier":"nrMcpAgentAiChat","positions":{}}}',
            $widgets,
        );
        self::assertFalse($this->subject->updateNecessary());

        // Run again: nothing changes.
        self::assertTrue($this->subject->executeUpdate());
        self::assertSame($widgets, $this->widgets($uid));
    }

    #[Test]
    public function keepsAPositionThatAlreadyHasTheRegisteredSize(): void
    {
        // Placed since 0.26: full width in four and in two columns, one column
        // where the dashboard has one; three rows high.
        $placed = '{"b2":{"identifier":"nrMcpAgentAiChat","positions":{"4":{"x":0,"y":1,"width":4,"height":3},"2":{"x":0,"y":1,"width":2,"height":3},"1":{"x":0,"y":1,"width":1,"height":3}}}}';
        $uid    = $this->insertDashboard($placed);

        self::assertFalse($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());
        self::assertSame($placed, $this->widgets($uid));
    }

    #[Test]
    public function removesOnlyTheColumnCountInWhichTheWidgetIsTooSmall(): void
    {
        $uid = $this->insertDashboard('{"b2":{"identifier":"nrMcpAgentAiChat","positions":{"4":{"x":0,"y":0,"width":2,"height":3},"1":{"x":0,"y":0,"width":1,"height":3}}}}');

        self::assertTrue($this->subject->updateNecessary());
        $this->subject->executeUpdate();

        self::assertSame('{"b2":{"identifier":"nrMcpAgentAiChat","positions":{"1":{"x":0,"y":0,"width":1,"height":3}}}}', $this->widgets($uid));
    }

    #[Test]
    public function leavesDashboardsWithoutTheWidgetOrWithoutPositionsAlone(): void
    {
        $other    = '{"x9":{"identifier":"sysLogErrors","positions":{"4":{"x":0,"y":0,"width":1,"height":1}}}}';
        $unplaced = '{"b2":{"identifier":"nrMcpAgentAiChat"}}';
        $broken   = '{"b2":{"identifier":"nrMcpAgentAiChat",';
        $uids     = [$this->insertDashboard($other), $this->insertDashboard($unplaced), $this->insertDashboard($broken)];

        self::assertFalse($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());

        self::assertSame([$other, $unplaced, $broken], array_map($this->widgets(...), $uids));
    }

    #[Test]
    public function coversADeletedDashboardToo(): void
    {
        $uid = $this->insertDashboard(self::ARRANGED_BEFORE, deleted: 1);

        self::assertTrue($this->subject->updateNecessary());
        $this->subject->executeUpdate();

        self::assertStringContainsString('"b2":{"identifier":"nrMcpAgentAiChat","positions":{}}', $this->widgets($uid));
        self::assertFalse($this->subject->updateNecessary());
    }

    private function insertDashboard(string $widgets, int $deleted = 0): int
    {
        $conn = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $conn->insert(self::TABLE, [
            'pid'        => 0,
            'identifier' => 'dashboard-' . random_int(1, PHP_INT_MAX),
            'cruser_id'  => 1,
            'title'      => 'My dashboard',
            'widgets'    => $widgets,
            'deleted'    => $deleted,
        ]);

        return (int) $conn->lastInsertId();
    }

    private function widgets(int $uid): string
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $value = $qb->select('widgets')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($value);

        return $value;
    }
}
