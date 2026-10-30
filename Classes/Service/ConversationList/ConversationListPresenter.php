<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ConversationList;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Service\AltText\AltTextConversation;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\ProcessRunDetectorInterface;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Service\SkillPageNeed;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locales;

/**
 * What every list of conversations shows about one (ADR-027): the chat
 * module's list, the panel's tabs, its "more" list, its sidebar and its
 * minimized bar, and the dashboard widget "Recent chats".
 *
 * Per conversation:
 * - the title (ConversationTitle): "<skill> · <page>" for a guided tour whose
 *   title the extension gave it, else the stored title shortened for a list,
 *   with the full text for the tooltip;
 * - the context, the parts the title does not already name: the page a skill
 *   that needs one is bound to, the skill's title, "Guided tour" and the point
 *   the tour is at. Only a conversation whose skill needs its page is bound to
 *   it (ADR-025); any other stores the page the browser showed with its last
 *   message, which is where the editor was, not what the chat is about, and
 *   gets no page;
 * - a page is named only when the user may see it in the page tree; a deleted
 *   page of a guided tour is named as deleted, a page the user may not see is
 *   left out, the conversation stays;
 * - when it was created, and when its last message was written, which a list
 *   is ordered by: a conversation whose last message carries no time counts by
 *   when it was last changed;
 * - its status, as a tone the lists colour it with and a label that says it
 *   in words.
 *
 * Pages, skills and labels are read once per list, not once per row, and
 * again for the next list.
 */
final class ConversationListPresenter
{
    private const LL = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:';

    /** The message a guided tour started with before ADR-027, and the button that starts one. */
    public const TOUR_START_KEY = 'guided.checkPage';

    /**
     * The languages locallang_chat.xlf ships ("default" is the English source).
     * A tour started in any of them — or in the user's language, or in a
     * backend language installed here — is recognised by its start message,
     * also when the editor has switched the backend language since.
     */
    public const SHIPPED_LANGUAGES = ['default', 'de'];

    /**
     * The tone a status is shown in, and its label: an idle conversation is
     * neutral, a conversation that waits for the editor stands out, a running
     * one is information, a failed one an error. `locked` is a running turn.
     */
    private const STATUS = [
        'idle'              => ['neutral', 'status.idle'],
        'processing'        => ['info', 'status.processing'],
        'locked'            => ['info', 'status.processing'],
        'tool_loop'         => ['info', 'status.processing'],
        'awaiting_approval' => ['warning', 'status.awaitingApproval'],
        'awaiting_input'    => ['warning', 'status.awaitingInput'],
        'failed'            => ['danger', 'status.failed'],
    ];

    /**
     * The pages of the list, by "<uid>:<language>", null for one the user may
     * not see (readPages()).
     *
     * @var array<string, array{uid: int, title: string}|null>
     */
    private array $accessiblePages = [];

    /** @var array<int, bool> */
    private array $gonePages = [];

    /** @var array<string, string|null> */
    private array $skillTitles = [];

    /** @var array<string, bool> */
    private array $processSkills = [];

    /** @var array<string, bool> */
    private array $pageSkills = [];

    public function __construct(
        private readonly ConversationRepository $repository,
        private readonly PageChoiceRepository $pages,
        private readonly SkillLabels $skillLabels,
        private readonly ProcessRunDetectorInterface $processRuns,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly Locales $locales,
        private readonly Context $context,
        private readonly ?SkillCatalogueInterface $skills = null,
    ) {}

