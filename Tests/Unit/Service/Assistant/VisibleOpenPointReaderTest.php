<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant;

use Netresearch\NrMcpAgent\Service\Assistant\OpenPoint;
use Netresearch\NrMcpAgent\Service\Assistant\VisibleOpenPointReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The reader over the chat's store. The store's class does not exist on this
 * branch, so the double has its method, and its points the public properties
 * of `VisibleOpenPoint`.
 */
final class VisibleOpenPointReaderTest extends TestCase
{
    /**
     * A stand-in for `VisibleOpenPoint`: the same nine public properties.
     */
    public static function point(int $pageUid, int $languageUid, string $skillIdentifier, string $summary): object
    {
        return new class ($pageUid, $languageUid, $skillIdentifier, $summary) {
            public int $skillUid = 7;

            public string $targetTable = 'pages';

            public int $targetUid;

            public string $field = 'description';

            public int $crdate = 1;

            public function __construct(
                public readonly int $pageUid,
                public readonly int $languageUid,
                public readonly string $skillIdentifier,
                public readonly string $summary,
            ) {
                $this->targetUid = $pageUid;
            }
        };
    }

    /**
     * A stand-in for `OpenPointVisibility` that returns $points and records the limit asked for.
     *
     * @param list<object> $points
     */
    public static function visibility(array $points): object
    {
        return new class ($points) {
            /** @var list<int> */
            public array $limits = [];

            /** @param list<object> $points */
            public function __construct(private readonly array $points) {}

            /** @return list<object> */
            public function visibleForCurrentUser(int $limit = 20): array
            {
                $this->limits[] = $limit;

                return array_slice($this->points, 0, $limit);
            }
        };
    }

    #[Test]
    public function withoutTheStoreThereAreNoOpenPoints(): void
    {
        self::assertSame([], (new VisibleOpenPointReader())->findOpen(5));
        self::assertSame([], (new VisibleOpenPointReader(null))->findOpen(5));
        self::assertSame([], (new VisibleOpenPointReader(new stdClass()))->findOpen(5));
    }

    /**
     * Page, language and summary as the store has them; the skill as its slug,
     * whether the stored identifier names its source or not.
     */
    #[Test]
    public function theStoresPointsAreReadWithTheSkillsSlug(): void
    {
        $visibility = self::visibility([
            self::point(21, 1, '3:seo-optimieren', 'Beschreibung fehlt auf „Start“'),
            self::point(26, 0, 'inhalt-verbessern', 'Text auf „Über uns“ offen'),
        ]);

        self::assertEquals(
            [
                new OpenPoint(21, 1, 'seo-optimieren', 'Beschreibung fehlt auf „Start“'),
                new OpenPoint(26, 0, 'inhalt-verbessern', 'Text auf „Über uns“ offen'),
            ],
            (new VisibleOpenPointReader($visibility))->findOpen(5),
        );
        self::assertSame([5], $visibility->limits);
    }

    /** A point whose skill is gone is not shown. */
    #[Test]
    public function aPointWhoseSkillIsGoneIsNotShown(): void
    {
        $visibility = self::visibility([
            self::point(21, 0, '', 'Beschreibung fehlt auf „Start“'),
            self::point(26, 0, '3:seo-optimieren', 'Titel auf „Über uns“ offen'),
        ]);

        self::assertEquals(
            [new OpenPoint(26, 0, 'seo-optimieren', 'Titel auf „Über uns“ offen')],
            (new VisibleOpenPointReader($visibility))->findOpen(5),
        );
    }

    #[Test]
    public function aLimitOfZeroAsksTheStoreForNothing(): void
    {
        $visibility = self::visibility([self::point(21, 0, '3:seo-optimieren', 'x')]);

        self::assertSame([], (new VisibleOpenPointReader($visibility))->findOpen(0));
        self::assertSame([], $visibility->limits);
    }
}
