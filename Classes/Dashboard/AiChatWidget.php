<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use LogicException;
use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\ProcessRunDetectorInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

/**
 * "Recent chats" on the TYPO3 dashboard: the user's last conversations, each
 * with what it was about and when it last moved, and a way back into it.
 *
 * Registered only when EXT:dashboard is installed (Configuration/Services.php);
 * the extension does not require it.
 *
 * Who may use the chat is decided exactly as for the toolbar button — the
 * same object answers — so a widget that core's per-group widget permissions
 * let a user place never becomes a way into a chat they were not given. For
 * such a user it says that the chat is not available.
 *
 * Per entry:
 * - the title: the guided process's name where the conversation runs one and
 *   the skill has a title, else the conversation's own title;
 * - the context, for a guided process: the page it is bound to (its
 *   `view_context`, which only a guided process keeps, ADR-023) and "Guided
 *   tour". Any other conversation stores the page the browser showed with
 *   its last message, which says where the editor was, not what the chat is
 *   about; it gets no context line;
 * - for a guided process that is at a point and has not reported its end,
 *   that point, as the chat's header shows it ("Punkt n von m", ADR-023);
 * - when its last message was written, relative to today.
 *
 * A guided process on a page the user may not see is left out, and does not
 * take one of the places. One whose page no longer exists stays: it says
 * so, and the chat opens it read-only.
 *
 * A conversation opens in the floating panel, which lives in the backend
 * frame around the dashboard (ADR-011), in the user's layout (ADR-024); a
 * guided process also shows its page in the page module. Without the panel,
 * the link opens the chat module (dashboard-widget.js).
 */
final class AiChatWidget implements WidgetInterface, RequestAwareWidgetInterface, JavaScriptInterface, AdditionalCssInterface
{
    /** How many conversations the widget lists. */
    public const LIMIT = 5;

    /**
     * How many conversations are read to fill the list: conversations about a
     * page the user may not see must not take its places, and the list is
     * ordered by the last message, which the changed-at order the candidates
     * are read in only approximates (pinning or renaming moves it too).
     */
    public const WINDOW = 50;

    private const LL = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:';

    /** Set by the dashboard's WidgetRegistry right after it creates the widget. */
    private ?ServerRequestInterface $request = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly ConversationRepository $repository,
        private readonly ChatToolbarItem $chatAccess,
        private readonly Locales $locales,
        private readonly PageChoiceRepository $pages,
        private readonly SkillLabels $skillLabels,
        private readonly ProcessRunDetectorInterface $processRuns,
        private readonly Context $context,
        private readonly array $options = [],
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $request = $this->request ?? throw new LogicException('setRequest() must be called before renderWidgetContent()', 1792329208);
        $view    = $this->backendViewFactory->create($request, ['typo3/cms-dashboard', 'netresearch/nr-mcp-agent']);
        $view->assignMultiple([
            ...$this->templateVariables(),
            'configuration' => $this->configuration,
        ]);

