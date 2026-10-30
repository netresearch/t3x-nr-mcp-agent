<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Updates;

use Netresearch\NrMcpAgent\Updates\DashboardWidgetSizesUpdateWizard;
use Netresearch\NrMcpAgent\Updates\RecentChatsWidgetSizeUpdateWizard;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardRegistry;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The stored dashboards are written as the dashboard writes them
 * (DashboardAjaxController::saveWidgetPositions() in 13.4 and 14.3): per
 * widget hash the identifier and, per column count, {x, y, width, height}.
 * The wizard is built with the widget registry of the container, so the
 * registered sizes are those of Configuration/Services.php:
 *
 *  - nrMcpAgentWebsiteCheck: medium and medium, 2 columns and 2 rows
 *  - nrMcpAgentRecommendations: large and medium, 4 columns and 2 rows
 *  - nrMcpAgentAiChat: large and large, 4 columns and 3 rows
 *  - t3information (EXT:dashboard): medium and medium
 *
 * Whether the container injects that registry into the wizard is checked
 * where the test container hands out the upgrade wizard registry: on 14.3.
 */
class DashboardWidgetSizesUpdateWizardTest extends FunctionalTestCase
{
    private const TABLE = 'be_dashboards';

    protected array $coreExtensionsToLoad = ['filelist', 'dashboard'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private DashboardWidgetSizesUpdateWizard $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new DashboardWidgetSizesUpdateWizard($this->get(ConnectionPool::class), $this->get(WidgetRegistry::class));
    }

    #[Test]
    public function nothingIsNecessaryWithoutDashboards(): void
    {
        self::assertFalse($this->subject->updateNecessary());
    }

    /**
     * As the install tool and upgrade:run get the wizard: a registry the
     * container failed to inject would leave it with nothing to do, for
     * good. The 13.4 test container does not hand out EXT:install's wizard
     * registry, so this runs on 14.3 only.
     */
    #[Test]
    public function theWizardRegistryHandsOutTheWizardWithTheWidgetRegistry(): void
    {
        if (!class_exists(UpgradeWizardRegistry::class)) {
            self::markTestSkipped('The 13.4 test container does not hand out the upgrade wizard registry of EXT:install.');
        }

        $subject = $this->get(UpgradeWizardRegistry::class)->getUpgradeWizard('nrMcpAgent_dashboardWidgetSizes');
        self::assertInstanceOf(DashboardWidgetSizesUpdateWizard::class, $subject);

        $this->insertDashboard('{"b2":{"identifier":"nrMcpAgentWebsiteCheck","positions":{"4":{"x":1,"y":0,"width":2,"height":1}}}}');

        self::assertTrue($subject->updateNecessary());
    }

