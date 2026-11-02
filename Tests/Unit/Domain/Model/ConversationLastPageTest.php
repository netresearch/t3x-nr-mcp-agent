<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Model;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A turn sent where no page is shown keeps the page the conversation was
 * about, apart from the selected page (ADR-030).
 */
#[CoversClass(Conversation::class)]
final class ConversationLastPageTest extends TestCase
{
    #[Test]
    public function aTurnWithoutAPageKeepsTheLastPageApartFromTheSelection(): void
    {
        $conversation = new Conversation();
        $conversation->setViewContext(157, 'web_layout', 0);
        self::assertSame(0, $conversation->getLastPageId(), 'a selected page is no last page');

        $conversation->setViewContext(0, 'nr_mcp_agent_chat');

        self::assertSame(['pageId' => 0, 'module' => 'nr_mcp_agent_chat', 'languageId' => -1], $conversation->getViewContext());
        self::assertSame(157, $conversation->getLastPageId());

        // It survives further turns without a page and the stored row.
        $conversation->setViewContext(0, '');
        $reloaded = Conversation::fromRow($conversation->toRow());
        self::assertSame(157, $reloaded->getLastPageId());
        self::assertSame(0, $reloaded->getViewContext()['pageId']);
    }

    #[Test]
    public function anotherSelectedPageReplacesIt(): void
    {
        $conversation = new Conversation();
        $conversation->setViewContext(157, '');
        $conversation->setViewContext(0, '');
        $conversation->setViewContext(10, '');

        self::assertSame(0, $conversation->getLastPageId());
        $conversation->setViewContext(0, '');
        self::assertSame(10, $conversation->getLastPageId());
    }

    #[Test]
    public function aConversationThatNeverHadAPageHasNone(): void
    {
        $conversation = new Conversation();
        $conversation->setViewContext(0, 'dashboard');

        self::assertSame(0, $conversation->getLastPageId());
    }
}
