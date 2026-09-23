<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use IntlDateFormatter;
use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

/**
 * "AI Chat" on the TYPO3 dashboard (NEXT-172): the user's most recent
 * conversations and a way into the chat.
 *
 * Registered only when EXT:dashboard is installed (Configuration/Services.php);
 * the extension does not require it.
 *
 * Who may use the chat is decided exactly as for the toolbar button — the
 * same object answers — so a widget that core's per-group widget permissions
 * let a user place never becomes a way into a chat they were not given. For
 * such a user it says that the chat is not available.
 *
 * A conversation opens in the floating panel, which lives in the backend
 * frame around the dashboard (ADR-011); without the panel, the link opens the
 * chat module (dashboard-widget.js).
 */
final class AiChatWidget implements WidgetInterface, RequestAwareWidgetInterface, JavaScriptInterface
{
    /** How many conversations the widget lists. */
    public const LIMIT = 5;

    private ServerRequestInterface $request;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly ConversationRepository $repository,
        private readonly ChatToolbarItem $chatAccess,
        private readonly Locales $locales,
        private readonly array $options = [],
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $view = $this->backendViewFactory->create($this->request, ['typo3/cms-dashboard', 'netresearch/nr-mcp-agent']);
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
     * @return array{available: bool, conversations: list<array{uid: int, title: string, status: string, pinned: bool, tstamp: int, date: string}>}
     */
    public function templateVariables(): array
    {
        $available = $this->chatAccess->checkAccess();

        return [
            'available' => $available,
            'conversations' => $available ? $this->recentConversations() : [],
        ];
    }

    /**
     * @return list<array{uid: int, title: string, status: string, pinned: bool, tstamp: int, date: string}>
     */
    private function recentConversations(): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $rawUid = $backendUser instanceof BackendUserAuthentication ? ($backendUser->user['uid'] ?? null) : null;
        $uid = is_numeric($rawUid) ? (int) $rawUid : 0;
        if ($uid === 0) {
            return [];
        }

        // Newest first, as the repository returns them: the widget answers
        // "where was I?", which pinning does not change.
        $conversations = array_slice($this->repository->findByBeUser($uid), 0, self::LIMIT);

        // In the backend user's language: a fixed d.m.Y reads wrong to
        // everyone who does not use it.
        $formatter = new IntlDateFormatter(
            (string) $this->locales->createLocaleFromUserPreferences($backendUser instanceof BackendUserAuthentication ? $backendUser : null),
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::SHORT,
            date_default_timezone_get(),
        );

        return array_map(static fn(Conversation $c): array => [
            'uid' => $c->getUid(),
            'title' => $c->getTitle(),
            'status' => $c->getStatus()->value,
            'pinned' => $c->isPinned(),
            'tstamp' => $c->getTstamp(),
            'date' => (string) $formatter->format($c->getTstamp()),
        ], $conversations);
    }

    /**
     * @return list<JavaScriptModuleInstruction>
     */
    public function getJavaScriptModuleInstructions(): array
    {
        return [JavaScriptModuleInstruction::create('@netresearch/nr-mcp-agent/dashboard-widget.js')];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
