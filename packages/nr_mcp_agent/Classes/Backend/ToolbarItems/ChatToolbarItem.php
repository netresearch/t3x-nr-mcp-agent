<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Backend\ToolbarItems;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\PageRenderer;

final class ChatToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly ConversationRepository $repository,
        private readonly ExtensionConfiguration $config,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        if ($this->config->getLlmTaskUid() === 0) {
            return false;
        }
        $allowed = $this->config->getAllowedGroupIds();
        if ($allowed === []) {
            return true;
        }
        $beUser = $this->getBackendUser();
        if ($beUser === null) {
            return false;
        }
        $userGroups = array_map('intval', explode(',', $beUser->user['usergroup'] ?? ''));
        return array_intersect($allowed, $userGroups) !== [];
    }

    public function getItem(): string
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-mcp-agent/toolbar/chat-panel.js');

        $count = 0;
        $beUser = $this->getBackendUser();
        if ($beUser !== null) {
            $count = $this->repository->countActiveByBeUser((int) $beUser->user['uid']);
        }
        $badgeStyle = $count > 0 ? '' : 'display:none';

        return '<button class="toolbar-item ai-chat-toolbar-btn" title="AI Chat">'
            . '<span class="toolbar-item-icon">'
            . '<typo3-backend-icon identifier="actions-message" size="small"></typo3-backend-icon>'
            . '</span>'
            . '<span class="toolbar-item-badge badge badge-warning ai-chat-badge" style="' . $badgeStyle . '">'
            . $count
            . '</span>'
            . '</button>';
    }

    public function hasDropDown(): bool
    {
        return false;
    }

    public function getDropDown(): string
    {
        return '';
    }

    /** @return array<string, string> */
    public function getAdditionalAttributes(): array
    {
        return ['class' => 'ai-chat-toolbar'];
    }

    public function getIndex(): int
    {
        return 25;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
