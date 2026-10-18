<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Enum;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationInactiveException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrMcpAgent\Enum\ApprovalHandBackReason;
use Netresearch\NrMcpAgent\Enum\ConversationErrorCode;
use Netresearch\NrMcpAgent\Enum\InputHandBackReason;
use Netresearch\NrMcpAgent\Enum\ProcessRefusal;
use Netresearch\NrMcpAgent\Enum\ProcessStopReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The refusals nr-llm raises for a guided process (nr-llm PR 1029), read by
 * class name so that an nr-llm without them matches nothing.
 */
#[CoversClass(ProcessRefusal::class)]
#[CoversClass(ProcessStopReason::class)]
final class ProcessRefusalTest extends TestCase
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';

    /**
     * The refusal as nr-llm raises it, or a skip where the installed nr-llm
     * does not have it yet.
     */
    public static function raised(ProcessRefusal $refusal, string $runUuid = 'run-1'): RuntimeException
    {
        if (!class_exists($refusal->value)) {
            self::markTestSkipped('Needs nr-llm with ' . $refusal->value . ' (nr-llm PR 1029).');
        }

        $exception = match ($refusal) {
            ProcessRefusal::DecidedInChat       => call_user_func([$refusal->value, 'forActor'], AiActorContext::backendUser(2), $runUuid),
            ProcessRefusal::NeedsSecondApprover => call_user_func([$refusal->value, 'forRun'], $runUuid, 'chat'),
            ProcessRefusal::VerdictUnavailable  => call_user_func([$refusal->value, 'forRun'], $runUuid),
        };
        self::assertInstanceOf(RuntimeException::class, $exception);

        return $exception;
    }

    /**
     * @return iterable<string, array{ProcessRefusal}>
     */
    public static function refusals(): iterable
    {
        foreach (ProcessRefusal::cases() as $refusal) {
            yield $refusal->name => [$refusal];
        }
    }

    #[Test]
    #[DataProvider('refusals')]
    public function nrLlmsRefusalIsRecognised(ProcessRefusal $refusal): void
    {
        self::assertSame($refusal, ProcessRefusal::of(self::raised($refusal)));
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function otherExceptions(): iterable
    {
        yield 'a releasing refusal' => [new RunNotAwaitingApprovalException('run-1', 'not waiting')];
        yield 'an inactive configuration' => [new RunConfigurationInactiveException('run-1', 'inactive')];
        yield 'anything else' => [new RuntimeException('the database went away')];
    }

    /** Runs on every nr-llm: nothing else is taken for a refusal of a guided process. */
    #[Test]
    #[DataProvider('otherExceptions')]
    public function anythingElseIsNoProcessRefusal(Throwable $e): void
    {
        self::assertNull(ProcessRefusal::of($e));
    }

    #[Test]
    public function onlyTheSecondApproverStopsTheRun(): void
    {
        self::assertTrue(ProcessRefusal::NeedsSecondApprover->stopsRun());
        self::assertNull(ProcessRefusal::NeedsSecondApprover->approvalHandBack());
        self::assertNull(ProcessRefusal::NeedsSecondApprover->inputHandBack());

        foreach ([ProcessRefusal::VerdictUnavailable, ProcessRefusal::DecidedInChat] as $refusal) {
            self::assertFalse($refusal->stopsRun(), $refusal->name);
            self::assertNotNull($refusal->approvalHandBack(), $refusal->name);
            self::assertNotNull($refusal->inputHandBack(), $refusal->name);
        }

        self::assertSame(ApprovalHandBackReason::VerdictUnavailable, ProcessRefusal::VerdictUnavailable->approvalHandBack());
        self::assertSame(ApprovalHandBackReason::DecidedInChat, ProcessRefusal::DecidedInChat->approvalHandBack());
        self::assertSame(InputHandBackReason::VerdictUnavailable, ProcessRefusal::VerdictUnavailable->inputHandBack());
        self::assertSame(InputHandBackReason::DecidedInChat, ProcessRefusal::DecidedInChat->inputHandBack());
    }

    /**
     * Every code a process refusal can store has its sentence in both
     * languages, fits the error-code column and collides with no other code.
     */
    #[Test]
    public function everyCodeHasItsSentenceAndFitsTheColumn(): void
    {
        $english = (string) file_get_contents(self::LANGUAGE_DIR . 'locallang_chat.xlf');
        $german  = (string) file_get_contents(self::LANGUAGE_DIR . 'de.locallang_chat.xlf');
        $codes   = [
            ApprovalHandBackReason::VerdictUnavailable,
            ApprovalHandBackReason::DecidedInChat,
            InputHandBackReason::VerdictUnavailable,
            InputHandBackReason::DecidedInChat,
            ProcessStopReason::NeedsSecondApprover,
        ];

        foreach ($codes as $code) {
            $unit = 'id="' . $code->labelKey() . '"';
            self::assertStringContainsString($unit, $english, $code->name);
            self::assertMatchesRegularExpression('/' . preg_quote($unit, '/') . '[^>]*>\s*<source>[^<]+<\/source>\s*<target>[^<]+<\/target>/', $german, $code->name);
            self::assertLessThanOrEqual(32, strlen($code->value), $code->name);
            self::assertNull(ConversationErrorCode::tryFrom($code->value), $code->name);
        }

        self::assertNull(ApprovalHandBackReason::tryFrom(ProcessStopReason::NeedsSecondApprover->value));
        self::assertNull(InputHandBackReason::tryFrom(ProcessStopReason::NeedsSecondApprover->value));
    }
}
