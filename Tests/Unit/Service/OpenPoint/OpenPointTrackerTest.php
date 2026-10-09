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
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->user = ['uid' => $uid];
        $GLOBALS['BE_USER'] = $user;

        return $user;
    }

    private static function conversation(int $skillUid = 7, int $pageUid = 42): Conversation
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

    private static function runResult(RunStep ...$steps): AgentRunResult
    {
        return new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-1', array_values($steps));
    }

    private static function target(): OpenPointTarget
    {
        return new OpenPointTarget('tt_content', 12, ['bodytext']);
    }

    // ---- recording ---------------------------------------------------------

    #[Test]
    public function skippingRecordsUnderSkillPageAndTarget(): void
    {
        $target = self::target();
        $this->repository->expects(self::once())->method('record')->with(7, 'pages', 42, $target, 3, 99);
        $this->repository->expects(self::never())->method('close');

        $this->tracker()->settle(self::conversation(), false, DenyReason::Skip, $target, self::runResult());
    }

    /** "Andere Variante" records nothing: a new proposal follows. */
    #[Test]
    public function askingForAVariantRecordsNothing(): void
    {
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle(self::conversation(), false, DenyReason::Variant, self::target(), self::runResult());
    }

    #[Test]
    public function aPlainDenialRecordsNothing(): void
    {
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle(self::conversation(), false, null, self::target(), self::runResult());
    }

    /** A skipped create names no target, and records nothing. */
    #[Test]
    public function skippingACreateRecordsNothing(): void
    {
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle(self::conversation(), false, DenyReason::Skip, null, self::runResult());
    }

    #[Test]
    public function withoutAKnownSkillOrPageNothingIsRecorded(): void
    {
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle(self::conversation(skillUid: 0), false, DenyReason::Skip, self::target(), self::runResult());
        $this->tracker()->settle(self::conversation(pageUid: 0), false, DenyReason::Skip, self::target(), self::runResult());
    }

    // ---- closing -----------------------------------------------------------

    private static function requireCompleteness(): void
    {
        if (!property_exists(RunStep::class, 'writeCompleteness')) {
            self::markTestSkipped('Needs nr-llm 0.41 (RunStep::$writeCompleteness, nr-llm PR 1023).');
        }
    }

    private static function applied(WriteCompleteness $completeness, bool $hookFailed = false): AgentRunResult
    {
        return self::runResult(
            new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: 'update_content_element', toolIsError: false),
            new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('tt_content', 12), writeCompleteness: $completeness, hookFailedAfterWrite: $hookFailed),
        );
    }

    #[Test]
    public function anAppliedApprovalClosesTheTargetsOpenPoints(): void
    {
        self::requireCompleteness();
        $target = self::target();
        $this->repository->expects(self::once())->method('close')->with($target);
        $this->repository->expects(self::never())->method('record');

        $this->tracker()->settle(self::conversation(), true, null, $target, self::applied(WriteCompleteness::COMPLETE));
    }

    #[Test]
    public function aPartialWriteClosesNothing(): void
    {
        self::requireCompleteness();
        $this->repository->expects(self::never())->method('close');

        $this->tracker()->settle(self::conversation(), true, null, self::target(), self::applied(WriteCompleteness::PARTIAL));
    }

    #[Test]
    public function aHookFlaggedWriteClosesNothing(): void
    {
        self::requireCompleteness();
        $this->repository->expects(self::never())->method('close');

        $this->tracker()->settle(self::conversation(), true, null, self::target(), self::applied(WriteCompleteness::COMPLETE, hookFailed: true));
    }

    /** Before nr-llm 0.41 no write states its completeness: nothing closes. */
    #[Test]
    public function aWriteThatStatesNoCompletenessClosesNothing(): void
    {
        $this->repository->expects(self::never())->method('close');

        $this->tracker()->settle(self::conversation(), true, null, self::target(), self::runResult(
            new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: 'update_content_element', toolIsError: false),
            new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('tt_content', 12)),
        ));
    }

    #[Test]
    public function anApprovalWithoutATargetClosesNothing(): void
    {
        self::requireCompleteness();
        $this->repository->expects(self::never())->method('close');

        $this->tracker()->settle(self::conversation(), true, null, null, self::applied(WriteCompleteness::COMPLETE));
    }

    // ---- the card the decision answers -------------------------------------

    private static function card(string $digest, string $mode = WaitingRunView::MODE_APPROVAL): WaitingRunView
    {
        $call = property_exists(PendingCallView::class, 'pendingTarget')
            ? new PendingCallView('update_content_element', '{}', true, pendingTarget: new PendingWriteTarget(new RecordReference('tt_content', 12), ['bodytext']))
            : new PendingCallView('update_content_element', '{}', true);

        return new WaitingRunView('run-1', $mode, 0, 'Chat', $digest, [$call]);
    }

    #[Test]
    public function theTargetIsTheCardsWhoseDigestTheDecisionCarries(): void
    {
        if (!property_exists(PendingCallView::class, 'pendingTarget')) {
            self::markTestSkipped('Needs nr-llm 0.41 (PendingCallView::$pendingTarget, nr-llm PR 1024).');
        }

        $target = $this->tracker()->cardTarget(self::conversation(), self::card('digest-abc'));

        self::assertEquals(self::target(), $target);
    }

    /** A card that moved on since the decision is not the card it answers. */
    #[Test]
    public function aCardWithAnotherDigestHasNoTarget(): void
    {
        self::assertNull($this->tracker()->cardTarget(self::conversation(), self::card('digest-other')));
        self::assertNull($this->tracker()->cardTarget(self::conversation(), self::card('digest-abc', WaitingRunView::MODE_INPUT)));
        self::assertNull($this->tracker()->cardTarget(self::conversation(), null));
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

        $prompt = $this->tracker()->promptFor(self::conversation());

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

        self::assertSame('', $this->tracker()->promptFor(self::conversation()));
        self::assertSame('', $this->tracker()->promptFor(self::conversation(skillUid: 0)));
        self::assertSame('', $this->tracker()->promptFor(self::conversation(pageUid: 0)));
    }

    /** A page the owner may not show offers nothing, as the list tool refuses it. */
    #[Test]
    public function aPageTheOwnerMayNotShowOffersNothing(): void
    {
        $this->loggedIn(3);
        $this->visibility->method('forPage')->willReturn(null);

        self::assertSame('', $this->tracker()->promptFor(self::conversation()));
    }

    /** Visibility is decided for the owner; without the owner as current user, nothing. */
    #[Test]
    public function withoutTheOwnerAsCurrentUserNothingIsOffered(): void
    {
        $this->visibility->expects(self::never())->method('forPage');

        self::assertSame('', $this->tracker()->promptFor(self::conversation()));
        $this->loggedIn(4);
        self::assertSame('', $this->tracker()->promptFor(self::conversation()));
    }
}
