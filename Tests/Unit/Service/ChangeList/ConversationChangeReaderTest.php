<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\ChangeList;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Service\ChangeList\ConversationChangeReader;
use Netresearch\NrMcpAgent\Service\ChangeList\RecordChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What the reader makes of the persisted events of a conversation's runs
 * (ADR-034): several runs, a run resumed after an approval, a call that failed
 * after it wrote, a capture nr-llm marked incomplete, and a run from before
 * `tool_changes` existed.
 */
#[CoversClass(ConversationChangeReader::class)]
final class ConversationChangeReaderTest extends TestCase
{
    private const OWNER = 7;

    /** @var array<string, list<AgentRunEvent>> */
    private array $events = [];

    /** @var array<string, int> uuid => owner */
    private array $runs = [];

    private function reader(): ConversationChangeReader
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('events')->willReturnCallback(fn (AiActorContext $actor, string $uuid): array => $this->events[$uuid] ?? []);

        $repository = $this->createMock(AgentRunRepositoryInterface::class);
        $repository->method('findByUuid')->willReturnCallback(function (string $uuid): ?AgentRun {
            if (!isset($this->runs[$uuid])) {
                return null;
            }

            $run = (new ReflectionClass(AgentRun::class))->newInstanceWithoutConstructor();
            (new ReflectionClass($run))->getProperty('beUser')->setValue($run, $this->runs[$uuid]);

            return $run;
        });

