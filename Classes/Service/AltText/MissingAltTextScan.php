<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

/**
 * The open hits one backend user may fix, in list order (ADR-026): pages
 * first, in page tree order and within a page in the order of its content;
 * then the records per table, by folder and title.
 *
 * Every number the list and the dashboard show comes from here, so the
 * counter, the tabs and the widget agree.
 */
final readonly class MissingAltTextScan
{
    /** The type of hits on pages: content elements and page properties. */
    public const PAGES = 'pages';

    /**
     * @param list<MissingAltText> $hits
     */
    public function __construct(
        public array $hits,
    ) {}

    public function count(): int
    {
        return count($this->hits);
    }

    /**
     * Open hits per type, in list order.
     *
     * @return array<string, int>
     */
    public function countsByType(): array
    {
        $counts = [];
        foreach ($this->hits as $hit) {
            $counts[$hit->type] = ($counts[$hit->type] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<MissingAltText>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->hits, static fn (MissingAltText $hit): bool => $hit->type === $type));
    }

    /** On how many pages hits lie ("auf {n} Seiten"). */
    public function pageCount(): int
    {
        $pages = [];
        foreach ($this->ofType(self::PAGES) as $hit) {
            $pages[$hit->groupUid] = true;
        }

        return count($pages);
    }

    /** In how many records hits lie ("in {n} Datensätzen"); each language version counts. */
    public function recordCount(): int
    {
        $records = [];
        foreach ($this->hits as $hit) {
            if ($hit->type !== self::PAGES) {
                $records[$hit->type . ':' . $hit->groupUid] = true;
            }
        }

        return count($records);
    }
}
