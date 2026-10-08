<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

/**
 * What the AI assistant widgets share: the request, the options, and
 * rendering their template from templateVariables().
 *
 * A widget's constructor must keep the parameter name `$configuration`: the
 * dashboard's compiler pass sets that argument by name.
 */
abstract class AbstractAssistantWidget implements WidgetInterface, RequestAwareWidgetInterface
{
    private ServerRequestInterface $request;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly array $options = [],
    ) {}

    /**
     * What the template shows. Each widget answers "chat not available" here
     * when ChatToolbarItem::checkAccess() says so.
     *
     * @return array<string, mixed>
     */
    abstract public function templateVariables(): array;

    /** The template under Resources/Private/Templates/, without the extension. */
    abstract protected function templateName(): string;

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

        return $view->render($this->templateName());
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
