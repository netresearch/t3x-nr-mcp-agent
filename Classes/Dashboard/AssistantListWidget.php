<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;

/**
 * An assistant widget whose actions start the chat: rows with a chevron,
 * number tiles, a button grid (assistant-widgets.css), and links that open
 * the chat beside the page module where the panel is available
 * (chat-start.js).
 */
abstract class AssistantListWidget extends AbstractAssistantWidget implements JavaScriptInterface, AdditionalCssInterface
{
    /**
     * @return list<JavaScriptModuleInstruction>
     */
    public function getJavaScriptModuleInstructions(): array
    {
        return [JavaScriptModuleInstruction::create('@netresearch/nr-mcp-agent/chat-start.js')];
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return ['EXT:nr_mcp_agent/Resources/Public/Css/assistant-widgets.css'];
    }
}
