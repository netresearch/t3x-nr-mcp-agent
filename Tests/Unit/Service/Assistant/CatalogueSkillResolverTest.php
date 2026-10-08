<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant;

use Netresearch\NrMcpAgent\Domain\Repository\SkillTrustRepository;
use Netresearch\NrMcpAgent\Service\Assistant\CatalogueSkillResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A configured skill resolves to the identifier the chat's skill catalogue
 * accepts, or to nothing where the chat would refuse it.
 */
final class CatalogueSkillResolverTest extends TestCase
{
    /**
     * A stand-in for the chat's SkillCatalogueInterface, which counts how
     * often the catalogue is read.
     *
     * @param list<mixed> $entries
     */
    private function catalogue(array $entries, bool $available = true, bool $secondApprover = false): object
    {
        return new class ($entries, $available, $secondApprover) {
            public int $reads = 0;

            /** @param list<mixed> $entries */
            public function __construct(private readonly array $entries, private readonly bool $available, private readonly bool $secondApprover) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function requiresSecondApprover(): bool
            {
                return $this->secondApprover;
            }

            /** @return list<mixed> */
            public function catalogue(): array
            {
                $this->reads++;

                return $this->entries;
            }
        };
    }

    /**
     * @param array<int, int> $ranks trust rank per skill uid; any other uid ranks 0
     * @param int $minimum the rank a skill needs to instruct a run (0: untrusted)
     */
    private function trust(array $ranks = [], int $minimum = 0): SkillTrustRepository
    {
        $trust = $this->createMock(SkillTrustRepository::class);
        $trust->method('rankByUid')->willReturnCallback(
            static fn(array $uids): array => array_combine($uids, array_map(static fn(int $uid): int => $ranks[$uid] ?? 0, $uids)),
        );
        $trust->method('minimumRank')->willReturn($minimum);

        return $trust;
    }

    /**
     * @param list<mixed> $entries
     * @param array<int, int> $ranks
     */
    private function resolver(array $entries, array $ranks = [], int $minimum = 0): CatalogueSkillResolver
    {
        return new CatalogueSkillResolver($this->trust($ranks, $minimum), $this->catalogue($entries));
    }

    /** A slug whose only sources are below the instruction minimum resolves to nothing: the widget shows its hint. */
    #[Test]
    public function aSlugOnlyBelowTheMinimumTrustDoesNotResolve(): void
    {
        $resolver = $this->resolver(
            [['identifier' => '2:seo-optimieren', 'uid' => 20], ['identifier' => '4:seo-optimieren', 'uid' => 40]],
            [20 => 1, 40 => 0],
            minimum: 2,
        );

        self::assertNull($resolver->resolve('seo-optimieren'));
    }

    /** A source that meets the minimum is offered, even where a lower source uid falls short of it. */
    #[Test]
    public function aSourceMeetingTheMinimumTrustIsOffered(): void
    {
        $resolver = $this->resolver(
            [['identifier' => '2:seo-optimieren', 'uid' => 20], ['identifier' => '7:seo-optimieren', 'uid' => 70]],
            [20 => 1, 70 => 2],
            minimum: 2,
        );

        self::assertSame('7:seo-optimieren', $resolver->resolve('seo-optimieren'));
    }

    /** The minimum binds a configured catalogue identifier as well: it could not instruct a run either. */
    #[Test]
    public function aQualifiedIdentifierBelowTheMinimumTrustDoesNotResolve(): void
    {
        $resolver = $this->resolver(
            [['identifier' => '2:seo-optimieren', 'uid' => 20], ['identifier' => '7:seo-optimieren', 'uid' => 70]],
            [20 => 1, 70 => 3],
            minimum: 2,
        );

        self::assertNull($resolver->resolve('2:seo-optimieren'));
        self::assertSame('7:seo-optimieren', $resolver->resolve('7:seo-optimieren'));
    }

    /** Decision 1: the default slug finds the skill the demo seeds, whatever its source uid. */
    #[Test]
    public function aBareSlugResolvesToTheCatalogueEntryWithThatSlug(): void
    {
        $resolver = $this->resolver([
            ['identifier' => '7:inhalt-verbessern', 'uid' => 2],
            ['identifier' => '7:seo-optimieren', 'uid' => 1],
        ]);

        self::assertSame('7:seo-optimieren', $resolver->resolve('seo-optimieren'));
        self::assertSame('7:inhalt-verbessern', $resolver->resolve('inhalt-verbessern'));
    }

