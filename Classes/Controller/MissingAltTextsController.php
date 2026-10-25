<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Controller;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextList;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
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
            $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-mcp-agent/alt-text-tabs.js');
            $this->pageRenderer->addCssFile('EXT:nr_mcp_agent/Resources/Public/Css/alt-text-list.css');
        }

        $language = $GLOBALS['LANG'] ?? null;
        $view->setTitle($language instanceof LanguageService ? $language->sL('LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_alttext.xlf:module.title') : 'Missing alt texts');
        $view->assignMultiple(['available' => $available, 'heading' => $title]);

        return $view->renderResponse('AltText/List');
    }
}
