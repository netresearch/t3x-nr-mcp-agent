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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * The meta-description count, with the SEO tour that fixes it.
 */
final class DescriptionTourTest extends TestCase
{
    use SkillLabelsStub;

    /** @var array<string, mixed> the shared cache's entries */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['BE_USER'] = $this->user(7, 0);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function user(int $uid, int $workspace, string $languages = ''): BackendUserAuthentication
    {
        $user                = $this->createMock(BackendUserAuthentication::class);
        $user->user          = ['uid' => $uid];
        $user->workspace     = $workspace;
        $user->userGroupsUID = [1];
        $user->groupData     = ['allowed_languages' => $languages];

        return $user;
    }

    /** One cache shared by every tour of a test, as the widgets' requests share it. */
    private function cache(): FrontendInterface
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->entries[$key] ?? false);
        $cache->method('set')->willReturnCallback(function (string $key, mixed $value, array $tags = []): void {
            self::assertSame([DescriptionTour::CACHE_TAG], $tags);
            $this->entries[$key] = $value;
        });

        return $cache;
    }

    /**
     * @param array<string, string> $labels
     */
    private function tour(MissingDescriptionRepository $repository, ?string $resolved = '3:seo-optimieren', array $labels = ['skill.seo-optimieren.title' => 'SEO optimieren']): DescriptionTour
    {
        $resolver = $this->createMock(SkillResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static fn (string $skill): ?string => $skill === 'seo-optimieren' ? $resolved : null);

        return new DescriptionTour($repository, $this->skillLabels($labels), $resolver, $this->cache());
    }

    #[Test]
    public function theStartCarriesTheResolvedTourAndTheFirstPage(): void
    {
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->method('summary')->willReturn(['count' => 3, 'translations' => 1, 'first' => ['pageUid' => 7, 'languageUid' => 1, 'title' => 'Sieben']]);

        self::assertSame(
            ['count' => 3, 'translations' => 1, 'start' => ['skill' => '3:seo-optimieren', 'pageUid' => 7, 'languageUid' => 1, 'title' => 'Sieben']],
            $this->tour($repository)->summary(),
        );
    }

    #[Test]
    public function noStartWhenNothingIsMissing(): void
    {
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->method('summary')->willReturn(['count' => 0, 'translations' => 0, 'first' => null]);

        self::assertSame(['count' => 0, 'translations' => 0, 'start' => null], $this->tour($repository)->summary());
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

    /**
     * Two widgets on one dashboard ask in requests of their own; the pages
     * are read once, also when nothing could be counted.
     */
    #[Test]
    public function twoRequestsReadThePagesOnce(): void
    {
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->expects(self::once())->method('summary')->willReturn(null);

        self::assertNull($this->tour($repository)->summary());
        self::assertNull($this->tour($repository)->summary());
    }

    /** Another user, workspace or language set is counted on its own. */
    #[Test]
    public function theCountIsKeptPerUserWorkspaceAndLanguages(): void
    {
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->expects(self::exactly(4))->method('summary')->willReturn(['count' => 1, 'translations' => 0, 'first' => null]);

        $this->tour($repository)->summary();
        $GLOBALS['BE_USER'] = $this->user(8, 0);
        $this->tour($repository)->summary();
        $GLOBALS['BE_USER'] = $this->user(8, 1);
        $this->tour($repository)->summary();
        $GLOBALS['BE_USER'] = $this->user(8, 1, '1');
        $this->tour($repository)->summary();
        $this->tour($repository)->summary();
    }

    /** Without a user there is no key: the count is read and not kept. */
    #[Test]
    public function withoutAUserNothingIsKept(): void
    {
        unset($GLOBALS['BE_USER']);
        $repository = $this->createMock(MissingDescriptionRepository::class);
        $repository->expects(self::exactly(2))->method('summary')->willReturn(null);

        $this->tour($repository)->summary();
        $this->tour($repository)->summary();
        self::assertSame([], $this->entries);
    }
}
