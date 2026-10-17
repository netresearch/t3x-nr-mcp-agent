<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\OpenPoint;

use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrMcpAgent\Service\OpenPoint\NrLlmCardTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The card's target comes from nr-llm's structured `pendingTarget` (nr-llm
 * PR 1024), never from the preview text.
 */
#[CoversClass(NrLlmCardTarget::class)]
final class NrLlmCardTargetTest extends TestCase
{
    private static function requireTarget(bool $present): void
    {
        if (property_exists(PendingCallView::class, 'pendingTarget') !== $present) {
            self::markTestSkipped($present
                ? 'Needs nr-llm 0.41 (PendingCallView::$pendingTarget, nr-llm PR 1024).'
                : 'Pins the fallback for an nr-llm whose card carries no target.');
        }
    }

    /**
     * @param list<PendingCallView> $calls
     */
    private static function view(array $calls): WaitingRunView
    {
        return new WaitingRunView('run-1', WaitingRunView::MODE_APPROVAL, 0, 'Chat', 'digest', $calls);
    }

    #[Test]
    public function withoutATargetOnTheViewThereIsNone(): void
    {
        self::requireTarget(false);

        self::assertNull(NrLlmCardTarget::of(self::view([new PendingCallView('update_content_element', '{"uid":12}', true, ['bodytext: old → new'])])));
    }

    #[Test]
    public function theTargetIsNrLlmsRecordAndFields(): void
    {
        self::requireTarget(true);
        $call = new PendingCallView('update_content_element', '{}', true, pendingTarget: new PendingWriteTarget(new RecordReference('tt_content', 12), ['header', 'bodytext']));

        $target = NrLlmCardTarget::of(self::view([$call]));

        self::assertNotNull($target);
        self::assertSame('tt_content', $target->table);
        self::assertSame(12, $target->uid);
        self::assertSame(['bodytext', 'header'], $target->fields);
    }

    /** A create has no uid yet, so nr-llm names no target and nothing is keyed. */
    #[Test]
    public function aCallWithoutATargetHasNone(): void
    {
        self::requireTarget(true);

        self::assertNull(NrLlmCardTarget::of(self::view([new PendingCallView('create_content_element_draft', '{}', true, pendingTarget: null)])));
    }

    /** One decision covers the whole pending set; only a one-call card is keyed. */
    #[Test]
    public function aCardWithSeveralCallsHasNone(): void
    {
        self::requireTarget(true);
        $target = new PendingWriteTarget(new RecordReference('tt_content', 12), ['bodytext']);

        self::assertNull(NrLlmCardTarget::of(self::view([
            new PendingCallView('update_content_element', '{}', true, pendingTarget: $target),
            new PendingCallView('update_content_element', '{}', true, pendingTarget: $target),
        ])));
        self::assertNull(NrLlmCardTarget::of(self::view([])));
    }
}
