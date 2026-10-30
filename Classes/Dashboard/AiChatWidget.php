<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use LogicException;
use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ConversationList\ConversationListItem;
use Netresearch\NrMcpAgent\Service\ConversationList\ConversationListPresenter;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

/**
 * "Recent chats" on the TYPO3 dashboard: the user's last conversations, each
 * with what it was about, where it stands and when it last moved, and a way
 * back into it. Full width, so each row has room for all of that.
 *
 * Registered only when EXT:dashboard is installed (Configuration/Services.php);
 * the extension does not require it.
 *
 * Who may use the chat is decided exactly as for the toolbar button — the
 * same object answers — so a widget that core's per-group widget permissions
 * let a user place never becomes a way into a chat they were not given. For
 * such a user it says that the chat is not available.
 *
 * Each row shows what every list of conversations shows
 * (ConversationListPresenter, ADR-027): the title, the context the title does
 * not name, the point a guided tour is at, the status and when the last
 * message was written. Conversations without a message are not listed.
 *
 * A conversation opens in the floating panel, which lives in the backend
 * frame around the dashboard (ADR-011), in the user's layout (ADR-024); a
 * guided process also shows its page in the page module. Without the panel,
 * the link opens the chat module (dashboard-widget.js), and "Show all chats"
 * always does.
 */
final class AiChatWidget implements WidgetInterface, RequestAwareWidgetInterface, JavaScriptInterface, AdditionalCssInterface
{
    /** How many conversations the widget lists. */
    public const LIMIT = 10;

    /**
     * How many conversations are read to fill the list: it is ordered by the
     * last message, which the changed-at order the candidates are read in only
     * approximates (pinning or renaming moves it too).
     */
    public const WINDOW = 50;

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
        private readonly ConversationListPresenter $presenter,
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
     * @return array{available: bool, conversations: list<ConversationListItem>}
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
     * @return list<ConversationListItem>
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

        return array_slice($this->presenter->present($candidates, $backendUser), 0, self::LIMIT);
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
}
