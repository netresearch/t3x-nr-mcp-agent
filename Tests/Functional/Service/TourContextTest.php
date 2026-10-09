<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Service\TourContext;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The header's page and language of a guided process (ADR-023), against a
 * real database: the page only when the reader may show it, the language by
 * its name. The fixture's editor may show page 20 ("Über uns"), not page 30.
 */
final class TourContextTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/guided_tools.csv');
    }

    private static function conversation(int $pageUid, int $languageId = -1, string $skill = 'seo-check'): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(3);
        $conversation->setSkillIdentifier($skill, 7);
        $conversation->setViewContext($pageUid, 'web_layout', $languageId);

        return $conversation;
    }

    /** A site whose default language is "Deutsch" and language 1 "English". */
    private function siteFinder(): SiteFinder
    {
        $site = new Site('main', 20, [
            'base' => 'https://example.org/',
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE', 'base' => '/'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_US', 'base' => '/en/'],
            ],
        ]);
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getSiteByPageId')->willReturn($site);

        return $finder;
    }

    #[Test]
    public function theHeaderNamesThePageAndTheLanguageByItsName(): void
    {
        $user = $this->setUpBackendUser(3);

        self::assertSame(['pageTitle' => 'Über uns', 'languageName' => 'English'], (new TourContext($this->siteFinder()))->of(self::conversation(20, 1), $user));
        self::assertSame(['pageTitle' => 'Über uns', 'languageName' => 'Deutsch'], (new TourContext($this->siteFinder()))->of(self::conversation(20), $user), 'no language: the site\'s default');
    }

    #[Test]
    public function aPageOutsideEverySiteHasNoLanguageName(): void
    {
        self::assertSame(['pageTitle' => 'Über uns', 'languageName' => ''], (new TourContext(null))->of(self::conversation(20), $this->setUpBackendUser(3)));
    }

    #[Test]
    public function aPageTheReaderMayNotShowIsNotNamed(): void
    {
        self::assertNull((new TourContext($this->siteFinder()))->of(self::conversation(30), $this->setUpBackendUser(3)));
    }

    #[Test]
    public function withoutASkillOrAPageThereIsNoTour(): void
    {
        $user = $this->setUpBackendUser(3);

        self::assertNull((new TourContext($this->siteFinder()))->of(self::conversation(20, skill: ''), $user));
        self::assertNull((new TourContext($this->siteFinder()))->of(self::conversation(0), $user));
    }
}
