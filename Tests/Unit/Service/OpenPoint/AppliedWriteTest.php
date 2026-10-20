<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\OpenPoint;

use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrMcpAgent\Service\OpenPoint\AppliedWrite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * nr-llm ADR-214, item 9, "Applied": only an approved call whose write step
 * states COMPLETE without the hook-failure flag closes an open point.
 */
#[CoversClass(AppliedWrite::class)]
final class AppliedWriteTest extends TestCase
{
    /**
     * @return iterable<string, array{bool|null, bool, bool|null, bool}>
     */
    public static function readings(): iterable
    {
        yield 'complete, no hook failure' => [false, true, false, true];
        yield 'complete, flag not stated' => [false, true, null, true];
        yield 'partial' => [false, false, false, false];
        yield 'complete, hook failed after the write' => [false, true, true, false];
        yield 'partial and hook failed' => [false, false, true, false];
        yield 'the call failed' => [true, true, false, false];
        yield 'the call states no outcome' => [null, true, false, false];
    }

    #[Test]
    #[DataProvider('readings')]
    public function onlyACompleteWriteWithoutAHookFailureIsApplied(?bool $toolIsError, bool $complete, ?bool $hookFailed, bool $applied): void
    {
        self::assertSame($applied, AppliedWrite::isApplied($toolIsError, $complete, $hookFailed));
    }

    private function tool(bool $isError = false): RunStep
    {
        return new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: 'update_content_element', toolIsError: $isError);
    }

    /** A write step that states no completeness is not applied. */
    #[Test]
    public function aWriteThatStatesNoCompletenessIsNotApplied(): void
    {
        $write = new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('tt_content', 12));

        self::assertFalse(AppliedWrite::inSteps([$this->tool(), $write]));
    }

    private function write(WriteCompleteness $completeness, bool $hookFailed = false): RunStep
    {
        return new RunStep(
            kind: RunStep::KIND_WRITE,
            round: 1,
            durationMs: 1.0,
            writeTarget: new RecordReference('tt_content', 12),
            writeCompleteness: $completeness,
            hookFailedAfterWrite: $hookFailed,
        );
    }

    #[Test]
    public function theApprovedCallsCompleteWriteIsApplied(): void
    {
        self::assertTrue(AppliedWrite::inSteps([
            new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 1.0),
            $this->tool(),
            $this->write(WriteCompleteness::COMPLETE),
        ]));
    }

    #[Test]
    public function aPartialWriteIsNotApplied(): void
    {
        self::assertFalse(AppliedWrite::inSteps([$this->tool(), $this->write(WriteCompleteness::PARTIAL)]));
    }

    #[Test]
    public function aHookFailureAfterTheWriteIsNotApplied(): void
    {
        self::assertFalse(AppliedWrite::inSteps([$this->tool(), $this->write(WriteCompleteness::COMPLETE, hookFailed: true)]));
    }

    #[Test]
    public function aFailedCallIsNotApplied(): void
    {
        self::assertFalse(AppliedWrite::inSteps([$this->tool(isError: true), $this->write(WriteCompleteness::COMPLETE)]));
    }

    /** A call without a write step — a remote tool — is approved, not applied. */
    #[Test]
    public function aCallWithoutAWriteStepIsNotApplied(): void
    {
        self::assertFalse(AppliedWrite::inSteps([$this->tool()]));
        self::assertFalse(AppliedWrite::inSteps([]));
    }

    /** The write step of a later call of the continuation is not the approved call's. */
    #[Test]
    public function aLaterCallsWriteDoesNotCount(): void
    {
        self::assertFalse(AppliedWrite::inSteps([$this->tool(), $this->tool(), $this->write(WriteCompleteness::COMPLETE)]));
    }
}
