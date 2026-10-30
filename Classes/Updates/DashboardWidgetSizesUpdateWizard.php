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
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Give every dashboard widget of this extension its registered size on
 * dashboards arranged before the size changed.
 *
 * 0.27 registered new sizes for the assistant widgets: "Check website", for
 * one, is of medium height now, and a dashboard arranged before keeps it one
 * row high, so its second row is cut off. The dashboard applies the
 * registered size only to a widget without a stored position:
 * be_dashboards.widgets keeps, per widget, `positions` with one
 * {x, y, width, height} per column count (4, 2 or 1, from the width of the
 * dashboard), and a stored entry keeps its width and height. Users cannot
 * resize a widget.
 *
 * The registered size comes from the widget registry, so a widget that
 * changes its size later needs no new wizard. dashboard.js turns the
 * registration into grid cells in initializeCurrentDashboard(): width small,
 * medium, large is 1, 2, 4 columns, at most as many as the dashboard has;
 * height small, medium, large is 1, 2, 3 rows; any other value is 1
 * (Build/Sources/TypeScript/dashboard/dashboard.ts, lines 1020-1021 and 1030
 * in 13.4.37, lines 971-972 and 981 in 14.3.10; the shipped dashboard.js
 * holds the same maps).
 *
 * The wizard removes the stored position of such a widget for every column
 * count in which it is narrower or lower than the registration allows. The
 * dashboard then places it as it places a newly added widget, in the
 * registered size: the other widgets keep their positions, and this one goes
 * to the first rows that are free across the full width
 * (initializeCurrentDashboard() and widgetPositionsArrange() in dashboard.js),
 * so it usually moves below the widgets it shared its rows with. Widgets of
 * other extensions, identifiers the registry does not know, the settings of a
 * widget and positions that already have the size are left as they are. It
 * decodes to objects, so an empty `{}` elsewhere in the field stays one.
 *
 * This covers the case of nrMcpAgent_recentChatsWidgetSize ("Recent chats",
 * large and large since 0.26) too. That wizard stays registered, because
 * installations may have run it; whichever runs first leaves the other
 * nothing to do.
 *
 * Without EXT:dashboard there is no registry and no be_dashboards table, so
 * nothing is necessary. Necessity and update share one predicate, so the
 * wizard reports done exactly when nothing is left to change, and running it
 * again changes nothing.
 */
#[UpgradeWizard('nrMcpAgent_dashboardWidgetSizes')]
final readonly class DashboardWidgetSizesUpdateWizard implements UpgradeWizardInterface
{
    private const TABLE = 'be_dashboards';

    /** Every widget this extension registers (Configuration/Services.php) starts with it. */
    private const PREFIX = 'nrMcpAgent';

    /** Columns per registered width, as dashboard.js maps them. */
    private const WIDTHS = ['small' => 1, 'medium' => 2, 'large' => 4];

    /** Rows per registered height, as dashboard.js maps them. */
    private const HEIGHTS = ['small' => 1, 'medium' => 2, 'large' => 3];

    public function __construct(
        private ConnectionPool $connectionPool,
        // Null without EXT:dashboard: the class does not exist then.
        private ?WidgetRegistry $widgetRegistry = null,
    ) {}

    public function getTitle(): string
    {
        return 'Show the AI assistant dashboard widgets in their registered size';
    }

    public function getDescription(): string
    {
        return 'Dashboards arranged before a widget of nr_mcp_agent changed its size keep the old size, '
            . 'because the dashboard stores the size of every widget it has placed and offers no way to resize it; '
            . 'since 0.27 "Check website", for one, is cut off after its first row. '
            . 'This wizard removes the stored position of every widget of this extension where it is smaller than its registered size, '
            . 'so the dashboard places it again, in its registered size, in the first rows that are free across the whole width: '
            . 'it usually moves below the widgets that shared its rows. Other widgets keep their size and position.';
    }

    public function updateNecessary(): bool
    {
        $sizes = $this->registeredSizes();
        if ($sizes === []) {
            return false;
        }

        foreach ($this->dashboards() as $widgets) {
            if ($this->shrink($widgets, $sizes) !== null) {
                return true;
            }
        }

        return false;
    }

    public function executeUpdate(): bool
    {
        $sizes = $this->registeredSizes();
        if ($sizes === []) {
            return true;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach ($this->dashboards() as $uid => $widgets) {
            $updated = $this->shrink($widgets, $sizes);
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
     * The registered size in grid cells, [columns, rows], of every widget of
     * this extension. All widgets, not the available ones: availability
     * depends on the backend user, and the wizard runs without one.
     *
     * @return array<string, array{int, int}>
     */
    private function registeredSizes(): array
    {
        if (!$this->widgetRegistry instanceof WidgetRegistry) {
            return [];
        }

        $sizes = [];
        foreach ($this->widgetRegistry->getAllWidgets() as $configuration) {
            $identifier = $configuration->getIdentifier();
            if (str_starts_with($identifier, self::PREFIX)) {
                $sizes[$identifier] = [
                    self::WIDTHS[$configuration->getWidth()] ?? 1,
                    self::HEIGHTS[$configuration->getHeight()] ?? 1,
                ];
            }
        }

        return $sizes;
    }

    /**
     * The `widgets` field of every dashboard that holds a widget of this
     * extension. No restriction: the check must see exactly what the update
     * touches, and a deleted dashboard that is restored should show the
     * widgets right too.
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
                $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards('"' . self::PREFIX) . '%'),
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
     *
     * @param array<string, array{int, int}> $sizes
     */
    private function shrink(string $widgets, array $sizes): ?string
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
            if (!$entry instanceof stdClass || !is_string($entry->identifier ?? null) || !isset($sizes[$entry->identifier]) || !isset($entry->positions) || !$entry->positions instanceof stdClass) {
                continue;
            }

            [$width, $height] = $sizes[$entry->identifier];
            foreach (get_object_vars($entry->positions) as $columns => $position) {
                if ($this->tooSmall((int) $columns, $position, $width, $height)) {
                    unset($entry->positions->{$columns});
                    $changed = true;
                }
            }
        }

        return $changed ? json_encode($config, JSON_THROW_ON_ERROR) : null;
    }

    private function tooSmall(int $columns, mixed $position, int $width, int $height): bool
    {
        if (!$position instanceof stdClass || !is_numeric($position->width ?? null) || !is_numeric($position->height ?? null)) {
            return false;
        }

        return (int) $position->width < min($width, max(1, $columns)) || (int) $position->height < $height;
    }
}
