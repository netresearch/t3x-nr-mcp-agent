<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Model;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Enum\ProposalOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The process card a decision answers, and what became of each proposal
 * (ADR-023), as the conversation row keeps them.
 */
#[CoversClass(Conversation::class)]
final class ConversationCardOutcomeTest extends TestCase
{
    private const CARD = ['tool' => 'update_page_metadata', 'table' => 'pages', 'uid' => 3, 'fields' => ['description']];

    #[Test]
    public function theCardTravelsWithTheDecisionAndGoesWithIt(): void
    {
        $conversation = new Conversation();
        $conversation->setApprovalCard(self::CARD);

        self::assertSame(self::CARD, $conversation->getApprovalCard());
        self::assertSame(self::CARD, Conversation::fromRow($conversation->toRow())->getApprovalCard(), 'it is stored with the row');

        $conversation->clearApprovalDecision();
        self::assertNull($conversation->getApprovalCard());
    }

    #[Test]
    public function anOutcomeIsRecordedWhereTheTranscriptStandsAndSurvivesTheRow(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, 'Seite prüfen');
        $conversation->appendMessage(MessageRole::Assistant, 'Vorschlag 1');
        $conversation->appendCardOutcome(ProposalOutcome::Applied, self::CARD);
        $conversation->appendMessage(MessageRole::Assistant, 'Vorschlag 2');
        $conversation->appendCardOutcome(ProposalOutcome::Skipped, ['tool' => 'create_content_element_draft', 'table' => '', 'uid' => 0, 'fields' => []]);

        $expected = [
            ['outcome' => 'applied', 'after' => 2] + self::CARD,
            ['outcome' => 'skipped', 'after' => 3, 'tool' => 'create_content_element_draft', 'table' => '', 'uid' => 0, 'fields' => []],
        ];
        self::assertSame($expected, $conversation->getCardOutcomes());
        self::assertSame($expected, Conversation::fromRow($conversation->toRow())->getCardOutcomes());
    }

    #[Test]
    public function aStoredEntryOfAnotherShapeIsLeftOut(): void
    {
        $conversation = Conversation::fromRow(['card_outcomes' => json_encode([
            ['outcome' => 'applied', 'after' => 1] + self::CARD,
            ['outcome' => 'unknown', 'after' => 1] + self::CARD,
            ['outcome' => 'applied', 'after' => '1'] + self::CARD,
            ['outcome' => 'applied', 'after' => 1, 'tool' => 'x'],
        ], JSON_THROW_ON_ERROR)]);

        self::assertCount(1, $conversation->getCardOutcomes());
    }
}