        return new ConversationChangeReader($runtime, $repository);
    }

    private function actor(): AiActorContext
    {
        return AiActorContext::backendUser(self::OWNER);
    }

    /**
     * @param list<array<string, mixed>> $events payloads keyed `kind`
     */
    private function addRun(string $uuid, array $events, int $owner = self::OWNER): void
    {
        $this->runs[$uuid] = $owner;
        foreach ($events as $sequence => $event) {
            $kind                  = (string) $event['kind'];
            $this->events[$uuid][] = new AgentRunEvent(0, 1, $sequence, $kind, 1, 0.0, array_diff_key($event, ['kind' => 1]), 0);
        }
    }

    /**
     * @param list<array<string, mixed>> $changes
     *
     * @return array<string, mixed>
     */
    private function changes(string $tool, array $changes, string $coverage = 'datahandler', int $omitted = 0): array
    {
        return [
            'kind'           => 'tool_changes',
            'toolName'       => $tool,
            'changes'        => $changes,
            'changeCount'    => count($changes),
            'changeCoverage' => $coverage,
            'changesOmitted' => $omitted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function change(string $table, int $uid, string $action = 'created', int $workspace = 3, bool $target = false, ?bool $hidden = true): array
    {
        return ['table' => $table, 'uid' => $uid, 'action' => $action, 'workspace' => $workspace, 'draft' => $workspace > 0, 'writeTarget' => $target, 'hidden' => $hidden, 'restrictions' => ['starttime'], 'language' => 1];
    }

    #[Test]
    public function theChangesOfSeveralCallsOfSeveralRunsAreListedOnceEach(): void
    {
        $this->addRun('run-1', [
            ['kind' => 'tool', 'toolName' => 'create_page_draft', 'toolIsError' => false],
            $this->changes('create_page_draft', [$this->change('pages', 11, 'created', target: true), $this->change('tt_content', 21)]),
            ['kind' => 'tool', 'toolName' => 'update_record', 'toolIsError' => false],
            $this->changes('update_record', [$this->change('pages', 11, 'updated', target: true, hidden: false)]),
        ]);
        $this->addRun('run-2', [
            $this->changes('copy_record', [$this->change('pages', 12, 'created', target: true)]),
        ]);

        $result = $this->reader()->read($this->actor(), ['run-1', 'run-2']);

        self::assertSame(2, $result->runs);
        self::assertSame(['pages:11', 'tt_content:21', 'pages:12'], array_map(static fn (RecordChange $c): string => $c->key(), $result->changes));
        // Created, then updated by a later call: still created, and the later state stands.
        self::assertSame(ChangeAction::CREATED, $result->changes[0]->action);
        self::assertFalse($result->changes[0]->hidden);
        self::assertTrue($result->changes[0]->isWriteTarget);
        self::assertFalse($result->changes[1]->isWriteTarget);
        self::assertSame(3, $result->changes[1]->workspace);
        self::assertSame(1, $result->changes[1]->language);
        self::assertSame(['starttime'], $result->changes[1]->restrictions);
        self::assertFalse($result->notCaptured);
        self::assertFalse($result->legacy);
    }

    #[Test]
    public function aRunWithoutToolChangesFallsBackToItsToolWriteStepsAsLegacy(): void
    {
        $this->addRun('old', [
            ['kind' => 'tool', 'toolName' => 'update_record', 'toolIsError' => false],
            ['kind' => 'tool_write', 'toolName' => 'update_record', 'writeTargetTable' => 'pages', 'writeTargetUid' => 5, 'writeCompleteness' => 'partial'],
        ]);

        $result = $this->reader()->read($this->actor(), ['old']);

        self::assertTrue($result->legacy);
        self::assertCount(1, $result->changes);
        self::assertTrue($result->changes[0]->legacy);
        self::assertNull($result->changes[0]->action);
        self::assertFalse($result->changes[0]->complete);
        self::assertTrue($result->changes[0]->isWriteTarget);
    }

    /** Both steps name the tool's target; reading the second as well would list it twice or as legacy. */
    #[Test]
    public function aRunWithToolChangesIsNotAlsoReadThroughToolWrite(): void
    {
        $this->addRun('new', [
            ['kind' => 'tool', 'toolName' => 'create_page_draft', 'toolIsError' => false],
            ['kind' => 'tool_write', 'toolName' => 'create_page_draft', 'writeTargetTable' => 'pages', 'writeTargetUid' => 11],
            $this->changes('create_page_draft', [$this->change('pages', 11, 'created', target: true), $this->change('tt_content', 21)]),
        ]);

        $result = $this->reader()->read($this->actor(), ['new']);

        self::assertFalse($result->legacy);
        self::assertCount(2, $result->changes);
        self::assertFalse($result->changes[0]->legacy);
        self::assertSame(ChangeAction::CREATED, $result->changes[0]->action);
    }

    #[Test]
    public function aCallThatFailedAfterItWroteKeepsItsListAndIsMarked(): void
    {
        $this->addRun('run', [
            ['kind' => 'tool', 'toolName' => 'copy_record', 'toolIsError' => true],
            $this->changes('copy_record', [$this->change('pages', 30)]),
        ]);

        $result = $this->reader()->read($this->actor(), ['run']);

        self::assertCount(1, $result->changes);
        self::assertTrue($result->changes[0]->failedAfterWrite);
    }

    /** The failure belongs to the call whose tool step precedes the list, not to another tool of the run. */
    /** A later clean call that touches the record again does not undo the doubt about the earlier write. */
    #[Test]
    public function aLaterCleanCallDoesNotClearAnEarlierFailureMarker(): void
    {
        $this->addRun('run', [
            ['kind' => 'tool', 'toolName' => 'copy_record', 'toolIsError' => true],
            $this->changes('copy_record', [$this->change('pages', 30)]),
            ['kind' => 'tool', 'toolName' => 'update_record', 'toolIsError' => false],
            $this->changes('update_record', [$this->change('pages', 30, 'updated')]),
        ]);

        self::assertTrue($this->reader()->read($this->actor(), ['run'])->changes[0]->failedAfterWrite);
    }

    #[Test]
    public function theFailureOfAnotherToolIsNotAttributed(): void
    {
        $this->addRun('run', [
            ['kind' => 'tool', 'toolName' => 'broken_tool', 'toolIsError' => true],
            ['kind' => 'tool', 'toolName' => 'update_record', 'toolIsError' => false],
            $this->changes('update_record', [$this->change('pages', 30, 'updated')]),
        ]);

        self::assertFalse($this->reader()->read($this->actor(), ['run'])->changes[0]->failedAfterWrite);
    }

    /**
     * nr-llm records `isError=false` and PARTIAL for a translation that stopped
     * half-way (CreateTranslationDraftToolTest::aFailedTranslationLeavesTheSourceTextAndSaysSo):
     * the flag sits on the `tool_write` step of the call, and only on the write target.
     */
    #[Test]
    public function aPartialWriteOfACallWithToolChangesIsCarriedOntoItsWriteTarget(): void
    {
        $this->addRun('run', [
            ['kind' => 'tool', 'toolName' => 'create_translation_draft', 'toolIsError' => false],
            ['kind' => 'tool_write', 'toolName' => 'create_translation_draft', 'writeTargetTable' => 'pages', 'writeTargetUid' => 11, 'writeCompleteness' => 'partial', 'hookFailedAfterWrite' => false],
            $this->changes('create_translation_draft', [$this->change('pages', 11, 'created', target: true), $this->change('tt_content', 21)]),
        ]);

        $result = $this->reader()->read($this->actor(), ['run']);

        self::assertCount(2, $result->changes);
        self::assertFalse($result->legacy);
        self::assertFalse($result->changes[0]->legacy);
        self::assertFalse($result->changes[0]->complete);
        self::assertFalse($result->changes[0]->failedAfterWrite);
        self::assertNull($result->changes[1]->complete);
    }

    #[Test]
    public function aHookFailureAfterTheWriteIsKeptWhenTheToolReportedNoError(): void
    {
        $this->addRun('run', [
            ['kind' => 'tool', 'toolName' => 'update_record', 'toolIsError' => false],
            ['kind' => 'tool_write', 'toolName' => 'update_record', 'writeTargetTable' => 'pages', 'writeTargetUid' => 5, 'writeCompleteness' => 'complete', 'hookFailedAfterWrite' => true],
            $this->changes('update_record', [$this->change('pages', 5, 'updated', target: true)]),
        ]);

        $changes = $this->reader()->read($this->actor(), ['run'])->changes;

        self::assertCount(1, $changes);
        self::assertTrue($changes[0]->failedAfterWrite);
        // A hook failure does not change the completeness the tool stated (nr-llm ToolResult).
        self::assertTrue($changes[0]->complete);
    }

    /** Partial and hook-failed are two facts of one write: both stay on the target, observed or legacy. */
    #[Test]
    public function aPartialWriteWhoseHookAlsoFailedKeepsBothFacts(): void
    {
        $write = ['kind' => 'tool_write', 'toolName' => 'update_record', 'writeTargetTable' => 'pages', 'writeTargetUid' => 5, 'writeCompleteness' => 'partial', 'hookFailedAfterWrite' => true];
        $this->addRun('observed', [
            ['kind' => 'tool', 'toolName' => 'update_record', 'toolIsError' => false],
            $write,
            $this->changes('update_record', [$this->change('pages', 5, 'updated', target: true)]),
        ]);
        $this->addRun('old', [
            ['kind' => 'tool', 'toolName' => 'update_record', 'toolIsError' => false],
            array_replace($write, ['writeTargetUid' => 6]),
        ]);

        $changes = $this->reader()->read($this->actor(), ['observed', 'old'])->changes;

        self::assertCount(2, $changes);
        foreach ($changes as $change) {
            self::assertFalse($change->complete, $change->key());
            self::assertTrue($change->failedAfterWrite, $change->key());
        }
    }

    /** The write step belongs to the call it follows: a later call of the same tool does not inherit it. */
    #[Test]
    public function theWriteMetadataOfOneCallIsNotAppliedToAnotherCallOfTheSameTool(): void
    {
        $this->addRun('run', [
            ['kind' => 'tool', 'toolName' => 'update_record', 'toolIsError' => false],
            ['kind' => 'tool_write', 'toolName' => 'update_record', 'writeTargetTable' => 'pages', 'writeTargetUid' => 5, 'writeCompleteness' => 'partial'],
            $this->changes('update_record', [$this->change('pages', 5, 'updated', target: true)]),
            ['kind' => 'tool', 'toolName' => 'update_record', 'toolIsError' => false],
            $this->changes('update_record', [$this->change('pages', 6, 'updated', target: true)]),
        ]);

        $changes = $this->reader()->read($this->actor(), ['run'])->changes;

        self::assertFalse($changes[0]->complete);
        self::assertNull($changes[1]->complete);
    }

    #[Test]
    public function aCaptureThatIsNotCompleteAndOmittedEntriesAreCarriedOver(): void
    {
        $this->addRun('a', [$this->changes('update_record', [$this->change('pages', 1, 'updated')], 'not_captured', 0)]);
        $this->addRun('b', [$this->changes('copy_record', [$this->change('pages', 2)], 'datahandler', 4)]);

        $result = $this->reader()->read($this->actor(), ['a', 'b']);

        self::assertTrue($result->notCaptured);
        self::assertSame(4, $result->omitted);
    }

    /** A run of the conversation that the actor may not read, or that is gone, is counted, not listed. */
    #[Test]
    public function aRunTheActorMayNotReadIsCountedAndNotRead(): void
    {
        $this->addRun('mine', [$this->changes('update_record', [$this->change('pages', 1, 'updated')])]);
        $this->addRun('theirs', [$this->changes('update_record', [$this->change('pages', 2, 'updated')])], owner: 99);

        $result = $this->reader()->read($this->actor(), ['mine', 'theirs', 'gone']);

        self::assertSame(1, $result->runs);
        self::assertSame(2, $result->unreadable);
        self::assertSame(['pages:1'], array_map(static fn (RecordChange $c): string => $c->key(), $result->changes));
    }

    /** Run records from before the uuid was kept contribute the writes they list. */
    #[Test]
    public function writesOfRunRecordsWithoutAUuidAreListedAsLegacy(): void
    {
        $result = $this->reader()->read($this->actor(), [], [['table' => 'pages', 'uid' => 8, 'complete' => true]]);

        self::assertTrue($result->legacy);
        self::assertSame('pages:8', $result->changes[0]->key());
        self::assertTrue($result->changes[0]->legacy);
        self::assertTrue($result->changes[0]->complete);
    }

    #[Test]
    public function aMovedAfterCreatedStaysCreatedAndADeleteWins(): void
    {
        $this->addRun('run', [
            $this->changes('a', [$this->change('pages', 1, 'created')]),
            $this->changes('b', [$this->change('pages', 1, 'moved')]),
            $this->changes('c', [$this->change('pages', 2, 'updated')]),
            $this->changes('d', [$this->change('pages', 2, 'deleted')]),
        ]);

        $actions = array_map(static fn (RecordChange $c): ?ChangeAction => $c->action, $this->reader()->read($this->actor(), ['run'])->changes);

        self::assertSame([ChangeAction::CREATED, ChangeAction::DELETED], $actions);
    }
}