    /**
     * The conversations as a list shows them, the most recent last message
     * first.
     *
     * @param list<Conversation> $conversations
     *
     * @return list<ConversationListItem>
     */
    public function present(array $conversations, ?BackendUserAuthentication $user): array
    {
        if ($conversations === []) {
            return [];
        }

        // Read once per list, never across lists: the service is shared, and
        // a page or a permission may change between two requests of a worker.
        $this->accessiblePages = [];
        $this->gonePages       = [];
        $this->processSkills   = [];
        $this->pageSkills      = [];
        $this->skillTitles     = [];

        $language = $this->languageService($user);
        $locale   = (string) $this->locales->createLocaleFromUserPreferences($user);
        $now      = $this->now();
        $starts   = $this->tourStartMessages($language);
        $this->readPages($conversations);

        // The time is kept on the conversation (`last_message_at`); a row
        // saved before the column existed, and not yet backfilled by
        // LastMessageTimeUpdateWizard, is read from its last message row.
        $missing = array_values(array_map(
            static fn (Conversation $c): int => $c->getUid(),
            array_filter($conversations, static fn (Conversation $c): bool => $c->getLastMessageAt() === 0 && $c->getMessageCount() > 0),
        ));
        $times = $missing === [] ? [] : $this->repository->findLastMessageTimes($missing);

        $items = [];
        foreach ($conversations as $conversation) {
            $lastActivity = $conversation->getLastMessageAt() > 0 ? $conversation->getLastMessageAt() : ($times[$conversation->getUid()] ?? $conversation->getTstamp());
            $items[]      = $this->item($conversation, $lastActivity, $language, $locale, $now, $starts);
        }

        usort($items, static fn (ConversationListItem $a, ConversationListItem $b): int => [$b->lastActivityTimestamp, $b->uid] <=> [$a->lastActivityTimestamp, $a->uid]);

        return $this->disambiguate($items, $locale, $now);
    }

    /**
     * Two conversations a list would show with the same title — the same tour
     * on the same page, twice — get the time they were started after it, so a
     * tab tells them apart without its tooltip.
     *
     * @param list<ConversationListItem> $items
     *
     * @return list<ConversationListItem>
     */
    private function disambiguate(array $items, string $locale, DateTimeImmutable $now): array
    {
        $counts  = array_count_values(array_map(static fn (ConversationListItem $i): string => $i->displayTitle, $items));
        $pattern = (new IntlDatePatternGenerator($locale))->getBestPattern('MMMdjm');
        $format  = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $now->getTimezone()->getName(), null, $pattern === false ? null : $pattern);

