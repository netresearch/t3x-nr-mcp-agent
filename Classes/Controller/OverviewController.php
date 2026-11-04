<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Controller;

use DateTimeImmutable;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Overview\ChatOverview;
use Netresearch\NrMcpAgent\Service\Overview\OverviewPeriod;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * The AI Chat module's first page: what the chat is, whether it works for the
 * viewer, how it is configured, how it was used, and where to act (ADR-031).
 * The chat itself is the panel from the top bar, and full-page at the
 * sub-route {@see ChatStartUriBuilder::ROUTE}.
 */
final readonly class OverviewController
{
    /**
     * Parameters only the chat reads. A link built before the chat moved to
     * its sub-route still carries them, and still reaches the chat.
     */
    public const CHAT_PARAMETERS = ['conversation', 'skill', 'pageUid', 'languageUid', 'altTextReference'];

    private const LL = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_overview.xlf:';

    /** Modules the page links to, shown only to whoever may open them. */
    private const LINKED_MODULES = [
        'nrllm_tasks', 'nrllm_configurations', 'nrllm_models', 'nrllm_tools', 'nrllm_mcp',
        'nrllm_runs', 'nrllm_analytics', 'nrllm_aitasks', 'nrllm_skills',
    ];

    /** The extension settings module, by its 13.4 and its 14.3 identifier. */
    private const SETTINGS_MODULES = ['system_settings', 'tools_toolssettings'];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ChatOverview $overview,
        private UriBuilder $uriBuilder,
        private ModuleProvider $moduleProvider,
        private PageRenderer $pageRenderer,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $chat  = array_intersect_key($query, array_flip(self::CHAT_PARAMETERS));
        if ($chat !== []) {
            return new RedirectResponse((string) $this->uriBuilder->buildUriFromRoute(ChatStartUriBuilder::ROUTE, $chat), 303);
        }

        $user = $GLOBALS['BE_USER'] ?? null;
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->label('title', 'AI Chat'));
        if (!$user instanceof BackendUserAuthentication) {
            return $view->renderResponse('Overview/Index');
        }

        // "Open the chat" opens the panel where the user keeps it, and follows
        // its link to the full-page chat where the panel is not available.
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-mcp-agent/chat-start.js');
        $period = OverviewPeriod::fromRequestValue($query['period'] ?? null, new DateTimeImmutable());
        $view->assignMultiple($this->overview->build($user, $period));
        $view->assignMultiple([
            'periodLinks' => $this->periodLinks(),
            'links'       => $this->links($user),
            'chatUri'     => $this->uri(ChatStartUriBuilder::ROUTE),
            'overviewUri' => $this->uri('nr_mcp_agent_chat'),
            'altTextsUri' => $this->uri('nr_mcp_agent_chat.missingAltTexts'),
        ]);

        return $view->renderResponse('Overview/Index');
    }

    /**
     * @return array<string, string> module identifier → URI, for the modules this user may open
     */
    private function links(BackendUserAuthentication $user): array
    {
        $links = [];
        foreach (self::LINKED_MODULES as $identifier) {
            if ($this->moduleProvider->isModuleRegistered($identifier) && $this->moduleProvider->accessGranted($identifier, $user)) {
                $links[$identifier] = $this->uri($identifier);
            }
        }

        foreach (self::SETTINGS_MODULES as $identifier) {
            if ($this->moduleProvider->isModuleRegistered($identifier) && $this->moduleProvider->accessGranted($identifier, $user)) {
                $links['settings'] = $this->uri($identifier);
                break;
            }
        }

        return array_filter($links, static fn (string $uri): bool => $uri !== '');
    }

    /**
     * One link per period: links carry the route token, which a GET form
     * would drop.
     *
     * @return array<int, string> days → URI
     */
    private function periodLinks(): array
    {
        $links = [];
        foreach (OverviewPeriod::CHOICES as $days) {
            $links[$days] = (string) $this->uriBuilder->buildUriFromRoute('nr_mcp_agent_chat', ['period' => $days]);
        }

        return $links;
    }

    private function uri(string $route): string
    {
        try {
            return (string) $this->uriBuilder->buildUriFromRoute($route);
        } catch (Throwable) {
            return '';
        }
    }

    private function label(string $key, string $fallback): string
    {
        $language = $GLOBALS['LANG'] ?? null;

        $label = $language instanceof LanguageService ? $language->sL(self::LL . $key) : '';

        return $label !== '' ? $label : $fallback;
    }
}
