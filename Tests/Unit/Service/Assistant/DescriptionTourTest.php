<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant;

use Netresearch\NrMcpAgent\Domain\Repository\MissingDescriptionRepository;
use Netresearch\NrMcpAgent\Service\Assistant\DescriptionTour;
use Netresearch\NrMcpAgent\Service\Assistant\SkillResolverInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The meta-description count, with the SEO tour that fixes it.
 */
final class DescriptionTourTest extends TestCase
{
    use SkillLabelsStub;

    /**
     * @param array<string, string> $labels
     */
    private function tour(MissingDescriptionRepository $repository, ?string $resolved = '3:seo-optimieren', array $labels = ['skill.seo-optimieren.title' => 'SEO optimieren']): DescriptionTour
    {
        $resolver = $this->createMock(SkillResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static fn (string $skill): ?string => $skill === 'seo-optimieren' ? $resolved : null);

        return new DescriptionTour($repository, $this->skillLabels($labels), $resolver);
    }

    #[Test]
    public function theStartCarriesTheResolvedTourAndTheFirstPage(): void
    {
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->method('summary')->willReturn(['count' => 3, 'first' => ['pageUid' => 7, 'languageUid' => 1, 'title' => 'Sieben']]);

        self::assertSame(
            ['count' => 3, 'start' => ['skill' => '3:seo-optimieren', 'pageUid' => 7, 'languageUid' => 1, 'title' => 'Sieben']],
            $this->tour($repository)->summary(),
        );
    }

    #[Test]
    public function noStartWhenNothingIsMissing(): void
    {
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->method('summary')->willReturn(['count' => 0, 'first' => null]);

        self::assertSame(['count' => 0, 'start' => null], $this->tour($repository)->summary());
    }

    /** Without the tour there is nothing to offer, so nothing is counted. */
    #[Test]
    public function withoutTheTourNothingIsCounted(): void
    {
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->expects(self::never())->method('summary');

        self::assertNull($this->tour($repository, null)->summary());
        self::assertNull($this->tour($repository, '3:seo-optimieren', [])->summary());
    }

    #[Test]
    public function aUserWhoMayNotWriteDescriptionsGetsNothing(): void
    {
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->method('summary')->willReturn(null);

        self::assertNull($this->tour($repository)->summary());
    }

    /** Two widgets on one dashboard ask; the pages are read once. */
    #[Test]
    public function thePagesAreReadOncePerRequest(): void
    {
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->expects(self::once())->method('summary')->willReturn(null);
        $tour = $this->tour($repository);

        self::assertNull($tour->summary());
        self::assertNull($tour->summary());
    }
}
