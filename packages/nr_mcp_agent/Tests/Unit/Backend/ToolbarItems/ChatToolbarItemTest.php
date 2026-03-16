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

class ChatToolbarItemTest extends TestCase
{
    private ConversationRepository $repository;
    private ExtensionConfiguration $config;
    private \TYPO3\CMS\Core\Page\PageRenderer $pageRenderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(ConversationRepository::class);
        $this->config = $this->createMock(ExtensionConfiguration::class);
        $this->pageRenderer = $this->createMock(\TYPO3\CMS\Core\Page\PageRenderer::class);
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

    private function setUpBeUser(int $uid = 1, string $usergroup = '1,2'): void
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->user = ['uid' => $uid, 'usergroup' => $usergroup];
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
    public function getItemRendersButtonWithBadge(): void
    {
        $this->setUpBeUser(1, '1,2');
        $this->repository->method('countActiveByBeUser')->with(1)->willReturn(3);

        $subject = $this->createSubject();
        $html = $subject->getItem();

        self::assertStringContainsString('ai-chat-toolbar-btn', $html);
        self::assertStringContainsString('3', $html);
    }

    #[Test]
    public function getItemHidesBadgeWhenCountIsZero(): void
    {
        $this->setUpBeUser(1, '1,2');
        $this->repository->method('countActiveByBeUser')->with(1)->willReturn(0);

        $subject = $this->createSubject();
        $html = $subject->getItem();

        self::assertStringContainsString('display:none', $html);
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
    public function hasDropDownReturnsFalse(): void
    {
        $subject = $this->createSubject();
        self::assertFalse($subject->hasDropDown());
    }
}
