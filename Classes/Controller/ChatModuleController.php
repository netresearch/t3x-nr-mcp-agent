<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;

final readonly class ChatModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private PageRenderer $pageRenderer,
        private ExtensionConfiguration $config,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-mcp-agent/chat-app.js');
        $this->pageRenderer->addCssFile('EXT:nr_mcp_agent/Resources/Public/Css/chat.css');

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('AI Chat');
        $view->assignMultiple([
            'maxMessageLength' => $this->config->getMaxMessageLength(),
        ]);

        return $view->renderResponse('Chat/Index');
    }
}
