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
    /**
     * @param list<PendingCallView> $calls
     */
    private function view(array $calls): WaitingRunView
    {
        return new WaitingRunView('run-1', WaitingRunView::MODE_APPROVAL, 0, 'Chat', 'digest', $calls);
    }

    #[Test]
    public function theTargetIsNrLlmsRecordAndFields(): void
    {
        $call = new PendingCallView('update_content_element', '{}', true, pendingTarget: new PendingWriteTarget(new RecordReference('tt_content', 12), ['header', 'bodytext']));

        $target = NrLlmCardTarget::of($this->view([$call]));

        self::assertNotNull($target);
        self::assertSame('tt_content', $target->table);
        self::assertSame(12, $target->uid);
        self::assertSame(['bodytext', 'header'], $target->fields);
    }

    /** A create has no uid yet, so nr-llm names no target and nothing is keyed. */
    #[Test]
    public function aCallWithoutATargetHasNone(): void
    {
        self::assertNull(NrLlmCardTarget::of($this->view([new PendingCallView('create_content_element_draft', '{}', true)])));
    }

    /** One decision covers the whole pending set; only a one-call card is keyed. */
    #[Test]
    public function aCardWithSeveralCallsHasNone(): void
    {
        $target = new PendingWriteTarget(new RecordReference('tt_content', 12), ['bodytext']);

        self::assertNull(NrLlmCardTarget::of($this->view([
            new PendingCallView('update_content_element', '{}', true, pendingTarget: $target),
            new PendingCallView('update_content_element', '{}', true, pendingTarget: $target),
        ])));
        self::assertNull(NrLlmCardTarget::of($this->view([])));
    }
}
