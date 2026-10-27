<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\RunStateRepository;
use Netresearch\NrMcpAgent\EventListener\PageModuleHighlight;
use Netresearch\NrMcpAgent\Service\ConversationPageHighlightPolicy;
use Netresearch\NrMcpAgent\Service\GuidedStateLinker;
use Netresearch\NrMcpAgent\Service\HighlightTargetPolicyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Which highlight a conversation keeps (ADR-020, ADR-023): the element on the
 * conversation's page until nr-llm registers a run's subject targets, behind
 * a seam another policy replaces; and the page module's fixed badge label.
 */
#[CoversClass(ConversationPageHighlightPolicy::class)]
#[CoversClass(GuidedStateLinker::class)]
#[CoversClass(PageModuleHighlight::class)]
final class HighlightTargetPolicyTest extends TestCase
{
    private function onPage(int $pageUid): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(3);
        $conversation->setViewContext($pageUid, 'web_layout');

        return $conversation;
    }

    #[Test]
    public function anElementOnTheConversationsPageIsAllowed(): void
    {
        $policy = new ConversationPageHighlightPolicy();

        self::assertTrue($policy->allows($this->onPage(20), 'tt_content', 100, 20));
        self::assertFalse($policy->allows($this->onPage(20), 'tt_content', 100, 30), 'another page');
        self::assertFalse($policy->allows($this->onPage(0), 'tt_content', 100, 0), 'no page');
        self::assertFalse($policy->allows($this->onPage(20), 'be_users', 20, 20), 'neither a content element nor the page');
    }

    /** A property of the page counts for the conversation's own page only (NEXT-221). */
    #[Test]
    public function thePageItselfIsAllowedForItsProperties(): void
    {
        $policy = new ConversationPageHighlightPolicy();

        self::assertTrue($policy->allows($this->onPage(20), 'pages', 20, 20));
        self::assertFalse($policy->allows($this->onPage(20), 'pages', 30, 30), 'another page');
        self::assertFalse($policy->allows($this->onPage(20), 'pages', 30, 20), "a page below the conversation's page");
        self::assertFalse($policy->allows($this->onPage(0), 'pages', 0, 0), 'no page');
    }

    private function linkedHighlight(?HighlightTargetPolicyInterface $policy, int $pid): ?array
    {
        $runState = $this->createMock(RunStateRepository::class);
        $runState->method('find')->willReturn(['progress' => null, 'highlight' => ['table' => 'tt_content', 'uid' => 100, 'pid' => $pid]]);
        $conversation = $this->onPage(20);

        (new GuidedStateLinker($runState, $policy))->absorb($conversation, 'run-1');

        return $conversation->getGuidedState()['highlight'];
    }

    #[Test]
    public function withoutAPolicyTheConversationsPageDecides(): void
    {
        self::assertSame(['table' => 'tt_content', 'uid' => 100], $this->linkedHighlight(null, 20));
        self::assertNull($this->linkedHighlight(null, 30));
    }

    /** The seam: a policy that narrows the targets has the last word. */
    #[Test]
    public function aPolicyDecidesWhichHighlightIsKept(): void
    {
        $denies = $this->createMock(HighlightTargetPolicyInterface::class);
        $denies->expects(self::once())->method('allows')->with(self::isInstanceOf(Conversation::class), 'tt_content', 100, 20)->willReturn(false);

        self::assertNull($this->linkedHighlight($denies, 20));
    }

    /**
     * The tool names a page property without a page: the linker gives it the
     * conversation's page, and keeps nothing for a conversation without one.
     */
    #[Test]
    public function aPagePropertyGetsTheConversationsPage(): void
    {
        $runState = $this->createMock(RunStateRepository::class);
        $runState->method('find')->willReturn(['progress' => null, 'highlight' => ['table' => 'pages', 'field' => 'description', 'uid' => 99]]);

        $conversation = $this->onPage(20);
        (new GuidedStateLinker($runState))->absorb($conversation, 'run-1');
        self::assertSame(['table' => 'pages', 'uid' => 20, 'field' => 'description'], $conversation->getGuidedState()['highlight']);

        $withoutPage = $this->onPage(0);
        (new GuidedStateLinker($runState))->absorb($withoutPage, 'run-1');
        self::assertNull($withoutPage->getGuidedState()['highlight']);
    }

    #[Test]
    public function aPolicyDecidesWhichPagePropertyIsKept(): void
    {
        $runState = $this->createMock(RunStateRepository::class);
        $runState->method('find')->willReturn(['progress' => null, 'highlight' => ['table' => 'pages', 'field' => 'description']]);
        $denies = $this->createMock(HighlightTargetPolicyInterface::class);
        $denies->expects(self::once())->method('allows')->with(self::isInstanceOf(Conversation::class), 'pages', 20, 20)->willReturn(false);

        $conversation = $this->onPage(20);
        (new GuidedStateLinker($runState, $denies))->absorb($conversation, 'run-1');

        self::assertNull($conversation->getGuidedState()['highlight']);
    }

    #[Test]
    public function thePageModuleLoadsTheBadgesLabel(): void
    {
        $renderer = $this->createMock(PageRenderer::class);
        $renderer->expects(self::once())->method('loadJavaScriptModule')->with('@netresearch/nr-mcp-agent/page-highlight.js');
        $renderer->expects(self::once())->method('addInlineLanguageLabelFile')
            ->with('EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf', 'highlight.');

        (new PageModuleHighlight($renderer))();
    }
}