        return array_map(static function (ConversationListItem $item) use ($counts, $format): ConversationListItem {
            if ($item->displayTitle === '' || ($counts[$item->displayTitle] ?? 0) < 2 || $item->createdAt === '') {
                return $item;
            }

            $started = (string) $format->format(new DateTimeImmutable($item->createdAt));

            return $item->withDisplayTitle($item->displayTitle . ConversationTitle::SEPARATOR . $started);
        }, $items);
    }

    /**
     * Read the pages the conversations are about, with the user's rights and
     * in each conversation's language, one batch per language.
     *
     * @param list<Conversation> $conversations
     */
    private function readPages(array $conversations): void
    {
        $byLanguage = [];
        foreach ($conversations as $conversation) {
            $skill   = $conversation->getSkillIdentifier();
            $context = $conversation->getViewContext();
            if ($context['pageId'] > 0 && $this->bindsPage($skill, $skill !== '' && $this->isProcess($conversation))) {
                $byLanguage[max(0, $context['languageId'])][$context['pageId']] = max(0, $context['languageId']);
            }
        }

        foreach ($byLanguage as $languageUid => $pages) {
            $titles = $this->pages->findAccessibleTitles($pages);
            foreach (array_keys($pages) as $pageUid) {
                $this->accessiblePages[$pageUid . ':' . $languageUid] = $titles[$pageUid] ?? null;
            }
        }
    }

    /**
     * The title a new guided tour is stored with: "<skill> · <page>" in the
     * user's language, the page only when the user may see it.
     */
    public function tourStartTitle(string $skillIdentifier, int $pageUid, ?BackendUserAuthentication $user, int $languageUid = 0): string
    {
        $language = $this->languageService($user);
        $page     = $pageUid > 0 ? ($this->pages->findAccessibleTitles([$pageUid => max(0, $languageUid)])[$pageUid] ?? null) : null;

        return mb_substr(ConversationTitle::tourTitle($this->tourName($skillIdentifier, $language), $page['title'] ?? null), 0, 255);
    }

    /**
     * @param list<string> $startMessages
     */
    private function item(Conversation $conversation, int $lastActivity, LanguageService $language, string $locale, DateTimeImmutable $now, array $startMessages): ConversationListItem
    {
        $skill      = $conversation->getSkillIdentifier();
        $guided     = $skill !== '' && $this->isProcess($conversation);
        $skillTitle = $skill !== '' ? $this->skillTitle($skill) : null;
        $altText    = AltTextConversation::isAbout($conversation);

        $viewContext = $conversation->getViewContext();
        $pageUid     = $this->bindsPage($skill, $guided) ? $viewContext['pageId'] : 0;
        $page        = $pageUid > 0 ? ($this->accessiblePages[$pageUid . ':' . max(0, $viewContext['languageId'])] ?? null) : null;
        // A skill that is no process gives up a deleted page and asks for a
        // new one; only a guided tour is shown, read-only, on it.
        $pageGone = $guided && $pageUid > 0 && $page === null && $this->isGone($pageUid);

        $stored    = $conversation->getTitle();
        $collapsed = ConversationTitle::collapse($stored);
        $tourName  = $this->tourName($skill, $language);
        $isTour    = !$altText && ($guided || in_array($collapsed, array_map(ConversationTitle::collapse(...), $startMessages), true));
        // A title the editor gave stays; one the extension marked as the
        // tour's is derived again; an unmarked one only when it is exactly a
        // title the extension gives (ConversationTitle).
        $automatic = match ($conversation->getTitleSource()) {
            Conversation::TITLE_MANUAL => false,
            Conversation::TITLE_TOUR   => true,
            default                    => ConversationTitle::isAutomaticTourTitle($stored, $startMessages, [$tourName, ConversationTitle::tourTitle($tourName, $page['title'] ?? null)]),
        };
        $derived = $isTour && $automatic && ($guided || $page !== null);

        if ($derived) {
            $displayTitle = ConversationTitle::tourTitle($tourName, $page['title'] ?? null);
            $fullTitle    = $displayTitle;
        } else {
            $displayTitle = ConversationTitle::shorten($stored);
            $fullTitle    = $collapsed;
        }

        $context = [];
        if ($pageGone) {
            $context[] = $language->sL(self::LL . 'widget.context.pageGone');
        } elseif ($page !== null && !$derived) {
            $context[] = sprintf($language->sL(self::LL . 'widget.context.page'), $page['title']);
        }

        if ($skillTitle !== null && !$derived) {
            $context[] = $skillTitle;
        }

        if ($guided) {
            $context[] = $language->sL(self::LL . 'widget.context.guidedTour');
        }

        // The point the tour is at, as its header shows it: only once a point
        // has started (0 is the analysis before the first), and nothing once
        // the tour reported its end or its page is gone — it cannot go on.
        $state    = $guided && !$pageGone ? $conversation->getGuidedState()['progress'] : null;
        $progress = $state !== null && !$state['completed'] && $state['current'] >= 1
            ? ['current' => $state['current'], 'total' => $state['total'], 'label' => sprintf($language->sL(self::LL . 'guided.progress'), $state['current'], $state['total'])]
            : null;

        $status             = $conversation->getStatus();
        [$tone, $statusKey] = self::STATUS[$status->value] ?? ['neutral', 'status.unknown'];

        return new ConversationListItem(
            uid: $conversation->getUid(),
            title: $stored,
            displayTitle: $displayTitle,
            fullTitle: $fullTitle,
            titleKind: $derived ? 'tour' : ($stored === '' ? 'empty' : ($altText ? 'altText' : 'own')),
            context: $context,
            contextLabel: implode(ConversationTitle::SEPARATOR, $progress === null ? $context : [...$context, $progress['label']]),
            page: $page === null ? null : ['uid' => $page['uid'], 'languageUid' => $viewContext['languageId']],
            pageGone: $pageGone,
            skillTitle: $skillTitle,
            guided: $guided,
            progress: $progress,
            createdAt: $conversation->getCrdate() > 0 ? $this->atom($conversation->getCrdate(), $now) : '',
            lastActivityTimestamp: $lastActivity,
            lastActivity: $this->describeTime($lastActivity, $locale, $now, $language),
            status: $status->value,
            statusTone: $tone,
            statusLabel: $language->sL(self::LL . $statusKey),
            messageCount: $conversation->getMessageCount(),
            pinned: $conversation->isPinned(),
        );
    }

    /**
     * The name a guided tour goes by: the skill's title, or the start message
     * in the user's language for a skill without one.
     */
    private function tourName(string $skill, LanguageService $language): string
    {
        return ($skill !== '' ? $this->skillTitle($skill) : null) ?? $language->sL(self::LL . self::TOUR_START_KEY);
    }

    /**
     * The tour's start message in every language the presenter can resolve:
     * the user's, the ones the extension ships, and the backend languages
     * installed on this system (language packs may translate it).
     *
     * @return list<string>
     */
    private function tourStartMessages(LanguageService $userLanguage): array
    {
        $confVars  = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $extConf   = is_array($confVars) && is_array($confVars['EXTCONF'] ?? null) ? $confVars['EXTCONF'] : [];
        $lang      = is_array($extConf['lang'] ?? null) ? $extConf['lang'] : [];
        $installed = $lang['availableLanguages'] ?? [];
        $languages = array_unique([...self::SHIPPED_LANGUAGES, ...array_filter(is_array($installed) ? $installed : [], is_string(...))]);
        $messages  = [$userLanguage->sL(self::LL . self::TOUR_START_KEY)];
        foreach ($languages as $languageKey) {
            $message = $this->languageServiceFactory->create($languageKey)->sL(self::LL . self::TOUR_START_KEY);
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return array_values(array_unique(array_filter($messages, static fn (string $message): bool => $message !== '')));
    }

    private function languageService(?BackendUserAuthentication $user): LanguageService
    {
        return $user instanceof BackendUserAuthentication
            ? $this->languageServiceFactory->createFromUserPreferences($user)
            : $this->languageServiceFactory->create('default');
    }

    private function isProcess(Conversation $conversation): bool
    {
        return $this->processSkills[$conversation->getSkillIdentifier()] ??= $this->processRuns->isProcessRun($conversation);
    }

    private function skillTitle(string $skill): ?string
    {
        if (!array_key_exists($skill, $this->skillTitles)) {
            $this->skillTitles[$skill] = $this->skillLabels->title($skill);
        }

        return $this->skillTitles[$skill];
    }

    /**
     * Whether the conversation is bound to its page: its skill needs one
     * (ADR-025). Without the catalogue the process marker decides.
     */
    private function bindsPage(string $skill, bool $guided): bool
    {
        if ($skill === '') {
            return false;
        }

        if (!$this->skills instanceof SkillCatalogueInterface) {
            return $guided;
        }

        return $this->pageSkills[$skill] ??= SkillPageNeed::of($this->skills->find($skill)) === SkillPageNeed::Required;
    }

    private function isGone(int $uid): bool
    {
        return $this->gonePages[$uid] ??= $this->pages->isGone($uid);
    }

    /** The request's time, in the server's time zone. */
    private function now(): DateTimeImmutable
    {
        $now = $this->context->getPropertyFromAspect('date', 'full');
        $now = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable();

        return $now->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    private function atom(int $timestamp, DateTimeImmutable $now): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($now->getTimezone())->format(DATE_ATOM);
    }

    /**
     * When the last message was written, as a list shows it: "Heute, 14:03"
     * and "Gestern, 09:12" for today and yesterday, the day and month for
     * anything older, and the year as well once it is more than a year back;
     * the full date and time for the tooltip. Calendar days in the server's
     * time zone, in the backend user's language: a fixed d.m.Y reads wrong to
     * everyone who does not use it.
     *
     * @return array{kind: string, text: string, label: string, absolute: string, datetime: string}
     */
    private function describeTime(int $timestamp, string $locale, DateTimeImmutable $now, LanguageService $language): array
    {
        $at       = (new DateTimeImmutable('@' . $timestamp))->setTimezone($now->getTimezone());
        $timezone = $now->getTimezone()->getName();
        $day      = $at->format('Y-m-d');

        if ($day === $now->format('Y-m-d') || $day === $now->modify('-1 day')->format('Y-m-d')) {
            $kind      = $day === $now->format('Y-m-d') ? 'today' : 'yesterday';
            $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $timezone);
        } else {
            $kind      = 'date';
            $skeleton  = $at < $now->modify('-1 year') ? 'yMMMd' : 'MMMd';
            $pattern   = (new IntlDatePatternGenerator($locale))->getBestPattern($skeleton);
            $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $timezone, null, $pattern === false ? null : $pattern);
        }

        $text = (string) $formatter->format($at);

        return [
            'kind'     => $kind,
            'text'     => $text,
            'label'    => $kind === 'date' ? $text : sprintf($language->sL(self::LL . 'widget.time.' . $kind), $text),
            'absolute' => (string) (new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::SHORT, $timezone))->format($at),
            'datetime' => $at->format(DATE_ATOM),
        ];
    }
}
