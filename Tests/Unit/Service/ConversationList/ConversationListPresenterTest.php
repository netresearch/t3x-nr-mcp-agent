<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\ConversationList;

use DateTimeImmutable;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\ConversationList\ConversationListItem;
use Netresearch\NrMcpAgent\Service\ConversationList\ConversationListPresenter;
use Netresearch\NrMcpAgent\Service\ProcessRunDetectorInterface;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locales;

/**
 * What every list of conversations shows about one (ADR-027).
 */
final class ConversationListPresenterTest extends TestCase
{
    private const LL = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:';

    /** 2026-10-24 10:42 UTC, a Saturday. */
    private const NOW = '2026-10-24T10:42:00+00:00';

    /** The labels as the German and the English language file have them. */
    private const LABELS = [
        'de' => [
            self::LL . 'guided.checkPage'                     => 'Diese Seite prüfen',
            self::LL . 'widget.context.page'                  => 'Seite „%s“',
            self::LL . 'widget.context.pageGone'              => 'Gelöschte Seite',
            self::LL . 'widget.context.guidedTour'            => 'Guided Tour',
            self::LL . 'guided.progress'                      => 'Punkt %d von %d',
            self::LL . 'widget.time.today'                    => 'Heute, %s',
            self::LL . 'widget.time.yesterday'                => 'Gestern, %s',
            self::LL . 'status.idle'                          => 'Bereit',
            self::LL . 'status.processing'                    => 'Arbeitet',
            self::LL . 'status.awaitingApproval'              => 'Wartet auf deine Freigabe',
            self::LL . 'status.awaitingInput'                 => 'Wartet auf deine Antwort',
            self::LL . 'status.failed'                        => 'Fehlgeschlagen',
            self::LL . 'status.unknown'                       => 'Status unbekannt',
            SkillLabels::FILE . ':skill.seo-optimieren.title' => 'SEO optimieren',
        ],
        'fr' => [
            self::LL . 'guided.checkPage' => 'Vérifier cette page',
        ],
        'it' => [
            self::LL . 'guided.checkPage'                     => 'Controlla questa pagina',
            SkillLabels::FILE . ':skill.seo-optimieren.title' => 'Ottimizzare SEO',
        ],
        'default' => [
            self::LL . 'guided.checkPage'                     => 'Check this page',
            self::LL . 'widget.context.page'                  => 'Page “%s”',
            self::LL . 'widget.context.guidedTour'            => 'Guided tour',
            self::LL . 'widget.time.today'                    => 'Today, %s',
            self::LL . 'widget.time.yesterday'                => 'Yesterday, %s',
            self::LL . 'status.idle'                          => 'Ready',
            SkillLabels::FILE . ':skill.seo-optimieren.title' => 'SEO optimisation',
        ],
    ];

    private string $timezone = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function languageService(string $language): LanguageService
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(static fn (string $key): string => self::LABELS[$language][$key] ?? '');

