<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Enum;

use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\Exception\SubmitterNotPermittedException;
use Netresearch\NrMcpAgent\Enum\ConversationErrorCode;
use Netresearch\NrMcpAgent\Enum\InputHandBackReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InputHandBackReason::class)]
final class InputHandBackReasonTest extends TestCase
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';

    /**
     * nr-llm up to 0.41.4 does not say why the submitter was refused: every
     * refusal keeps the permissions sentence, as before.
     */
    #[Test]
    public function aRefusalWithoutADenialReasonKeepsThePermissionsSentence(): void
    {
        self::assertSame(
            InputHandBackReason::SubmitterNotPermitted,
            InputHandBackReason::fromException(new SubmitterNotPermittedException('run-1', 'denied')),
        );
    }

    #[Test]
    public function aSwitchedOffToolGetsItsOwnSentence(): void
    {
        self::assertSame(InputHandBackReason::ToolDisabled, InputHandBackReason::forSubmitterRefusal(ToolDenialReason::TOOL_DISABLED));
    }

    /**
     * Every other reason is about what the submitter may do, so it keeps the
     * permissions sentence.
     */
    #[Test]
    public function everyOtherDenialReasonKeepsThePermissionsSentence(): void
    {
        self::assertSame(InputHandBackReason::SubmitterNotPermitted, InputHandBackReason::forSubmitterRefusal(null));
        foreach (ToolDenialReason::cases() as $reason) {
            if ($reason === ToolDenialReason::TOOL_DISABLED) {
                continue;
            }

            self::assertSame(InputHandBackReason::SubmitterNotPermitted, InputHandBackReason::forSubmitterRefusal($reason), $reason->name);
        }
    }

    /**
     * End to end against the real exception, where the installed nr-llm has
     * the accessor; skipped before that, where the tests above cover the path.
     */
    #[Test]
    #[RequiresMethod(SubmitterNotPermittedException::class, 'getDenialReason')]
    public function aRealRefusalForASwitchedOffToolMapsToItsOwnSentence(): void
    {
        $actor = AiActorContext::backendUser(2);

        self::assertSame(
            InputHandBackReason::ToolDisabled,
            InputHandBackReason::fromException(SubmitterNotPermittedException::forDeniedTool('run-1', $actor, 'ask_user', ToolDenialReason::TOOL_DISABLED)),
        );
        self::assertSame(
            InputHandBackReason::SubmitterNotPermitted,
            InputHandBackReason::fromException(SubmitterNotPermittedException::forDeniedTool('run-1', $actor, 'ask_user', ToolDenialReason::REQUIRES_ADMIN)),
        );
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

        foreach (InputHandBackReason::cases() as $reason) {
            $unit = 'id="' . $reason->labelKey() . '"';
            self::assertStringContainsString($unit, $english, $reason->name);
            self::assertMatchesRegularExpression('/' . preg_quote($unit, '/') . '[^>]*>\s*<source>[^<]+<\/source>\s*<target>[^<]+<\/target>/', $german, $reason->name);
        }
    }

    /** The conversation's error_code column is a varchar(32), shared with the error codes. */
    #[Test]
    public function everyValueFitsTheErrorCodeColumnAndCollidesWithNoErrorCode(): void
    {
        foreach (InputHandBackReason::cases() as $reason) {
            self::assertLessThanOrEqual(32, strlen($reason->value), $reason->name);
            self::assertNull(ConversationErrorCode::tryFrom($reason->value), $reason->name);
        }
    }
}
