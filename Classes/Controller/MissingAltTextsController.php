<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Controller;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Service\AltText\AltTextGate;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextList;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * The list "Fehlende Alt-Texte" (ADR-026), a page of the AI assistant's
 * module: every image the editor may fix that has no alt text, in tabs by
 * origin. It is behind the same gate as the chat and its dashboard widgets
 * (ChatToolbarItem::checkAccess(), ADR-009).
 *
 * Query parameters: `tab`, the type whose tab is open, and `page`, that
 * tab's page.
 */
final readonly class MissingAltTextsController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private PageRenderer $pageRenderer,
        private ChatToolbarItem $chatAccess,
        private MissingAltTextList $list,
        // Without them the list offers no "Optimieren" (ADR-026).
        private ?AltTextGate $gate = null,
        private ?ChatStartUriBuilder $chatStart = null,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $type  = is_string($query['tab'] ?? null) ? $query['tab'] : '';
        $page  = is_numeric($query['page'] ?? null) ? (int) $query['page'] : 1;

        $view      = $this->moduleTemplateFactory->create($request);
        $available = $this->chatAccess->checkAccess();
        $title     = $this->list->title(0);
        if ($available) {
            $data = $this->list->build($type, $page);
            $view->assignMultiple($data);
            $title = $data['title'];
            $view->assignMultiple([
                'optimise'     => $this->optimise(),
                'titles'       => ['none' => $this->list->title(0), 'one' => $this->list->title(1), 'other' => $this->list->titlePattern()],
                'countPattern' => $this->list->countPattern(),
            ]);
            $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-mcp-agent/alt-text-tabs.js');
            $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-mcp-agent/alt-text-optimise.js');
            $this->pageRenderer->addInlineLanguageLabelFile('EXT:nr_mcp_agent/Resources/Private/Language/locallang_alttext.xlf', 'status.');
            $this->pageRenderer->addCssFile('EXT:nr_mcp_agent/Resources/Public/Css/alt-text-list.css');
        }

        $language = $GLOBALS['LANG'] ?? null;
        $view->setTitle($language instanceof LanguageService ? $language->sL('LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_alttext.xlf:module.title') : 'Missing alt texts');
        $view->assignMultiple(['available' => $available, 'heading' => $title]);

        return $view->renderResponse('AltText/List');
    }

    /**
     * "Optimieren" for the user (ADR-026): available, or shown with the
     * reason it is not, which the button points to (aria-describedby; a
     * disabled button could not be focused, so its reason would not reach
     * keyboard users). The chat module's address takes the image where the
     * chat panel is not there.
     *
     * @return array{available: bool, note: string, label: string, labelActive: string, chatUri: string, listUri: string}
     */
    private function optimise(): array
    {
        $user     = $GLOBALS['BE_USER'] ?? null;
        $language = $GLOBALS['LANG'] ?? null;
        $refusal  = !$user instanceof BackendUserAuthentication || !$this->gate instanceof AltTextGate ? 'tools' : $this->gate->refusal($user);
        $text     = static fn (string $key): string => $language instanceof LanguageService ? $language->sL($key) : $key;

        return [
            'available'   => $refusal === null,
            'note'        => $refusal === null ? '' : $text('LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:' . AltTextGate::answer($refusal)[1]),
            'label'       => $text('LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_alttext.xlf:action.optimise'),
            'labelActive' => $text('LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_alttext.xlf:action.inChat'),
            'chatUri'     => $this->chatStart?->forAltText() ?? '',
            'listUri'     => $this->list->uri(),
        ];
    }
}
