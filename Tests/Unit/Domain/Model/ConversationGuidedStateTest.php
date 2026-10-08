<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Model;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The guided state a conversation keeps for the header and the page module
 * (ADR-020). What leaves it is what the browser renders and posts to the
 * page module, so a stored value of another shape is read as "none".
 */
#[CoversClass(Conversation::class)]
final class ConversationGuidedStateTest extends TestCase
{
    #[Test]
    public function aNewConversationHasNoGuidedState(): void
    {
        self::assertSame(['progress' => null, 'highlight' => null], (new Conversation())->getGuidedState());
    }

    #[Test]
    public function theStateSurvivesTheDatabaseRow(): void
    {
        $conversation = new Conversation();
        $conversation->setGuidedState(['label' => 'Über uns · Deutsch', 'current' => 2, 'total' => 5], ['table' => 'tt_content', 'uid' => 100]);

        $row = $conversation->toRow();
        self::assertStringContainsString('Über uns', $row['guided_state']);

        self::assertSame(
            ['progress' => ['label' => 'Über uns · Deutsch', 'current' => 2, 'total' => 5], 'highlight' => ['table' => 'tt_content', 'uid' => 100]],
            Conversation::fromRow($row)->getGuidedState(),
        );
    }

    #[Test]
    public function clearingBothLeavesAnEmptyColumn(): void
    {
        $conversation = new Conversation();
        $conversation->setGuidedState(['label' => 'x', 'current' => 1, 'total' => 1], null);
        $conversation->setGuidedState(null, null);

        self::assertSame('', $conversation->toRow()['guided_state']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function storedValuesOfAnotherShape(): iterable
    {
        yield 'not JSON' => ['{'];
        yield 'another table' => ['{"progress":null,"highlight":{"table":"be_users","uid":1}}'];
        yield 'a uid as text' => ['{"progress":null,"highlight":{"table":"tt_content","uid":"1 or 1=1"}}'];
        yield 'progress without a total' => ['{"progress":{"label":"x","current":1},"highlight":null}'];
    }

    #[Test]
    #[DataProvider('storedValuesOfAnotherShape')]
    public function aStoredValueOfAnotherShapeIsNone(string $json): void
    {
        self::assertSame(['progress' => null, 'highlight' => null], Conversation::fromRow(['uid' => 1, 'guided_state' => $json])->getGuidedState());
    }

    /** Only table and uid leave the model; anything else stored beside them is dropped. */
    #[Test]
    public function aStoredSelectorIsDropped(): void
    {
        $state = Conversation::fromRow(['uid' => 1, 'guided_state' => '{"progress":null,"highlight":{"table":"tt_content","uid":1,"selector":"body"}}'])->getGuidedState();

        self::assertSame(['table' => 'tt_content', 'uid' => 1], $state['highlight']);
    }
}
