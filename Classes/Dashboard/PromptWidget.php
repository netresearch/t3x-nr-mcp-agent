<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * "AI Assistant": a free question straight into the chat, without choosing a
 * tour or a page first.
 *
 * The question opens the chat panel as a column on a new conversation and is
 * its first message, as typed (prompt-widget.js). The form's own target is the
 * chat module, the way in where the panel is not available; the question is
 * not part of it, so a link can never send a message on the user's behalf.
 *
 * Who may use the chat is decided as for the toolbar button and the other
 * assistant widgets; for anyone else the widget says the chat is not
 * available.
 */
final class PromptWidget extends AbstractAssistantWidget implements JavaScriptInterface, AdditionalCssInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        BackendViewFactory $backendViewFactory,
        private readonly ChatToolbarItem $chatAccess,
        private readonly ChatStartUriBuilder $chatStart,
        array $options = [],
    ) {
        parent::__construct($configuration, $backendViewFactory, $options);
    }

    protected function templateName(): string
    {
        return 'Widget/PromptWidget';
    }

    /**
     * The form needs the chat module's URL and its hidden fields only: it
     * starts no skill and names no page.
     *
     * @return array{available: bool, form: array{action: string, hidden: array<string, string>}|null, idPrefix: string}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'form' => null, 'idPrefix' => ''];
        }

        $target = $this->chatStart->formTarget();

        return [
            'available' => true,
            'form'      => ['action' => $target['action'], 'hidden' => $target['hidden']],
            // The same widget may sit on a dashboard twice; its label must
            // still point at its own field.
            'idPrefix' => 'nr-mcp-agent-prompt-' . bin2hex(random_bytes(4)),
        ];
    }

    /**
     * @return list<JavaScriptModuleInstruction>
     */
    public function getJavaScriptModuleInstructions(): array
    {
        return [JavaScriptModuleInstruction::create('@netresearch/nr-mcp-agent/prompt-widget.js')];
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return ['EXT:nr_mcp_agent/Resources/Public/Css/prompt-widget.css'];
    }
}
