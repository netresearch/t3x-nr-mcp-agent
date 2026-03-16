<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Backend\ToolbarItems;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\PageRenderer;

class ChatToolbarItemTest extends TestCase
{
    private ConversationRepository $repository;
    private ExtensionConfiguration $config;
    private PageRenderer $pageRenderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(ConversationRepository::class);
        $this->config = $this->createMock(ExtensionConfiguration::class);
        $this->pageRenderer = $this->createMock(PageRenderer::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function createSubject(): ChatToolbarItem
    {
        return new ChatToolbarItem($this->repository, $this->config, $this->pageRenderer);
    }

    private function setUpBeUser(int $uid = 1, string $usergroup = '1,2', bool $isAdmin = false): void
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->user = ['uid' => $uid, 'usergroup' => $usergroup];
        $beUser->method('isAdmin')->willReturn($isAdmin);
        $GLOBALS['BE_USER'] = $beUser;
    }

    #[Test]
    public function implementsToolbarInterfaces(): void
    {
        $subject = $this->createSubject();
        self::assertInstanceOf(ToolbarItemInterface::class, $subject);
        self::assertInstanceOf(RequestAwareToolbarItemInterface::class, $subject);
    }

    #[Test]
    public function getItemRendersButtonWithBadgeContainer(): void
    {
        $subject = $this->createSubject();
        $html = $subject->getItem();

        self::assertStringContainsString('ai-chat-toolbar-btn', $html);
        self::assertStringContainsString('ai-chat-badge', $html);
        // Badge starts hidden (count updated client-side)
        self::assertStringContainsString('display:none', $html);
    }

    #[Test]
    public function getItemLoadsJsModule(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('loadJavaScriptModule')
            ->with('@netresearch/nr-mcp-agent/toolbar/chat-panel.js');

        $subject = $this->createSubject();
        $subject->getItem();
    }

    #[Test]
    public function checkAccessReturnsTrueWhenNoGroupRestriction(): void
    {
        $this->config->method('getLlmTaskUid')->willReturn(1);
        $this->config->method('getAllowedGroupIds')->willReturn([]);

        $subject = $this->createSubject();
        self::assertTrue($subject->checkAccess());
    }

    #[Test]
    public function checkAccessReturnsFalseWhenNoTask(): void
    {
        $this->config->method('getLlmTaskUid')->willReturn(0);

        $subject = $this->createSubject();
        self::assertFalse($subject->checkAccess());
    }

    #[Test]
    public function checkAccessReturnsTrueWhenUserInAllowedGroup(): void
    {
        $this->setUpBeUser(1, '3,5');
        $this->config->method('getLlmTaskUid')->willReturn(1);
        $this->config->method('getAllowedGroupIds')->willReturn([5, 7]);

        $subject = $this->createSubject();
        self::assertTrue($subject->checkAccess());
    }

    #[Test]
    public function checkAccessReturnsFalseWhenUserNotInAllowedGroup(): void
    {
        $this->setUpBeUser(1, '3,4');
        $this->config->method('getLlmTaskUid')->willReturn(1);
        $this->config->method('getAllowedGroupIds')->willReturn([5, 7]);

        $subject = $this->createSubject();
        self::assertFalse($subject->checkAccess());
    }

    #[Test]
    public function checkAccessReturnsTrueForAdminEvenIfNotInGroup(): void
    {
        $this->setUpBeUser(1, '3,4', isAdmin: true);
        $this->config->method('getLlmTaskUid')->willReturn(1);
        $this->config->method('getAllowedGroupIds')->willReturn([5, 7]);

        $subject = $this->createSubject();
        self::assertTrue($subject->checkAccess());
    }

    #[Test]
    public function checkAccessReturnsFalseWhenNoBeUserAndGroupsRestricted(): void
    {
        // No BE_USER set
        $this->config->method('getLlmTaskUid')->willReturn(1);
        $this->config->method('getAllowedGroupIds')->willReturn([5]);

        $subject = $this->createSubject();
        self::assertFalse($subject->checkAccess());
    }

    #[Test]
    public function hasDropDownReturnsFalse(): void
    {
        $subject = $this->createSubject();
        self::assertFalse($subject->hasDropDown());
    }

    #[Test]
    public function getIndexReturns25(): void
    {
        $subject = $this->createSubject();
        self::assertSame(25, $subject->getIndex());
    }

    #[Test]
    public function getAdditionalAttributesContainsToolbarClass(): void
    {
        $subject = $this->createSubject();
        $attrs = $subject->getAdditionalAttributes();
        self::assertStringContainsString('ai-chat-toolbar', $attrs['class']);
    }
}
