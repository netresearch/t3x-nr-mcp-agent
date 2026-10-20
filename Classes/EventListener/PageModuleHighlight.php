<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\EventListener;

use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Loads the receiver of the chat's highlight message into the page module
 * (ADR-020). The receiver accepts one message shape from the backend window
 * that holds the chat panel and highlights one content element by TYPO3's own
 * id for it, with a frame and a badge whose text is this extension's own
 * label, never the message's (ADR-023).
 */
#[AsEventListener(identifier: 'nr-mcp-agent/page-module-highlight', event: ModifyPageLayoutContentEvent::class)]
final readonly class PageModuleHighlight
{
    public function __construct(
        private PageRenderer $pageRenderer,
    ) {}

    public function __invoke(): void
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-mcp-agent/page-highlight.js');
        // The badge's fixed text, in the backend user's language (ADR-023).
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf', 'highlight.');
    }
}