    /** Decision 1: of several sources with the slug, the most trusted wins, even from a higher source uid. */
    #[Test]
    public function theMostTrustedSourceWins(): void
    {
        $resolver = $this->resolver(
            [
                ['identifier' => '2:seo-optimieren', 'uid' => 20],
                ['identifier' => '9:seo-optimieren', 'uid' => 90],
                ['identifier' => '5:seo-optimieren', 'uid' => 50],
            ],
            [20 => 1, 90 => 3, 50 => 2],
        );

        self::assertSame('9:seo-optimieren', $resolver->resolve('seo-optimieren'));
    }

    /** Decision 1: at equal trust, the lowest source uid wins — compared as numbers, not as text. */
    #[Test]
    public function atEqualTrustTheLowestSourceUidWins(): void
    {
        $resolver = $this->resolver(
            [
                ['identifier' => '12:seo-optimieren', 'uid' => 120],
                ['identifier' => '3:seo-optimieren', 'uid' => 30],
                ['identifier' => '10:seo-optimieren', 'uid' => 100],
                ['identifier' => '1:seo-optimieren', 'uid' => 10],
            ],
            [120 => 3, 30 => 3, 100 => 3, 10 => 2],
        );

        self::assertSame('3:seo-optimieren', $resolver->resolve('seo-optimieren'));
    }

    /** Decision 1: a qualified identifier matches exactly, and never falls back to its slug. */
    #[Test]
    public function aQualifiedIdentifierMatchesExactly(): void
    {
        $resolver = $this->resolver(
            [['identifier' => '3:seo-optimieren', 'uid' => 30], ['identifier' => '4:seo-optimieren', 'uid' => 40]],
            [40 => 3],
        );

        self::assertSame('3:seo-optimieren', $resolver->resolve('3:seo-optimieren'));
        self::assertNull($resolver->resolve('5:seo-optimieren'));
    }

    /** The slug is the whole part after `<source uid>:`, not a prefix or a path segment. */
    #[Test]
    public function onlyTheWholePartAfterTheSourceUidCounts(): void
    {
        $resolver = $this->resolver([
            ['identifier' => '3:seo-optimieren-extra', 'uid' => 1],
            ['identifier' => '3:skills/seo-optimieren', 'uid' => 2],
            ['identifier' => 'x:seo-optimieren', 'uid' => 3],
            ['identifier' => 'seo-optimieren', 'uid' => 4],
        ]);

        self::assertNull($resolver->resolve('seo-optimieren'));
    }

    /** The skills do not exist yet: neither default resolves. */
    #[Test]
    public function aSkillMissingFromTheCatalogueDoesNotResolve(): void
    {
        $resolver = $this->resolver([['identifier' => '3:other', 'uid' => 1]]);

        self::assertNull($resolver->resolve('seo-optimieren'));
        self::assertNull($resolver->resolve('inhalt-verbessern'));
        self::assertNull($resolver->resolve(''));
    }

    /** Without the chat's skill catalogue no skill can be started. */
    #[Test]
    public function withoutACatalogueNothingResolves(): void
    {
        self::assertNull((new CatalogueSkillResolver($this->trust()))->resolve('seo-optimieren'));
        self::assertNull((new CatalogueSkillResolver($this->trust(), new stdClass()))->resolve('seo-optimieren'));
    }

    /** An nr-llm that cannot take a skill per run starts none. */
    #[Test]
    public function aCatalogueThatCannotPassSkillsResolvesNothing(): void
    {
        $resolver = new CatalogueSkillResolver($this->trust(), $this->catalogue([['identifier' => '3:seo-optimieren', 'uid' => 1]], available: false));

        self::assertNull($resolver->resolve('seo-optimieren'));
    }

    /** The chat refuses every skill where a second person must approve each change. */
    #[Test]
    public function underFourEyesNothingResolves(): void
    {
        $resolver = new CatalogueSkillResolver($this->trust(), $this->catalogue([['identifier' => '3:seo-optimieren', 'uid' => 1]], secondApprover: true));

        self::assertNull($resolver->resolve('3:seo-optimieren'));
    }

    #[Test]
    public function malformedEntriesAreIgnored(): void
    {
        $resolver = $this->resolver(['3:seo-optimieren', ['identifier' => 7], ['identifier' => ''], [], ['identifier' => '3:ok']]);

        self::assertNull($resolver->resolve('seo-optimieren'));
        self::assertSame('3:ok', $resolver->resolve('ok'));
    }

    /** Each widget asks for several skills; the catalogue is read once. */
    #[Test]
    public function theCatalogueIsReadOnce(): void
    {
        $catalogue = $this->catalogue([['identifier' => '3:a', 'uid' => 1]]);
        $resolver = new CatalogueSkillResolver($this->trust(), $catalogue);

        $resolver->resolve('a');
        $resolver->resolve('b');
        $resolver->resolve('3:a');

        self::assertSame(1, $catalogue->reads);
    }
}