    /**
     * Arranged under 0.26, when "Check website" was small and small: one row
     * high everywhere. Only its height is short at four and two columns.
     */
    #[Test]
    public function removesTheTooLowPositionsOfWebsiteCheckAndNothingElse(): void
    {
        $uid = $this->insertDashboard(
            '{"a1":{"identifier":"t3information","positions":{"4":{"x":0,"y":0,"width":1,"height":1}},"settings":{}},'
            . '"b2":{"identifier":"nrMcpAgentWebsiteCheck","positions":{"4":{"x":1,"y":0,"width":2,"height":1},"2":{"x":0,"y":1,"width":2,"height":1},"1":{"x":0,"y":1,"width":1,"height":1}},"settings":{}}}',
        );

        self::assertTrue($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());

        $widgets = $this->widgets($uid);
        self::assertSame(
            '{"a1":{"identifier":"t3information","positions":{"4":{"x":0,"y":0,"width":1,"height":1}},"settings":{}},'
            . '"b2":{"identifier":"nrMcpAgentWebsiteCheck","positions":{},"settings":{}}}',
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
        // Two columns wide where the dashboard has two or four, one column
        // where it has one; two rows high.
        $placed = '{"b2":{"identifier":"nrMcpAgentWebsiteCheck","positions":{"4":{"x":2,"y":0,"width":2,"height":2},"2":{"x":0,"y":1,"width":2,"height":2},"1":{"x":0,"y":1,"width":1,"height":2}}}}';
        $uid    = $this->insertDashboard($placed);

        self::assertFalse($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());
        self::assertSame($placed, $this->widgets($uid));
    }

    /**
     * "Recommended for your website" became four columns wide in 0.27. Two
     * columns are too narrow where the dashboard has four, and the full width
     * where it has two or one.
     */
    #[Test]
    public function removesOnlyTheColumnCountInWhichTheWidgetIsTooNarrow(): void
    {
        $uid = $this->insertDashboard(
            '{"c3":{"identifier":"nrMcpAgentRecommendations","positions":{"4":{"x":0,"y":2,"width":2,"height":2},"2":{"x":0,"y":2,"width":2,"height":2},"1":{"x":0,"y":2,"width":1,"height":2}}}}',
        );

        self::assertTrue($this->subject->updateNecessary());
        $this->subject->executeUpdate();

        self::assertSame(
            '{"c3":{"identifier":"nrMcpAgentRecommendations","positions":{"2":{"x":0,"y":2,"width":2,"height":2},"1":{"x":0,"y":2,"width":1,"height":2}}}}',
            $this->widgets($uid),
        );
    }

    /** The case of nrMcpAgent_recentChatsWidgetSize: after this wizard, that one has nothing left to do. */
    #[Test]
    public function coversTheRecentChatsWidgetToo(): void
    {
        $uid = $this->insertDashboard(
            '{"d4":{"identifier":"nrMcpAgentAiChat","positions":{"4":{"x":0,"y":0,"width":1,"height":2},"2":{"x":0,"y":0,"width":1,"height":2},"1":{"x":0,"y":0,"width":1,"height":2}}}}',
        );

        $this->subject->executeUpdate();

        self::assertSame('{"d4":{"identifier":"nrMcpAgentAiChat","positions":{}}}', $this->widgets($uid));
        self::assertFalse((new RecentChatsWidgetSizeUpdateWizard($this->get(ConnectionPool::class)))->updateNecessary());
    }

    /**
     * A widget the registry does not know (removed from the extension, or
     * from an extension not loaded) has no registered size to compare with.
     */
    #[Test]
    public function leavesAWidgetTheRegistryDoesNotKnowAlone(): void
    {
        $retired = '{"e5":{"identifier":"nrMcpAgentRetired","positions":{"4":{"x":0,"y":0,"width":1,"height":1}}}}';
        $uid     = $this->insertDashboard($retired);

        self::assertFalse($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());
        self::assertSame($retired, $this->widgets($uid));
    }

    /**
     * dashboard.js gives a size it does not know one cell, so a widget
     * stored one cell large is in its registered size. The registry here is
     * a stub: no widget of the extension is registered with such a size.
     */
    #[Test]
    public function takesASizeTheDashboardDoesNotKnowAsOneCell(): void
    {
        $configuration = $this->createStub(WidgetConfigurationInterface::class);
        $configuration->method('getIdentifier')->willReturn('nrMcpAgentOdd');
        $configuration->method('getWidth')->willReturn('wide');
        $configuration->method('getHeight')->willReturn('tall');
        $registry = $this->createStub(WidgetRegistry::class);
        $registry->method('getAllWidgets')->willReturn(['nrMcpAgentOdd' => $configuration]);
        $subject = new DashboardWidgetSizesUpdateWizard($this->get(ConnectionPool::class), $registry);

        $placed = '{"f6":{"identifier":"nrMcpAgentOdd","positions":{"4":{"x":0,"y":0,"width":1,"height":1}}}}';
        $uid    = $this->insertDashboard($placed);

        self::assertFalse($subject->updateNecessary());
        self::assertTrue($subject->executeUpdate());
        self::assertSame($placed, $this->widgets($uid));
    }

    #[Test]
    public function leavesDashboardsWithoutAWidgetOfTheExtensionOrWithoutPositionsAlone(): void
    {
        $other    = '{"x9":{"identifier":"t3information","positions":{"4":{"x":0,"y":0,"width":1,"height":1}}}}';
        $unplaced = '{"b2":{"identifier":"nrMcpAgentWebsiteCheck"}}';
        $list     = '{"b2":{"identifier":"nrMcpAgentWebsiteCheck","positions":[]}}';
        $broken   = '{"b2":{"identifier":"nrMcpAgentWebsiteCheck",';
        $empty    = '[]';
        $array    = '[{"identifier":"nrMcpAgentWebsiteCheck","positions":{"4":{"x":0,"y":0,"width":1,"height":1}}}]';
        $uids     = array_map($this->insertDashboard(...), [$other, $unplaced, $list, $broken, $empty, $array]);

        self::assertFalse($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());

        self::assertSame([$other, $unplaced, $list, $broken, $empty, $array], array_map($this->widgets(...), $uids));
    }

    #[Test]
    public function coversADeletedDashboardToo(): void
    {
        $uid = $this->insertDashboard(
            '{"b2":{"identifier":"nrMcpAgentWebsiteCheck","positions":{"4":{"x":1,"y":0,"width":2,"height":1}}}}',
            deleted: 1,
        );

        self::assertTrue($this->subject->updateNecessary());
        $this->subject->executeUpdate();

        self::assertSame('{"b2":{"identifier":"nrMcpAgentWebsiteCheck","positions":{}}}', $this->widgets($uid));
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
