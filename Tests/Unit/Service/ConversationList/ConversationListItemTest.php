<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\ConversationList;

use Netresearch\NrMcpAgent\Service\ConversationList\ConversationListItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The title a list cuts short and the suffix it keeps (ADR-027). The
 * presenter only ever builds the two together (withTitleSuffix()); the base
 * still checks that the suffix is at the end, as splitTitle() in
 * conversation-list.js does, so a title and a suffix that do not belong
 * together give the whole title, never a cut one.
 */
final class ConversationListItemTest extends TestCase
{
    #[Test]
    public function theBaseIsTheTitleBeforeTheSuffix(): void
    {
        $item = $this->item('SEO optimieren · Über uns')->withTitleSuffix('29. Okt., 11:27:01');

        self::assertSame('SEO optimieren · Über uns · 29. Okt., 11:27:01', $item->displayTitle);
        self::assertSame('SEO optimieren · Über uns', $item->getTitleBase());
    }

    #[Test]
    public function aSuffixThatIsNotAtTheEndLeavesTheTitleWhole(): void
    {
        self::assertSame('Mein Titel', $this->item('Mein Titel', '29. Okt., 11:27:01')->getTitleBase());
        // The suffix alone is no title to cut.
        self::assertSame(' · 11:27', $this->item(' · 11:27', '11:27')->getTitleBase());
        self::assertSame('SEO optimieren', $this->item('SEO optimieren')->getTitleBase());
    }

    private function item(string $displayTitle, string $titleSuffix = ''): ConversationListItem
    {
        return new ConversationListItem(
            uid: 1,
            title: $displayTitle,
            displayTitle: $displayTitle,
            fullTitle: $displayTitle,
            titleKind: 'own',
            context: [],
            contextLabel: '',
            page: null,
            pageGone: false,
            skillTitle: null,
            guided: false,
            progress: null,
            createdAt: '',
            lastActivityTimestamp: 0,
            lastActivity: ['kind' => 'date', 'text' => '', 'label' => '', 'absolute' => '', 'datetime' => ''],
            status: 'idle',
            statusTone: 'neutral',
            statusLabel: 'Ready',
            messageCount: 1,
            pinned: false,
            titleSuffix: $titleSuffix,
        );
    }
}
