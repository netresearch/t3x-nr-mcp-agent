<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\OpenPoint;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrMcpAgent\Enum\ProposalOutcome;
use Netresearch\NrMcpAgent\Service\OpenPoint\ApprovedCallReading;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * nr-llm ADR-214, item 9, "Which reading applies": exactly one of applied,
 * approved but to be checked, and not applied, read from the approval's
 * result alone (ADR-023).
 */
#[CoversClass(ApprovedCallReading::class)]
final class ApprovedCallReadingTest extends TestCase
{
    private function tool(bool $isError = false): RunStep
    {
        return new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: 'update_page_metadata', toolIsError: $isError);
    }

    private function plainWrite(): RunStep
    {
        return new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('pages', 3));
    }

    private function runResult(AgentRunOutcome $outcome, RunStep ...$steps): AgentRunResult
    {
        return new AgentRunResult($outcome, 'run-1', array_values($steps));
    }

    #[Test]
    public function aCallThatDidNotRunHasNoReading(): void
    {
        self::assertNull(ApprovedCallReading::of($this->runResult(AgentRunOutcome::COMPLETED)));
        self::assertNull(ApprovedCallReading::of($this->runResult(AgentRunOutcome::COMPLETED, new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 1.0))));
    }

    #[Test]
    public function aFailedCallIsNotApplied(): void
    {
        self::assertSame(ProposalOutcome::NotApplied, ApprovedCallReading::of($this->runResult(AgentRunOutcome::COMPLETED, $this->tool(isError: true), $this->plainWrite())));
    }

    /**
     * @return iterable<string, array{AgentRunOutcome, ProposalOutcome}>
     */
    public static function endingsWithoutAWriteStep(): iterable
    {
        yield 'cancelled: the write may have happened' => [AgentRunOutcome::CANCELLED, ProposalOutcome::Check];
        yield 'lease lost' => [AgentRunOutcome::LEASE_LOST, ProposalOutcome::Check];
        yield 'failed' => [AgentRunOutcome::FAILED, ProposalOutcome::Check];
        yield 'completed: a tool that leaves no write step' => [AgentRunOutcome::COMPLETED, ProposalOutcome::NotApplied];
        yield 'waiting again' => [AgentRunOutcome::AWAITING_APPROVAL, ProposalOutcome::NotApplied];
    }

    #[Test]
    #[DataProvider('endingsWithoutAWriteStep')]
    public function withoutAWriteStepTheRunsEndingDecides(AgentRunOutcome $outcome, ProposalOutcome $expected): void
    {
        self::assertSame($expected, ApprovedCallReading::of($this->runResult($outcome, $this->tool())));
    }

    /** The write step of a later call is not the approved call's. */
    #[Test]
    public function aLaterCallsWriteDoesNotCount(): void
    {
        self::assertSame(ProposalOutcome::NotApplied, ApprovedCallReading::of($this->runResult(AgentRunOutcome::COMPLETED, $this->tool(), $this->tool(), $this->plainWrite())));
    }

    // ---- a write step that states no completeness ---------------------------

    #[Test]
    public function aWriteThatStatesNoCompletenessIsToBeChecked(): void
    {
        self::assertSame(ProposalOutcome::Check, ApprovedCallReading::of($this->runResult(AgentRunOutcome::COMPLETED, $this->tool(), $this->plainWrite())));
    }

    // ---- a write step that states its completeness ---------------------------

    private function write(?WriteCompleteness $completeness, bool $hookFailed = false): RunStep
    {
        return new RunStep(
            kind: RunStep::KIND_WRITE,
            round: 1,
            durationMs: 1.0,
            writeTarget: new RecordReference('pages', 3),
            writeCompleteness: $completeness,
            hookFailedAfterWrite: $hookFailed,
        );
    }

    #[Test]
    public function aCompleteWriteIsApplied(): void
    {
        self::assertSame(ProposalOutcome::Applied, ApprovedCallReading::of($this->runResult(AgentRunOutcome::COMPLETED, $this->tool(), $this->write(WriteCompleteness::COMPLETE))));
    }

    #[Test]
    public function aPartialWriteIsNotApplied(): void
    {
        self::assertSame(ProposalOutcome::NotApplied, ApprovedCallReading::of($this->runResult(AgentRunOutcome::COMPLETED, $this->tool(), $this->write(WriteCompleteness::PARTIAL))));
    }

    /** A hook failure after the write is "check the record", whatever the completeness. */
    #[Test]
    public function aHookFailureAfterTheWriteIsToBeChecked(): void
    {
        self::assertSame(ProposalOutcome::Check, ApprovedCallReading::of($this->runResult(AgentRunOutcome::COMPLETED, $this->tool(), $this->write(WriteCompleteness::COMPLETE, hookFailed: true))));
        self::assertSame(ProposalOutcome::Check, ApprovedCallReading::of($this->runResult(AgentRunOutcome::COMPLETED, $this->tool(), $this->write(WriteCompleteness::PARTIAL, hookFailed: true))));
    }

    #[Test]
    public function aWriteWhoseToolStatedNoCompletenessIsToBeChecked(): void
    {
        self::assertSame(ProposalOutcome::Check, ApprovedCallReading::of($this->runResult(AgentRunOutcome::COMPLETED, $this->tool(), $this->write(null))));
    }
}
