<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Updates;

use Netresearch\NrMcpAgent\Updates\DashboardWidgetSizesUpdateWizard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;

/**
 * The attribute is pinned for the reason ClearStoredApprovalNoticeUpdateWizardTest
 * gives. The functional test always has EXT:dashboard; without it the
 * container passes no widget registry, and there is no be_dashboards table to
 * query either.
 */
class DashboardWidgetSizesUpdateWizardTest extends TestCase
{
    #[Test]
    public function isRegisteredAsAnUpgradeWizardUnderItsIdentifier(): void
    {
        $attributes = (new ReflectionClass(DashboardWidgetSizesUpdateWizard::class))
            ->getAttributes(UpgradeWizard::class, ReflectionAttribute::IS_INSTANCEOF);

        self::assertCount(1, $attributes);

        $attribute = $attributes[0]->newInstance();
        self::assertInstanceOf(UpgradeWizard::class, $attribute);
        self::assertSame('nrMcpAgent_dashboardWidgetSizes', $attribute->identifier);
    }

    #[Test]
    public function touchesNoTableWithoutTheDashboard(): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getQueryBuilderForTable');
        $connectionPool->expects(self::never())->method('getConnectionForTable');

        $subject = new DashboardWidgetSizesUpdateWizard($connectionPool);

        self::assertFalse($subject->updateNecessary());
        self::assertTrue($subject->executeUpdate());
    }
}
