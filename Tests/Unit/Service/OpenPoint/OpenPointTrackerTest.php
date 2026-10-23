<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\OpenPoint;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\OpenPointRepository;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointTarget;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointTracker;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointVisibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * When the chat records and closes an open point (nr-llm ADR-214, item 9;
 * ADR-022), in both directions: what records one and what must not, what
 * closes one and what must not.
 */
#[CoversClass(OpenPointTracker::class)]
final class OpenPointTrackerTest extends TestCase
{
    private OpenPointRepository&MockObject $repository;

    private OpenPointVisibility&MockObject $visibility;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(OpenPointRepository::class);
        $this->visibility = $this->createMock(OpenPointVisibility::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function tracker(): OpenPointTracker
    {
        return new OpenPointTracker($this->repository, $this->visibility);
    }

    private function loggedIn(int $uid): BackendUserAuthentication
    {
        $user               = $this->createMock(BackendUserAuthentication::class);
        $user->user         = ['uid' => $uid];
        $GLOBALS['BE_USER'] = $user;

        return $user;
    }

    private function conversation(int $skillUid = 7, int $pageUid = 42): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(3);
        (new ReflectionProperty(Conversation::class, 'uid'))->setValue($conversation, 99);
        if ($skillUid > 0) {
            $conversation->setSkillIdentifier('seo-check', $skillUid);
        }

        $conversation->setViewContext($pageUid, 'web_layout');
        $conversation->recordApprovalDecision(false, 'digest-abc');

        return $conversation;
    }

    private function runResult(RunStep ...$steps): AgentRunResult
    {
        return new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-1', array_values($steps));
    }

    private function target(): OpenPointTarget
    {
        return new OpenPointTarget('tt_content', 12, ['bodytext']);
    }

    // ---- recording ---------------------------------------------------------

    #[Test]
    public function skippingRecordsUnderSkillPageAndTarget(): void
    {
        $target = $this->target();
        $this->repository->expects(self::once())->method('record')->with(7, 'pages', 42, $target, 3, 99);
        $this->repository->expects(self::never())->method('close');

        $this->tracker()->settle($this->conversation(), false, DenyReason::Skip, $target, $this->runResult());
    }

    /** "Andere Variante" records nothing: a new proposal follows. */
    #[Test]
    public function askingForAVariantRecordsNothing(): void
    {
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle($this->conversation(), false, DenyReason::Variant, $this->target(), $this->runResult());
    }

    #[Test]
    public function aPlainDenialRecordsNothing(): void
    {
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle($this->conversation(), false, null, $this->target(), $this->runResult());
    }

    /** A skipped create names no target, and records nothing. */
    #[Test]
    public function skippingACreateRecordsNothing(): void
    {
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle($this->conversation(), false, DenyReason::Skip, null, $this->runResult());
    }

    #[Test]
    public function withoutAKnownSkillOrPageNothingIsRecorded(): void
    {
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle($this->conversation(skillUid: 0), false, DenyReason::Skip, $this->target(), $this->runResult());
        $this->tracker()->settle($this->conversation(pageUid: 0), false, DenyReason::Skip, $this->target(), $this->runResult());
    }

    // ---- keeping -----------------------------------------------------------

    /** "Behalten": the current content stays, so the target's open points are settled and none is recorded. */
    #[Test]
    public function keepingClosesTheTargetsOpenPointsAndRecordsNone(): void
    {
        $target = $this->target();
        $this->repository->expects(self::once())->method('close')->with($target);
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle($this->conversation(), false, DenyReason::Keep, $target, $this->runResult());
    }

    /** Closing is keyed by the record and fields alone: it does not need the skill or the page that recording needs. */
    #[Test]
    public function keepingClosesWhateverSkillOrPageRecordedThePoint(): void
    {
        $this->repository->expects(self::exactly(2))->method('close')->with($this->target());
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle($this->conversation(skillUid: 0), false, DenyReason::Keep, $this->target(), $this->runResult());
        $this->tracker()->settle($this->conversation(pageUid: 0), false, DenyReason::Keep, $this->target(), $this->runResult());
    }

    /** A kept create names no target: there is nothing to close. */
    #[Test]
    public function keepingACreateNamesNoTargetClosesNothing(): void
    {
        $this->repository->expects(self::never())->method('close');
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle($this->conversation(), false, DenyReason::Keep, null, $this->runResult());
    }

    /** The other denials close nothing. */
    #[Test]
    public function theOtherDenialsCloseNothing(): void
    {
        $this->repository->expects(self::never())->method('close');

        $this->tracker()->settle($this->conversation(), false, DenyReason::Skip, $this->target(), $this->runResult());
        $this->tracker()->settle($this->conversation(), false, DenyReason::Variant, $this->target(), $this->runResult());
        $this->tracker()->settle($this->conversation(), false, null, $this->target(), $this->runResult());
    }

    // ---- closing -----------------------------------------------------------

