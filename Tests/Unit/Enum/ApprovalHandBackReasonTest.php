<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Enum;

use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\Exception\ApproverNotPermittedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationInactiveException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Netresearch\NrMcpAgent\Enum\ApprovalHandBackReason;
use Netresearch\NrMcpAgent\Enum\ConversationErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApprovalHandBackReason::class)]
final class ApprovalHandBackReasonTest extends TestCase
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';

    /**
     * @return iterable<string, array{StaleApprovalTurnException|RunAlreadyResumingException|ApproverNotPermittedException|RunConfigurationInactiveException|RunNotAwaitingApprovalException, ApprovalHandBackReason}>
     */
    public static function exceptions(): iterable
    {
        yield 'stale turn' => [new StaleApprovalTurnException('run-1', 'stale'), ApprovalHandBackReason::StaleTurn];
        yield 'already resuming' => [new RunAlreadyResumingException('run-1', 'resuming'), ApprovalHandBackReason::AlreadyResuming];
        yield 'approver not permitted' => [new ApproverNotPermittedException('run-1', 'denied'), ApprovalHandBackReason::ApproverNotPermitted];
        yield 'configuration inactive' => [new RunConfigurationInactiveException('run-1', 'inactive'), ApprovalHandBackReason::ConfigurationInactive];
        yield 'not awaiting approval' => [new RunNotAwaitingApprovalException('run-1', 'not waiting'), ApprovalHandBackReason::NotAwaitingApproval];
    }

    #[Test]
    #[DataProvider('exceptions')]
    public function eachExceptionMapsToItsReason(StaleApprovalTurnException|RunAlreadyResumingException|ApproverNotPermittedException|RunConfigurationInactiveException|RunNotAwaitingApprovalException $e, ApprovalHandBackReason $expected): void
    {
        self::assertSame($expected, ApprovalHandBackReason::fromException($e));
    }

    /**
     * nr-llm up to 0.41.4 does not say why the approver was refused: every refusal
     * keeps the permissions sentence, as before.
     */
    #[Test]
    public function aRefusalWithoutADenialReasonKeepsThePermissionsSentence(): void
    {
        $refusal = new ApproverNotPermittedException('run-1', 'denied');

        self::assertNull(ApprovalHandBackReason::denialReasonOf($refusal));
        self::assertSame(ApprovalHandBackReason::ApproverNotPermitted, ApprovalHandBackReason::fromException($refusal));
    }

    /**
     * The nr-llm release with getDenialReason() (t3x-nr-llm#1179): the reason
     * is read through the method. The exception is final, so a stand-in with
     * the same method proves the bridge on 0.41.4 as well.
     */
    #[Test]
    public function theDenialReasonIsReadWhereNrLlmExposesIt(): void
    {
        $refusal = new class {
            public function getDenialReason(): ToolDenialReason
            {
                return ToolDenialReason::TOOL_DISABLED;
            }
        };

        self::assertSame(ToolDenialReason::TOOL_DISABLED, ApprovalHandBackReason::denialReasonOf($refusal));
    }

    #[Test]
    public function aValueThatIsNoDenialReasonCountsAsNone(): void
    {
        $refusal = new class {
            public function getDenialReason(): string
            {
                return 'toolDisabled';
            }
        };

        self::assertNull(ApprovalHandBackReason::denialReasonOf($refusal));
    }

    #[Test]
    public function aSwitchedOffToolGetsItsOwnSentence(): void
    {
        self::assertSame(ApprovalHandBackReason::ToolDisabled, ApprovalHandBackReason::forApproverRefusal(ToolDenialReason::TOOL_DISABLED));
    }

    /**
     * Every other reason is about what the approver may do, so it keeps the
     * permissions sentence.
     */
    #[Test]
    public function everyOtherDenialReasonKeepsThePermissionsSentence(): void
    {
        self::assertSame(ApprovalHandBackReason::ApproverNotPermitted, ApprovalHandBackReason::forApproverRefusal(null));
        foreach (ToolDenialReason::cases() as $reason) {
            if ($reason === ToolDenialReason::TOOL_DISABLED) {
                continue;
            }

            self::assertSame(ApprovalHandBackReason::ApproverNotPermitted, ApprovalHandBackReason::forApproverRefusal($reason), $reason->name);
        }
    }

    /**
     * End to end against the real exception, where the installed nr-llm has
     * the accessor; skipped before that, where the tests above cover the path.
     */
    #[Test]
    #[RequiresMethod(ApproverNotPermittedException::class, 'getDenialReason')]
    public function aRealRefusalForASwitchedOffToolMapsToItsOwnSentence(): void
    {
        $actor = AiActorContext::backendUser(2);

        self::assertSame(
            ApprovalHandBackReason::ToolDisabled,
            ApprovalHandBackReason::fromException(ApproverNotPermittedException::forDeniedTool('run-1', $actor, 'update_page_metadata', ToolDenialReason::TOOL_DISABLED)),
        );
        self::assertSame(
            ApprovalHandBackReason::ApproverNotPermitted,
            ApprovalHandBackReason::fromException(ApproverNotPermittedException::forDeniedTool('run-1', $actor, 'update_page_metadata', ToolDenialReason::REQUIRES_ADMIN)),
        );
    }

    #[Test]
    public function aSwitchedOffToolDoesNotSendTheReaderToTheRun(): void
    {
        // Nothing in AI Tasks changes that: the tool has to be switched on again.
        self::assertFalse(ApprovalHandBackReason::ToolDisabled->pointsToRun());
    }

    /**
     * The key is built from the value, so no grep for translate('…') finds
     * it; this is the check that every reason has its sentence in both
     * languages.
     */
    #[Test]
    public function everyReasonHasItsSentenceInBothLanguageFiles(): void
    {
        $english = (string) file_get_contents(self::LANGUAGE_DIR . 'locallang_chat.xlf');
        $german  = (string) file_get_contents(self::LANGUAGE_DIR . 'de.locallang_chat.xlf');

        foreach (ApprovalHandBackReason::cases() as $reason) {
            $unit = 'id="' . $reason->labelKey() . '"';
            self::assertStringContainsString($unit, $english, $reason->name);
            self::assertMatchesRegularExpression('/' . preg_quote($unit, '/') . '[^>]*>\s*<source>[^<]+<\/source>\s*<target>[^<]+<\/target>/', $german, $reason->name);
        }
    }

    /** The two codes share the conversation's error-code column. */
    #[Test]
    public function noReasonCollidesWithAnErrorCode(): void
    {
        foreach (ApprovalHandBackReason::cases() as $reason) {
            self::assertNull(ConversationErrorCode::tryFrom($reason->value), $reason->name);
        }
    }
}
