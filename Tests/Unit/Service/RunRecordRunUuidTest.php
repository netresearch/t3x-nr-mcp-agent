<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Service\RunRecord;
use Netresearch\NrMcpAgent\Service\RunRecordNotes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The run's uuid in the run record (ADR-034): kept so the change view can read
 * the run's events, migration-safe for records written before, and never part
 * of what a later prompt reads.
 */
#[CoversClass(RunRecord::class)]
#[CoversClass(Conversation::class)]
#[CoversClass(RunRecordNotes::class)]
final class RunRecordRunUuidTest extends TestCase
{
    private const UUID = '0b6e1c52-3f0e-4c47-9d2e-1f6a8b7c9d10';

    #[Test]
    public function theRunUuidIsKeptInTheEntry(): void
    {
        $entry = RunRecord::of(new AgentRunResult(AgentRunOutcome::COMPLETED, self::UUID, []));

        self::assertSame(self::UUID, $entry['run'] ?? null);
    }

    /** A run nr-llm could not record has no uuid, and the entry then has no key rather than an empty one. */
    #[Test]
    public function aRunWithoutAUuidLeavesTheKeyOut(): void
    {
        $entry = RunRecord::of(new AgentRunResult(AgentRunOutcome::COMPLETED, '', []));

        self::assertArrayNotHasKey('run', $entry);
    }

    #[Test]
    public function theUuidsOfAConversationAreDistinctAndOldestFirst(): void
    {
        $conversation = new Conversation();
        // A run that paused for an approval and was resumed has two records and one uuid.
        $conversation->appendRunRecord(RunRecord::of(new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-a', [])));
        $conversation->appendRunRecord(RunRecord::of(new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-a', [])));
        $conversation->appendRunRecord(RunRecord::unknown());
        $conversation->appendRunRecord(RunRecord::of(new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-b', [])));

        self::assertSame(['run-a', 'run-b'], $conversation->getRunUuids());
    }

    /** Records stored before the uuid was kept decode, and name no run. */
    #[Test]
    public function recordsFromBeforeCarryNoUuid(): void
    {
        $conversation = Conversation::fromRow(['run_record' => json_encode([
            ['after' => 2, 'cards' => 0, 'outcome' => 'completed', 'tools' => [], 'writes' => [['table' => 'pages', 'uid' => 4, 'complete' => true]], 'waiting' => []],
        ], JSON_THROW_ON_ERROR)]);

        self::assertSame([], $conversation->getRunUuids());
        self::assertSame(4, $conversation->getRunRecords()[0]['writes'][0]['uid']);
        self::assertArrayNotHasKey('run', $conversation->getRunRecords()[0]);
    }

    /** The prompt notes read the fields they name; the uuid is an identifier of the run, not for the model. */
    #[Test]
    public function theUuidNeverReachesThePromptNotes(): void
    {
        $conversation = new Conversation();
        $conversation->appendRunRecord(RunRecord::of(new AgentRunResult(AgentRunOutcome::COMPLETED, self::UUID, [])));

        self::assertNotEmpty($conversation->getRunUuids());
        self::assertStringNotContainsString(self::UUID, json_encode(RunRecordNotes::of($conversation), JSON_THROW_ON_ERROR));
    }
}
