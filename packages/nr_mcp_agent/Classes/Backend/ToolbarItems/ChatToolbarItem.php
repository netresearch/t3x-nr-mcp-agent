<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Backend\ToolbarItems;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\PageRenderer;

final class ChatToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
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
        // Admin users always have access (consistent with ChatApiController)
        if ($beUser->isAdmin()) {
            return true;
        }
        $userGroups = array_map('intval', explode(',', $beUser->user['usergroup'] ?? ''));
        return array_intersect($allowed, $userGroups) !== [];
    }

    public function getItem(): string
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-mcp-agent/toolbar/chat-panel.js');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf');

        // Badge count is updated client-side from the status endpoint
        // to avoid a DB query on every backend page load.
        return '<span class="toolbar-item-link ai-chat-toolbar-btn" role="button" title="AI Chat" tabindex="0">'
            . '<typo3-backend-icon identifier="actions-message" size="small"></typo3-backend-icon>'
            . '<span class="badge badge-warning ai-chat-badge" style="display:none">0</span>'
            . '</span>';
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
        return ['class' => 'toolbar-item ai-chat-toolbar'];
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