    private function applied(WriteCompleteness $completeness, bool $hookFailed = false): AgentRunResult
    {
        return $this->runResult(new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: 'update_content_element', toolIsError: false), new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('tt_content', 12), writeCompleteness: $completeness, hookFailedAfterWrite: $hookFailed));
    }

    #[Test]
    public function anAppliedApprovalClosesTheTargetsOpenPoints(): void
    {
        $target = $this->target();
        $this->repository->expects(self::once())->method('close')->with($target);
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle($this->conversation(), true, null, $target, $this->applied(WriteCompleteness::COMPLETE));
    }

    #[Test]
    public function aPartialWriteClosesNothing(): void
    {
        $this->repository->expects(self::never())->method('close');

        $this->tracker()->settle($this->conversation(), true, null, $this->target(), $this->applied(WriteCompleteness::PARTIAL));
    }

    #[Test]
    public function aHookFlaggedWriteClosesNothing(): void
    {
        $this->repository->expects(self::never())->method('close');

        $this->tracker()->settle($this->conversation(), true, null, $this->target(), $this->applied(WriteCompleteness::COMPLETE, hookFailed: true));
    }

    /** A write step that states no completeness closes nothing. */
    #[Test]
    public function aWriteThatStatesNoCompletenessClosesNothing(): void
    {
        $this->repository->expects(self::never())->method('close');

        $this->tracker()->settle($this->conversation(), true, null, $this->target(), $this->runResult(new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: 'update_content_element', toolIsError: false), new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('tt_content', 12))));
    }

    #[Test]
    public function anApprovalWithoutATargetClosesNothing(): void
    {
        $this->repository->expects(self::never())->method('close');

        $this->tracker()->settle($this->conversation(), true, null, null, $this->applied(WriteCompleteness::COMPLETE));
    }

    // ---- the card the decision answers -------------------------------------

    private function card(string $digest, string $mode = WaitingRunView::MODE_APPROVAL): WaitingRunView
    {
        $call = new PendingCallView('update_content_element', '{}', true, pendingTarget: new PendingWriteTarget(new RecordReference('tt_content', 12), ['bodytext']));

        return new WaitingRunView('run-1', $mode, 0, 'Chat', $digest, [$call]);
    }

    #[Test]
    public function theTargetIsTheCardsWhoseDigestTheDecisionCarries(): void
    {
        $target = $this->tracker()->cardTarget($this->conversation(), $this->card('digest-abc'));

        self::assertEquals($this->target(), $target);
    }

    /** A card that moved on since the decision is not the card it answers. */
    #[Test]
    public function aCardWithAnotherDigestHasNoTarget(): void
    {
        self::assertNull($this->tracker()->cardTarget($this->conversation(), $this->card('digest-other')));
        self::assertNull($this->tracker()->cardTarget($this->conversation(), $this->card('digest-abc', WaitingRunView::MODE_INPUT)));
        self::assertNull($this->tracker()->cardTarget($this->conversation(), null));
    }

    // ---- offered again -----------------------------------------------------

    #[Test]
    public function theProcessIsOfferedItsOpenPointsOnItsPage(): void
    {
        $owner = $this->loggedIn(3);
        $this->visibility->expects(self::once())->method('forPage')->with($owner, 42, 7)->willReturn([
            ['skillUid' => 7, 'targetTable' => 'tt_content', 'targetUid' => 12, 'field' => 'bodytext', 'crdate' => 1],
            ['skillUid' => 7, 'targetTable' => 'tt_content', 'targetUid' => 13, 'field' => '', 'crdate' => 2],
        ]);

        $prompt = $this->tracker()->promptFor($this->conversation());

        self::assertStringContainsString('page 42', $prompt);
        self::assertStringContainsString('- tt_content 12, field bodytext', $prompt);
        self::assertStringContainsString('- tt_content 13 (the record as a whole)', $prompt);
        self::assertStringContainsString('chat_list_open_points', $prompt);
    }

    #[Test]
    public function withoutOpenPointsOrAProcessNothingIsOffered(): void
    {
        $this->loggedIn(3);
        $this->visibility->method('forPage')->willReturn([]);

        self::assertSame('', $this->tracker()->promptFor($this->conversation()));
        self::assertSame('', $this->tracker()->promptFor($this->conversation(skillUid: 0)));
        self::assertSame('', $this->tracker()->promptFor($this->conversation(pageUid: 0)));
    }

    /** A page the owner may not show offers nothing, as the list tool refuses it. */
    #[Test]
    public function aPageTheOwnerMayNotShowOffersNothing(): void
    {
        $this->loggedIn(3);
        $this->visibility->method('forPage')->willReturn(null);

        self::assertSame('', $this->tracker()->promptFor($this->conversation()));
    }

    /** Visibility is decided for the owner; without the owner as current user, nothing. */
    #[Test]
    public function withoutTheOwnerAsCurrentUserNothingIsOffered(): void
    {
        $this->visibility->expects(self::never())->method('forPage');

        self::assertSame('', $this->tracker()->promptFor($this->conversation()));
        $this->loggedIn(4);
        self::assertSame('', $this->tracker()->promptFor($this->conversation()));
    }
}