        return $view->render('Widget/AiChatWidget');
    }

    /**
     * What the template shows: whether the chat is available to this user,
     * and their most recent conversations if it is.
     *
     * @return array{available: bool, conversations: list<array{uid: int, title: string, context: list<array{key: string, arguments: list<string>}>, page: array{uid: int, languageUid: int}|null, pageGone: bool, progress: array{current: int, total: int}|null, time: array{kind: string, text: string, datetime: string}}>}
     */
    public function templateVariables(): array
    {
        $available = $this->chatAccess->checkAccess();

        return [
            'available'     => $available,
            'conversations' => $available ? $this->recentConversations() : [],
        ];
    }

    /**
     * @return list<array{uid: int, title: string, context: list<array{key: string, arguments: list<string>}>, page: array{uid: int, languageUid: int}|null, pageGone: bool, progress: array{current: int, total: int}|null, time: array{kind: string, text: string, datetime: string}}>
     */
    private function recentConversations(): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $backendUser = $backendUser instanceof BackendUserAuthentication ? $backendUser : null;

        $rawUid = $backendUser?->user['uid'] ?? null;
        $uid    = is_numeric($rawUid) ? (int) $rawUid : 0;
        if ($uid === 0) {
            return [];
        }

        $candidates = $this->repository->findRecentWithMessagesByBeUser($uid, self::WINDOW);
        $times      = $this->repository->findLastMessageTimes(array_map(static fn (Conversation $c): int => $c->getUid(), $candidates));

        // Newest message first; a conversation whose last message carries no
        // time counts by when it was last changed.
        $lastMessageAt = static fn (Conversation $c): int => $times[$c->getUid()] ?? $c->getTstamp();
        usort($candidates, static fn (Conversation $a, Conversation $b): int => [$lastMessageAt($b), $b->getUid()] <=> [$lastMessageAt($a), $a->getUid()]);

        $locale = (string) $this->locales->createLocaleFromUserPreferences($backendUser);
        $now    = $this->now();

        $entries = [];
        foreach ($candidates as $conversation) {
            $entry = $this->entry($conversation, $this->describeTime($lastMessageAt($conversation), $locale, $now));
            if ($entry === null) {
                continue;
            }

            $entries[] = $entry;
            if (count($entries) === self::LIMIT) {
                break;
            }
        }

        return $entries;
    }

    /**
     * One entry, or null for a conversation about a page the user may not see.
     *
     * @param array{kind: string, text: string, datetime: string} $time
     *
     * @return array{uid: int, title: string, context: list<array{key: string, arguments: list<string>}>, page: array{uid: int, languageUid: int}|null, pageGone: bool, progress: array{current: int, total: int}|null, time: array{kind: string, text: string, datetime: string}}|null
     */
    private function entry(Conversation $conversation, array $time): ?array
    {
        $guided   = $conversation->getSkillIdentifier() !== '' && $this->processRuns->isProcessRun($conversation);
        $progress = $guided ? $conversation->getGuidedState()['progress'] : null;

        // Only a guided process is bound to its page; any other conversation
        // stores the page the browser showed with its last message, which is
        // where the editor was, not what the chat is about. Such a chat gets
        // no page, no page check and no page module.
        $viewContext = $conversation->getViewContext();
        $pageUid     = $guided ? $viewContext['pageId'] : 0;
        $page        = $pageUid > 0 ? $this->pages->findAccessible($pageUid) : null;
        $pageGone    = $pageUid > 0 && $page === null && $this->pages->isGone($pageUid);
        if ($pageUid > 0 && $page === null && !$pageGone) {
            return null;
        }

        // A deleted page is named as deleted, not by its title: the title is
        // gone with the page.
        $context = [];
        if ($pageGone) {
            $context[] = ['key' => self::LL . 'widget.context.pageGone', 'arguments' => []];
        } elseif ($page !== null) {
            $context[] = ['key' => self::LL . 'widget.context.page', 'arguments' => [$page['title']]];
        }

        if ($guided) {
            $context[] = ['key' => self::LL . 'widget.context.guidedTour', 'arguments' => []];
        }

        return [
            'uid'      => $conversation->getUid(),
            'title'    => ($guided ? $this->skillLabels->title($conversation->getSkillIdentifier()) : null) ?? $conversation->getTitle(),
            'context'  => $context,
            'page'     => $page === null ? null : ['uid' => $page['uid'], 'languageUid' => $viewContext['languageId']],
            'pageGone' => $pageGone,
            // The point the process is at, as the header shows it: only once
            // a point has started (0 is the analysis before the first), and
            // nothing once the process reported its end.
            'progress' => $progress !== null && !$progress['completed'] && $progress['current'] >= 1 ? ['current' => $progress['current'], 'total' => $progress['total']] : null,
            'time'     => $time,
        ];
    }

    /** The request's time, in the server's time zone. */
    private function now(): DateTimeImmutable
    {
        $now = $this->context->getPropertyFromAspect('date', 'full');
        $now = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable();

        return $now->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * @return list<JavaScriptModuleInstruction>
     */
    public function getJavaScriptModuleInstructions(): array
    {
        return [JavaScriptModuleInstruction::create('@netresearch/nr-mcp-agent/dashboard-widget.js')];
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return ['EXT:nr_mcp_agent/Resources/Public/Css/recent-chats-widget.css'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * When a message was written, as the list shows it: the time of day for
     * today and yesterday (the template adds "Heute, " / "Gestern, "), the day
     * and month for anything older, and the year as well once it is more than
     * a year back. Calendar days in the server's time zone, in the backend
     * user's language: a fixed d.m.Y reads wrong to everyone who does not use
     * it.
     *
     * @return array{kind: string, text: string, datetime: string}
     */
    private function describeTime(int $timestamp, string $locale, DateTimeImmutable $now): array
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

        return [
            'kind'     => $kind,
            'text'     => (string) $formatter->format($at),
            'datetime' => $at->format(DATE_ATOM),
        ];
    }
}
