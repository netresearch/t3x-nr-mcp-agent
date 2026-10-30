<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Updates;

use JsonException;
use stdClass;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Give the "Recent chats" widget its new size on dashboards arranged before.
 *
 * Up to 0.25 the widget was registered small and of medium height; since
 * 0.26 it is large and large, so a row has room for title, context, status
 * and time (ADR-027). The dashboard applies the registered size only to a
 * widget without a stored position: be_dashboards.widgets keeps, per widget,
 * `positions` with one {x, y, width, height} per column count (4, 2 or 1,
 * from the width of the dashboard; dashboard.js in 13.4 and 14.3), and a
 * stored entry keeps its width and height. Users cannot resize a widget, so
 * a dashboard arranged before 0.26 shows it one column wide for good.
 *
 * The wizard removes the stored position of this widget for every column
 * count in which it is narrower or lower than the registration allows. The
 * dashboard then places it as it places a newly added widget, in the
 * registered size: the other widgets keep their positions, and this one goes
 * to the first rows that are free across the full width
 * (initializeCurrentDashboard() and widgetPositionsArrange() in dashboard.js),
 * so it usually moves below the widgets it shared its rows with. A place of
 * its own choosing would need a stored position, which is what the dashboard
 * keeps the old size in. Other widgets, the widget's settings and its
 * positions that already have the size are left as they are. It decodes to objects, so
 * an empty `{}` elsewhere in the field stays one.
 *
 * Necessity and update share one predicate, so the wizard reports done
 * exactly when nothing is left to change, and running it again changes
 * nothing.
 */
#[UpgradeWizard('nrMcpAgent_recentChatsWidgetSize')]
final readonly class RecentChatsWidgetSizeUpdateWizard implements UpgradeWizardInterface
{
    private const TABLE = 'be_dashboards';

    private const WIDGET = 'nrMcpAgentAiChat';

    /** The registered size in grid cells: `width: large` (4 columns, at most as many as the dashboard has) and `height: large`. */
    private const WIDTH = 4;

    private const HEIGHT = 3;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'Show the "Recent chats" dashboard widget in its new size';
    }

    public function getDescription(): string
    {
        return 'Dashboards arranged before nr_mcp_agent 0.26 keep the "Recent chats" widget one column wide, '
            . 'because the dashboard stores the size of every widget it has placed and offers no way to resize it. '
            . 'This wizard removes the stored position of that widget where it is smaller than its registered size, '
            . 'so the dashboard places it again, full width, in the first rows that are free across the whole width: '
            . 'it usually moves below the widgets that shared its rows. Other widgets keep their size and position.';
    }

    public function updateNecessary(): bool
    {
        foreach ($this->dashboards() as $widgets) {
            if ($this->shrink($widgets) !== null) {
                return true;
            }
        }

        return false;
    }

    public function executeUpdate(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach ($this->dashboards() as $uid => $widgets) {
            $updated = $this->shrink($widgets);
            if ($updated !== null) {
                $connection->update(self::TABLE, ['widgets' => $updated], ['uid' => $uid]);
            }
        }

        return true;
    }

    /**
     * @return array<int, class-string>
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    /**
     * The `widgets` field of every dashboard that holds the widget. No
     * restriction: the check must see exactly what the update touches, and a
     * deleted dashboard that is restored should show the widget right too.
     *
     * @return iterable<int, string>
     */
    private function dashboards(): iterable
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder
            ->select('uid', 'widgets')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->like(
                'widgets',
                $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards('"' . self::WIDGET . '"') . '%'),
            ))
            ->executeQuery();

        while (($row = $result->fetchAssociative()) !== false) {
            if (is_numeric($row['uid'] ?? null) && is_string($row['widgets'] ?? null)) {
                yield (int) $row['uid'] => $row['widgets'];
            }
        }
    }

    /**
     * The field without the stored positions that are too small, or null when
     * it has none (or is not the JSON the dashboard writes).
     */
    private function shrink(string $widgets): ?string
    {
        try {
            $config = json_decode($widgets, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$config instanceof stdClass) {
            return null;
        }

        $changed = false;
        foreach (get_object_vars($config) as $entry) {
            if (!$entry instanceof stdClass || ($entry->identifier ?? null) !== self::WIDGET || !isset($entry->positions) || !$entry->positions instanceof stdClass) {
                continue;
            }

            foreach (get_object_vars($entry->positions) as $columns => $position) {
                if ($this->tooSmall((int) $columns, $position)) {
                    unset($entry->positions->{$columns});
                    $changed = true;
                }
            }
        }

        return $changed ? json_encode($config, JSON_THROW_ON_ERROR) : null;
    }

    private function tooSmall(int $columns, mixed $position): bool
    {
        if (!$position instanceof stdClass || !is_numeric($position->width ?? null) || !is_numeric($position->height ?? null)) {
            return false;
        }

        return (int) $position->width < min(self::WIDTH, max(1, $columns)) || (int) $position->height < self::HEIGHT;
    }
}