        return $languageService;
    }

    /**
     * @param array<int, array{uid: int, title: string}> $accessiblePages
     * @param list<int>                                  $gonePages
     * @param array<int, int>                            $lastMessageTimes
     * @param array<string, string>                      $translatedTitles "<uid>:<language>" => title
     */
    private function presenter(
        array $accessiblePages = [],
        array $gonePages = [],
        bool $processRun = true,
        array $lastMessageTimes = [],
        string $userLanguage = 'de',
        ?SkillCatalogueInterface $skills = null,
        ?PageChoiceRepository $pages = null,
        ?ConversationRepository $repository = null,
        array $translatedTitles = [],
    ): ConversationListPresenter {
        if (!$repository instanceof ConversationRepository) {
            $repository = $this->createStub(ConversationRepository::class);
            $repository->method('findLastMessageTimes')->willReturn($lastMessageTimes);
        }

        if (!$pages instanceof PageChoiceRepository) {
            $pages = $this->createStub(PageChoiceRepository::class);
            $pages->method('findAccessibleTitles')->willReturnCallback(static function (array $languages) use ($accessiblePages, $translatedTitles): array {
                $found = [];
                foreach ($languages as $uid => $language) {
                    if (isset($accessiblePages[$uid])) {
                        $found[$uid] = ['uid' => $uid, 'title' => $translatedTitles[$uid . ':' . $language] ?? $accessiblePages[$uid]['title']];
                    }
                }

                return $found;
            });
            $pages->method('isGone')->willReturnCallback(static fn (int $uid): bool => in_array($uid, $gonePages, true));
        }

        $processRuns = $this->createStub(ProcessRunDetectorInterface::class);
        $processRuns->method('isProcessRun')->willReturn($processRun);

        $factory = $this->createStub(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturn($this->languageService($userLanguage));
        $factory->method('create')->willReturnCallback(fn (string $language): LanguageService => $this->languageService($language));

        $context = new Context();
        $context->setAspect('date', new DateTimeAspect(new DateTimeImmutable(self::NOW)));

        return new ConversationListPresenter($repository, $pages, new SkillLabels($factory), $processRuns, $factory, new Locales(), $context, $skills);
    }

    private function user(string $lang = 'de'): BackendUserAuthentication
    {
        $user               = $this->createStub(BackendUserAuthentication::class);
        $user->user         = ['uid' => 3, 'usergroup' => '', 'lang' => $lang];
        $GLOBALS['BE_USER'] = $user;

        return $user;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function conversation(int $uid, array $row = []): Conversation
    {
        return Conversation::fromRow(['uid' => $uid, 'be_user' => 3, 'title' => 'Chat ' . $uid, 'message_count' => 2, 'tstamp' => strtotime(self::NOW) - 60, ...$row]);
    }

    /** A guided tour on page 12, started the way tours started before ADR-027. */
    private function tour(int $uid, string $title = 'Diese Seite prüfen'): Conversation
    {
        $conversation = $this->conversation($uid, ['title' => $title]);
        $conversation->setViewContext(12, 'web_layout', 1);
        $conversation->setSkillIdentifier('seo-optimieren', 4);

        return $conversation;
    }

    private function withGuidedState(Conversation $conversation, int $current, int $total, bool $completed = false): Conversation
    {
        $conversation->setGuidedState(['label' => 'SEO', 'current' => $current, 'total' => $total, 'completed' => $completed], null);

        return $conversation;
    }

    /**
     * @param array<int, array{uid: int, title: string}> $accessiblePages
     */
    private function one(Conversation $conversation, array $accessiblePages = [], bool $processRun = true, ?SkillCatalogueInterface $skills = null): ConversationListItem
    {
        $items = $this->presenter($accessiblePages, processRun: $processRun, skills: $skills)->present([$conversation], $this->user());
        self::assertCount(1, $items);

        return $items[0];
    }

    /**
     * A catalogue whose one skill declares a page need (ADR-025).
     */
    private function catalogueDeclaring(string $page, bool $process): SkillCatalogueInterface
    {
        $skills = $this->createStub(SkillCatalogueInterface::class);
        $skills->method('find')->willReturn(['identifier' => 'seo-optimieren', 'name' => 'SEO', 'description' => '', 'uid' => 4, 'process' => $process, 'page' => $page]);

        return $skills;
    }

    // ── Titles ───────────────────────────────────────────────────────────

    /**
     * Twenty tours on the demo were all called "Diese Seite prüfen". Each is
     * now named by its skill and its page, without a migration, whatever
     * language it was started in.
     *
     * @return iterable<string, array{string}>
     */
    public static function startMessages(): iterable
    {
        yield 'started in German' => ['Diese Seite prüfen'];
        yield 'started in English' => ['Check this page'];
        yield 'with stray whitespace' => ["  Diese  Seite\nprüfen "];
        yield 'not titled yet' => [''];
    }

    #[Test]
    #[DataProvider('startMessages')]
    public function aTourTitledByItsStartMessageIsNamedByItsSkillAndPage(string $title): void
    {
        $item = $this->one($this->tour(1, $title), [12 => ['uid' => 12, 'title' => 'Über uns']]);

        self::assertSame('SEO optimieren · Über uns', $item->displayTitle);
        self::assertSame('SEO optimieren · Über uns', $item->fullTitle);
        self::assertSame('tour', $item->titleKind);
        self::assertSame($title, $item->title);
    }

    /** Two tours of one skill on two pages no longer look alike. */
    #[Test]
    public function toursOnDifferentPagesHaveDifferentTitles(): void
    {
        $first  = $this->tour(1);
        $second = $this->tour(2);
        $second->setViewContext(13, 'web_layout', 0);

        $items = $this->presenter([12 => ['uid' => 12, 'title' => 'Über uns'], 13 => ['uid' => 13, 'title' => 'Kontakt']])->present([$first, $second], $this->user());

        self::assertEqualsCanonicalizing(['SEO optimieren · Über uns', 'SEO optimieren · Kontakt'], array_map(static fn (ConversationListItem $i): string => $i->displayTitle, $items));
    }

    /** A new tour stores "<skill> · <page>", marked as the tour's; the page's current title is shown. */
    #[Test]
    public function aTourWithTheStoredTourTitleFollowsAPageRename(): void
    {
        $conversation = $this->tour(1, 'SEO optimieren · Alter Titel');
        $conversation->setTitleSource(Conversation::TITLE_TOUR);

        $item = $this->one($conversation, [12 => ['uid' => 12, 'title' => 'Neuer Titel']]);

        self::assertSame('SEO optimieren · Neuer Titel', $item->displayTitle);
        self::assertSame('tour', $item->titleKind);
    }

    /**
     * The rename field starts with the derived title. What the editor adds to
     * it is theirs: marked as renamed, it is never derived again (the review
     * of #281 reverted exactly this by a prefix match).
     */
    #[Test]
    public function aRenamedTourThatBeginsWithTheDerivedTitleKeepsTheAddition(): void
    {
        $conversation = $this->tour(1, 'SEO optimieren · Über uns (Kunde X)');
        $conversation->setTitleSource(Conversation::TITLE_MANUAL);

        $item = $this->one($conversation, [12 => ['uid' => 12, 'title' => 'Über uns']]);

        self::assertSame('SEO optimieren · Über uns (Kunde X)', $item->displayTitle);
        self::assertSame('own', $item->titleKind);
    }

    /** A renamed title stays the editor's even when it is exactly the derived one. */
    #[Test]
    public function aRenamedTourEqualToTheDerivedTitleStaysTheEditors(): void
    {
        $conversation = $this->tour(1, 'SEO optimieren · Über uns');
        $conversation->setTitleSource(Conversation::TITLE_MANUAL);

        $item = $this->one($conversation, [12 => ['uid' => 12, 'title' => 'Über uns']]);

        self::assertSame('SEO optimieren · Über uns', $item->displayTitle);
        self::assertSame('own', $item->titleKind);
    }

    /**
     * A row from before the mark: automatic only when exactly a title the
     * extension gives, never by its beginning.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unmarkedTitles(): iterable
    {
        yield 'the derived title' => ['SEO optimieren · Über uns', 'tour'];
        yield 'the skill alone' => ['SEO optimieren', 'tour'];
        yield 'the derived title with an addition' => ['SEO optimieren · Über uns (Kunde X)', 'own'];
        yield 'the derived title of an old page title' => ['SEO optimieren · Alter Titel', 'own'];
    }

    #[Test]
    #[DataProvider('unmarkedTitles')]
    public function anUnmarkedTourTitleIsAutomaticOnlyWhenExact(string $title, string $kind): void
    {
        self::assertSame($kind, $this->one($this->tour(1, $title), [12 => ['uid' => 12, 'title' => 'Über uns']])->titleKind);
    }

    /** A title another tour in another language would start with is recognised too: installed packs, the user's own. */
    #[Test]
    public function aTourStartedInAnInstalledLanguageIsRecognised(): void
    {
        $previous                                                            = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['lang']['availableLanguages'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['lang']['availableLanguages'] = ['fr'];

        try {
            $item = $this->one($this->tour(1, 'Vérifier cette page'), [12 => ['uid' => 12, 'title' => 'Über uns']]);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['lang']['availableLanguages']);
            } else {
                $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['lang']['availableLanguages'] = $previous;
            }
        }

        self::assertSame('tour', $item->titleKind);
    }

    #[Test]
    public function aTourStartedInTheUsersLanguageIsRecognised(): void
    {
        $item = $this->presenter([12 => ['uid' => 12, 'title' => 'Über uns']], userLanguage: 'it')->present([$this->tour(1, 'Controlla questa pagina')], $this->user('it'))[0];

        self::assertSame('tour', $item->titleKind);
        self::assertSame('Ottimizzare SEO · Über uns', $item->displayTitle);
    }

    /** The page is named in the conversation's language, where it has a translation the user may read. */
    #[Test]
    public function thePageIsNamedInTheConversationsLanguage(): void
    {
        $item = $this->presenter([12 => ['uid' => 12, 'title' => 'Über uns']], translatedTitles: ['12:1' => 'About us'])->present([$this->tour(1)], $this->user())[0];

        self::assertSame('SEO optimieren · About us', $item->displayTitle);
    }

    /** The same tour twice on the same page: the tab titles tell them apart by when each was started. */
    #[Test]
    public function twoConversationsWithTheSameTitleAreToldApartByTheirStart(): void
    {
        $first  = Conversation::fromRow(['uid' => 1, 'be_user' => 3, 'title' => 'Diese Seite prüfen', 'message_count' => 2, 'crdate' => strtotime('2026-10-20T08:00:00+00:00')]);
        $second = Conversation::fromRow(['uid' => 2, 'be_user' => 3, 'title' => 'Diese Seite prüfen', 'message_count' => 2, 'crdate' => strtotime('2026-10-22T14:30:00+00:00')]);
        $other  = $this->conversation(3, ['crdate' => strtotime('2026-10-21T09:00:00+00:00')]);
        foreach ([$first, $second] as $tour) {
            $tour->setViewContext(12, 'web_layout', 1);
            $tour->setSkillIdentifier('seo-optimieren', 4);
        }

        $titles = [];
        foreach ($this->presenter([12 => ['uid' => 12, 'title' => 'Über uns']])->present([$first, $second, $other], $this->user()) as $item) {
            $titles[$item->uid] = $item->displayTitle;
        }

        self::assertMatchesRegularExpression('/^SEO optimieren · Über uns · 20\. Okt\.?,? 08:00$/', $titles[1]);
        self::assertMatchesRegularExpression('/^SEO optimieren · Über uns · 22\. Okt\.?,? 14:30$/', $titles[2]);
        self::assertSame('Chat 3', $titles[3]);
    }

    /**
     * @param array<int, string> $started uid => creation time
     *
     * @return array<int, string> uid => display title
     */
    private function sameTourStartedAt(array $started): array
    {
        $tours = [];
        foreach ($started as $uid => $time) {
            $tour = Conversation::fromRow(['uid' => $uid, 'be_user' => 3, 'title' => 'Diese Seite prüfen', 'message_count' => 2, 'crdate' => strtotime($time)]);
            $tour->setViewContext(12, 'web_layout', 1);
            $tour->setSkillIdentifier('seo-optimieren', 4);
            $tours[] = $tour;
        }

        $titles = [];
        foreach ($this->presenter([12 => ['uid' => 12, 'title' => 'Über uns']])->present($tours, $this->user()) as $item) {
            $titles[$item->uid] = $item->displayTitle;
        }

        return $titles;
    }

    /**
     * Started within one minute (on the demo 11:27:01 and 11:27:44): the
     * minute does not tell them apart, the second does — for those alone.
     */
    #[Test]
    public function toursStartedWithinOneMinuteAreToldApartByTheSecond(): void
    {
        $titles = $this->sameTourStartedAt([
            190 => '2026-10-29T11:27:01+00:00',
            191 => '2026-10-29T11:27:44+00:00',
            192 => '2026-10-29T11:27:59+00:00',
            193 => '2026-10-28T09:15:30+00:00',
        ]);

        self::assertMatchesRegularExpression('/^SEO optimieren · Über uns · 29\. Okt\.?,? 11:27:01$/', $titles[190]);
        self::assertMatchesRegularExpression('/^SEO optimieren · Über uns · 29\. Okt\.?,? 11:27:44$/', $titles[191]);
        self::assertMatchesRegularExpression('/^SEO optimieren · Über uns · 29\. Okt\.?,? 11:27:59$/', $titles[192]);
        // Its minute is its own: no seconds.
        self::assertMatchesRegularExpression('/^SEO optimieren · Über uns · 28\. Okt\.?,? 09:15$/', $titles[193]);
    }

    /**
     * Started within the same second: numbered in the order they were
     * started, by uid where the time is the same, whatever order the list
     * arrives in, so the numbers are the same on every load.
     */
    #[Test]
    public function toursStartedInTheSameSecondAreNumbered(): void
    {
        $started = [
            7 => '2026-10-29T11:27:44+00:00',
            5 => '2026-10-29T11:27:44+00:00',
            6 => '2026-10-29T11:27:44+00:00',
            4 => '2026-10-29T11:27:01+00:00',
        ];
        $titles = $this->sameTourStartedAt($started);

        self::assertMatchesRegularExpression('/^SEO optimieren · Über uns · 29\. Okt\.?,? 11:27:01$/', $titles[4]);
        self::assertMatchesRegularExpression('/^SEO optimieren · Über uns · 29\. Okt\.?,? 11:27:44 \(1\)$/', $titles[5]);
        self::assertMatchesRegularExpression('/^SEO optimieren · Über uns · 29\. Okt\.?,? 11:27:44 \(2\)$/', $titles[6]);
        self::assertMatchesRegularExpression('/^SEO optimieren · Über uns · 29\. Okt\.?,? 11:27:44 \(3\)$/', $titles[7]);
        self::assertSame($titles, $this->sameTourStartedAt(array_reverse($started, true)));
        self::assertCount(4, array_unique($titles));
    }

    /** The time is kept on the conversation; only a row without it is read from its last message. */
    #[Test]
    public function theLastMessageTimeIsReadFromTheMessagesOnlyForARowWithoutIt(): void
    {
        $kept    = $this->conversation(1, ['last_message_at' => strtotime('2026-10-23T10:00:00+00:00')]);
        $missing = $this->conversation(2);
        $empty   = $this->conversation(3, ['message_count' => 0]);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('findLastMessageTimes')->with([2])->willReturn([2 => strtotime('2026-10-22T10:00:00+00:00')]);

        $items = $this->presenter(repository: $repository)->present([$kept, $missing, $empty], $this->user());
        $times = [];
        foreach ($items as $item) {
            $times[$item->uid] = $item->lastActivity['datetime'];
        }

        self::assertSame('2026-10-23T10:00:00+00:00', $times[1]);
        self::assertSame('2026-10-22T10:00:00+00:00', $times[2]);
        self::assertSame((new DateTimeImmutable('@' . (strtotime(self::NOW) - 60)))->format(DATE_ATOM), $times[3]);
    }

    #[Test]
    public function aListWhoseRowsKeepTheTimeReadsNoMessage(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::never())->method('findLastMessageTimes');

        $this->presenter(repository: $repository)->present([$this->conversation(1, ['last_message_at' => 1])], $this->user());
    }

    /** A tour the editor renamed keeps their title and names page and skill in its context. */
    #[Test]
    public function aRenamedTourKeepsItsTitleAndNamesPageAndSkillInItsContext(): void
    {
        $item = $this->one($this->tour(1, 'Meine Prüfung'), [12 => ['uid' => 12, 'title' => 'Über uns']]);

        self::assertSame('Meine Prüfung', $item->displayTitle);
        self::assertSame('own', $item->titleKind);
        self::assertSame(['Seite „Über uns“', 'SEO optimieren', 'Guided Tour'], $item->context);
    }

    /** The context names what the title does not: the page and skill are in the title. */
    #[Test]
    public function aTourWithADerivedTitleHasOnlyTheTourInItsContext(): void
    {
        $item = $this->one($this->withGuidedState($this->tour(1), 2, 5), [12 => ['uid' => 12, 'title' => 'Über uns']]);

        self::assertSame(['Guided Tour'], $item->context);
        self::assertSame('Guided Tour · Punkt 2 von 5', $item->contextLabel);
        self::assertSame(['current' => 2, 'total' => 5, 'label' => 'Punkt 2 von 5'], $item->progress);
        self::assertSame(['uid' => 12, 'languageUid' => 1], $item->page);
    }

    /** A page the user may not see is not named; the conversation is theirs and stays in the list. */
    #[Test]
    public function aTourOnAPageTheUserMayNotSeeStaysWithoutThePage(): void
    {
        $item = $this->one($this->tour(1));

        self::assertSame('SEO optimieren', $item->displayTitle);
        self::assertNull($item->page);
        self::assertFalse($item->pageGone);
        self::assertSame(['Guided Tour'], $item->context);
    }

    /** A deleted page is said, not named; the tour cannot go on, so no point is shown. */
    #[Test]
    public function aTourOnADeletedPageSaysSo(): void
    {
        $conversation = $this->withGuidedState($this->tour(1), 2, 4);
        $item         = $this->presenter(gonePages: [12])->present([$conversation], $this->user())[0];

        self::assertSame('SEO optimieren', $item->displayTitle);
        self::assertTrue($item->pageGone);
        self::assertNull($item->page);
        self::assertSame(['Gelöschte Seite', 'Guided Tour'], $item->context);
        self::assertNull($item->progress);
    }

    /** A skill without a title has no name an editor would understand; the start message stands in. */
    #[Test]
    public function aTourOfASkillWithoutATitleIsNamedByTheStartMessage(): void
    {
        $conversation = $this->tour(1);
        $conversation->setSkillIdentifier('unlabelled-skill');

        self::assertSame('Diese Seite prüfen · Über uns', $this->one($conversation, [12 => ['uid' => 12, 'title' => 'Über uns']])->displayTitle);
    }

    #[Test]
    public function aTourShowsTheSkillTitleInTheUsersLanguage(): void
    {
        $item = $this->presenter([12 => ['uid' => 12, 'title' => 'About us']], userLanguage: 'default')->present([$this->tour(1)], $this->user(''))[0];

        self::assertSame('SEO optimisation · About us', $item->displayTitle);
    }

    /** The title of a conversation about a missing alt text names its image, and stays. */
    #[Test]
    public function anAltTextConversationKeepsItsTitle(): void
    {
        $conversation = $this->conversation(1, ['title' => 'Alt-Text: team-photo.jpg']);
        $conversation->setAltTextHit(77);
        $conversation->setViewContext(12, 'web_layout', 0);

        $item = $this->one($conversation, [12 => ['uid' => 12, 'title' => 'Über uns']]);

        self::assertSame('Alt-Text: team-photo.jpg', $item->displayTitle);
        self::assertSame('altText', $item->titleKind);
        self::assertSame([], $item->context);
    }

    /** An ordinary chat is titled by its first message, collapsed and shortened, the full text in the tooltip. */
    #[Test]
    public function aLongFirstMessageIsShortenedWithTheFullTextAsTooltip(): void
    {
        $message = "Bitte prüfe alle Seiten unter \"Produkte\" auf fehlende Meta-Beschreibungen\nund schlage für jede Seite eine neue vor.";
        $item    = $this->one($this->conversation(1, ['title' => $message]));

        self::assertSame('Bitte prüfe alle Seiten unter "Produkte" auf fehlende…', $item->displayTitle);
        self::assertSame('Bitte prüfe alle Seiten unter "Produkte" auf fehlende Meta-Beschreibungen und schlage für jede Seite eine neue vor.', $item->fullTitle);
        self::assertSame('own', $item->titleKind);
    }

    /**
     * Any other conversation stores the page the browser showed with its last
     * message: where the editor was, not what the chat is about. It is not
     * named, not checked and not opened, whether the page is there, hidden
     * from the user or deleted.
     *
     * @return iterable<string, array{array<int, array{uid: int, title: string}>, list<int>}>
     */
    public static function lastPagesOfAnOrdinaryChat(): iterable
    {
        yield 'a page the user may see' => [[12 => ['uid' => 12, 'title' => 'Über uns']], []];
        yield 'a page the user may not see' => [[], []];
        yield 'a deleted page' => [[], [12]];
    }

    /**
     * @param array<int, array{uid: int, title: string}> $accessible
     * @param list<int>                                  $gone
     */
    #[Test]
    #[DataProvider('lastPagesOfAnOrdinaryChat')]
    public function anOrdinaryChatIsListedWithoutItsLastPage(array $accessible, array $gone): void
    {
        $conversation = $this->conversation(1);
        $conversation->setViewContext(12, 'web_layout', 0);

        $item = $this->presenter($accessible, $gone)->present([$conversation], $this->user())[0];

        self::assertSame('Chat 1', $item->displayTitle);
        self::assertSame([], $item->context);
        self::assertSame('', $item->contextLabel);
        self::assertNull($item->page);
        self::assertFalse($item->pageGone);
    }

    /** "Check this page" typed in an ordinary chat names no page: the chat is bound to none. */
    #[Test]
    public function anOrdinaryChatTitledLikeATourStartStaysAsItIs(): void
    {
        $conversation = $this->conversation(1, ['title' => 'Diese Seite prüfen']);
        $conversation->setViewContext(12, 'web_layout', 0);

        $item = $this->presenter([12 => ['uid' => 12, 'title' => 'Über uns']], processRun: false)->present([$conversation], $this->user())[0];

        self::assertSame('Diese Seite prüfen', $item->displayTitle);
        self::assertSame('own', $item->titleKind);
    }

    #[Test]
    public function aConversationWithoutATitleHasAnEmptyTitle(): void
    {
        $item = $this->one($this->conversation(1, ['title' => '', 'message_count' => 0]));

        self::assertSame('', $item->displayTitle);
        self::assertSame('empty', $item->titleKind);
        self::assertSame(0, $item->messageCount);
    }

    // ── Context ──────────────────────────────────────────────────────────

    /** A skill nr-llm marks as plain is no guided tour: no tour, no chip. */
    #[Test]
    public function aPlainSkillIsNoGuidedTour(): void
    {
        $conversation = $this->withGuidedState($this->conversation(1), 2, 5);
        $conversation->setSkillIdentifier('seo-optimieren');

        $item = $this->one($conversation, processRun: false);

        self::assertSame('Chat 1', $item->displayTitle);
        self::assertFalse($item->guided);
        self::assertSame(['SEO optimieren'], $item->context);
        self::assertNull($item->progress);
    }

    /** A skill that is no process but needs its page is bound to it, and named by it. */
    #[Test]
    public function aPlainSkillThatNeedsAPageNamesItsPage(): void
    {
        $conversation = $this->conversation(1);
        $conversation->setViewContext(12, 'web_layout', 1);
        $conversation->setSkillIdentifier('seo-optimieren');

        $item = $this->one($conversation, [12 => ['uid' => 12, 'title' => 'Über uns']], processRun: false, skills: $this->catalogueDeclaring('required', false));

        self::assertSame(['uid' => 12, 'languageUid' => 1], $item->page);
        self::assertSame(12, $item->jsonSerialize()['pageUid']);
        self::assertSame(['Seite „Über uns“', 'SEO optimieren'], $item->context);
        self::assertSame('Seite „Über uns“ · SEO optimieren', $item->contextLabel);
    }

    /** Such a skill gives up a deleted page (it asks for a new one), so the list shows no deleted page. */
    #[Test]
    public function aPlainSkillOnADeletedPageIsNotShownAsDeleted(): void
    {
        $conversation = $this->conversation(1);
        $conversation->setViewContext(42, 'web_layout', 0);
        $conversation->setSkillIdentifier('seo-optimieren');

        $item = $this->presenter(gonePages: [42], processRun: false, skills: $this->catalogueDeclaring('required', false))->present([$conversation], $this->user())[0];

        self::assertFalse($item->pageGone);
        self::assertNull($item->page);
        self::assertSame(['SEO optimieren'], $item->context);
    }

    /**
     * A process that only may work on a page (ADR-025) follows the browser,
     * so its stored page is where the editor last was: not named.
     *
     * @return iterable<string, array{array<int, array{uid: int, title: string}>}>
     */
    public static function lastPagesOfAnOptionalProcess(): iterable
    {
        yield 'a page the user may see' => [[12 => ['uid' => 12, 'title' => 'Über uns']]];
        yield 'a page the user may not see' => [[]];
    }

    /**
     * @param array<int, array{uid: int, title: string}> $accessible
     */
    #[Test]
    #[DataProvider('lastPagesOfAnOptionalProcess')]
    public function aProcessThatMayWorkOnAPageIsListedWithoutItsLastPage(array $accessible): void
    {
        $item = $this->one($this->tour(1, 'Prüf mal'), $accessible, skills: $this->catalogueDeclaring('optional', true));

        self::assertNull($item->page);
        self::assertSame(['SEO optimieren', 'Guided Tour'], $item->context);
    }

    #[Test]
    public function aFinishedTourShowsNoProgress(): void
    {
        self::assertNull($this->one($this->withGuidedState($this->tour(1), 5, 5, completed: true))->progress);
    }

    /** Point 0 is the analysis before the first point; the header says so, the list shows no chip yet. */
    #[Test]
    public function aTourShowsNoProgressBeforeItsFirstPoint(): void
    {
        self::assertNull($this->one($this->withGuidedState($this->tour(1), 0, 5))->progress);
    }

    /** One page is read once per list, however many conversations are about it. */
    #[Test]
    public function aPageIsReadOncePerList(): void
    {
        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->expects(self::once())->method('findAccessibleTitles')->with([12 => 1])->willReturn([12 => ['uid' => 12, 'title' => 'Über uns']]);

        $items = $this->presenter(pages: $pages)->present([$this->tour(1), $this->tour(2), $this->tour(3)], $this->user());

        self::assertCount(3, $items);
    }

    // ── Status ───────────────────────────────────────────────────────────

    /**
     * Ready is neutral, never green; waiting for the editor stands out; the
     * label says it in words.
     *
     * @return iterable<string, array{ConversationStatus, string, string}>
     */
    public static function statuses(): iterable
    {
        yield 'idle' => [ConversationStatus::Idle, 'neutral', 'Bereit'];
        yield 'processing' => [ConversationStatus::Processing, 'info', 'Arbeitet'];
        yield 'locked is a running turn' => [ConversationStatus::Locked, 'info', 'Arbeitet'];
        yield 'tool loop' => [ConversationStatus::ToolLoop, 'info', 'Arbeitet'];
        yield 'awaiting approval' => [ConversationStatus::AwaitingApproval, 'warning', 'Wartet auf deine Freigabe'];
        yield 'awaiting input' => [ConversationStatus::AwaitingInput, 'warning', 'Wartet auf deine Antwort'];
        yield 'failed' => [ConversationStatus::Failed, 'danger', 'Fehlgeschlagen'];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function theStatusHasAToneAndALabel(ConversationStatus $status, string $tone, string $label): void
    {
        $item = $this->one($this->conversation(1, ['status' => $status->value]));

        self::assertSame($status->value, $item->status);
        self::assertSame($tone, $item->statusTone);
        self::assertSame($label, $item->statusLabel);
    }

    // ── Order and time ───────────────────────────────────────────────────

    #[Test]
    public function theMostRecentLastMessageComesFirst(): void
    {
        $conversations = [];
        $times         = [];
        for ($i = 1; $i <= 4; ++$i) {
            $conversations[] = $this->conversation($i);
            $times[$i]       = strtotime(self::NOW) - 1000 + $i;
        }

        $items = $this->presenter(lastMessageTimes: $times)->present($conversations, $this->user());

        self::assertSame([4, 3, 2, 1], array_map(static fn (ConversationListItem $i): int => $i->uid, $items));
    }

    /** Pinning or renaming moves the changed-at time, not the last message. */
    #[Test]
    public function aConversationWhoseLastMessageHasNoTimeCountsByItsChangedAtTime(): void
    {
        $older = $this->conversation(1, ['tstamp' => strtotime(self::NOW) - 30]);
        $newer = $this->conversation(2, ['tstamp' => strtotime(self::NOW) - 10]);

        $items = $this->presenter(lastMessageTimes: [2 => strtotime(self::NOW) - 3600])->present([$newer, $older], $this->user());

        self::assertSame([1, 2], array_map(static fn (ConversationListItem $i): int => $i->uid, $items));
        self::assertSame(strtotime(self::NOW) - 30, $items[0]->lastActivityTimestamp);
    }

    #[Test]
    public function theCreationTimeIsGiven(): void
    {
        $item = $this->one($this->conversation(1, ['crdate' => strtotime('2026-10-20T08:00:00+00:00')]));

        self::assertSame('2026-10-20T08:00:00+00:00', $item->createdAt);
        self::assertSame('', $this->one($this->conversation(2))->createdAt);
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function times(): iterable
    {
        yield 'today, German' => ['2026-10-24T08:05:00+00:00', 'de', 'today', '/^08:05$/', '/^Heute, 08:05$/'];
        yield 'today, English' => ['2026-10-24T08:05:00+00:00', '', 'today', '/^8:05\s?AM$/u', '/^Today, 8:05\s?AM$/u'];
        yield 'just after midnight is today' => ['2026-10-24T00:01:00+00:00', 'de', 'today', '/^00:01$/', '/^Heute, 00:01$/'];
        yield 'yesterday, German' => ['2026-10-23T16:05:00+00:00', 'de', 'yesterday', '/^16:05$/', '/^Gestern, 16:05$/'];
        yield 'yesterday, English' => ['2026-10-23T16:05:00+00:00', '', 'yesterday', '/^4:05\s?PM$/u', '/^Yesterday, 4:05\s?PM$/u'];
        yield 'the day before, German' => ['2026-10-22T23:59:00+00:00', 'de', 'date', '/^22\. Okt\.$/', '/^22\. Okt\.$/'];
        yield 'earlier this year, German' => ['2026-10-03T09:00:00+00:00', 'de', 'date', '/^3\. Okt\.$/', '/^3\. Okt\.$/'];
        yield 'earlier this year, English' => ['2026-10-03T09:00:00+00:00', '', 'date', '/^Oct 3$/', '/^Oct 3$/'];
        yield 'just under a year, German' => ['2025-10-25T09:00:00+00:00', 'de', 'date', '/^25\. Okt\.$/', '/^25\. Okt\.$/'];
        yield 'over a year, German' => ['2025-10-23T09:00:00+00:00', 'de', 'date', '/^23\. Okt\. 2025$/', '/^23\. Okt\. 2025$/'];
        yield 'over a year, English' => ['2025-10-23T09:00:00+00:00', '', 'date', '/^Oct 23, 2025$/', '/^Oct 23, 2025$/'];
    }

    /** Calendar days relative to the request's time, in the user's language; the full date in the tooltip. */
    #[Test]
    #[DataProvider('times')]
    public function theTimeOfTheLastMessageIsRelativeToToday(string $at, string $lang, string $kind, string $text, string $label): void
    {
        $presenter = $this->presenter(lastMessageTimes: [1 => strtotime($at)], userLanguage: $lang === '' ? 'default' : $lang);
        $time      = $presenter->present([$this->conversation(1)], $this->user($lang))[0]->lastActivity;

        self::assertSame($kind, $time['kind']);
        self::assertMatchesRegularExpression($text, $time['text']);
        self::assertMatchesRegularExpression($label, $time['label']);
        self::assertSame((new DateTimeImmutable($at))->format(DATE_ATOM), $time['datetime']);
        self::assertStringContainsString(substr($at, 0, 4), $time['absolute']);
    }

    /** "Today" is the server's calendar day, not the last 24 hours. */
    #[Test]
    public function todayFollowsTheServersTimeZone(): void
    {
        date_default_timezone_set('Europe/Berlin');
        // 23:30 UTC on the 23rd is 01:30 on the 24th in Berlin.
        $time = $this->presenter(lastMessageTimes: [1 => strtotime('2026-10-23T23:30:00+00:00')])->present([$this->conversation(1)], $this->user())[0]->lastActivity;

        self::assertSame('today', $time['kind']);
        self::assertSame('01:30', $time['text']);
    }

    // ── New tours ────────────────────────────────────────────────────────

    #[Test]
    public function aNewTourIsStoredWithItsSkillAndPage(): void
    {
        $presenter = $this->presenter([12 => ['uid' => 12, 'title' => 'Über uns']]);

        self::assertSame('SEO optimieren · Über uns', $presenter->tourStartTitle('seo-optimieren', 12, $this->user()));
        self::assertSame('SEO optimieren', $presenter->tourStartTitle('seo-optimieren', 99, $this->user()));
    }

    /** The stored title is recognised as the extension's own, so the list keeps deriving it. */
    #[Test]
    public function theStoredTitleOfANewTourIsRecognisedAsAutomatic(): void
    {
        $presenter = $this->presenter([12 => ['uid' => 12, 'title' => 'Über uns']]);
        $stored    = $presenter->tourStartTitle('seo-optimieren', 12, $this->user(), 1);

        self::assertSame('tour', $this->one($this->tour(1, $stored), [12 => ['uid' => 12, 'title' => 'Über uns']])->titleKind);
    }

    /** The two languages the presenter knows the start message in are the ones the extension ships. */
    #[Test]
    public function theShippedLanguagesAreTheLanguagesOfTheChatLabels(): void
    {
        $files     = glob(dirname(__DIR__, 4) . '/Resources/Private/Language/*locallang_chat.xlf') ?: [];
        $languages = array_map(static fn (string $file): string => basename($file) === 'locallang_chat.xlf' ? 'default' : explode('.', basename($file))[0], $files);

        self::assertEqualsCanonicalizing($languages, ConversationListPresenter::SHIPPED_LANGUAGES);
    }

    #[Test]
    public function anEmptyListReadsNothing(): void
    {
        self::assertSame([], $this->presenter()->present([], $this->user()));
    }
}
