<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Enum;

use Netresearch\NrLlm\Service\Agent\Exception\ApproverNotPermittedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationInactiveException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Netresearch\NrMcpAgent\Enum\ApprovalHandBackReason;
use Netresearch\NrMcpAgent\Enum\ConversationErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(ApprovalHandBackReason::class)]
final class ApprovalHandBackReasonTest extends TestCase
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';

    /**
     * @return iterable<string, array{Throwable, ApprovalHandBackReason}>
     */
    public static function exceptions(): iterable
    {
        yield 'stale turn' => [new StaleApprovalTurnException('run-1', 'stale'), ApprovalHandBackReason::StaleTurn];
        yield 'already resuming' => [new RunAlreadyResumingException('run-1', 'resuming'), ApprovalHandBackReason::AlreadyResuming];
        yield 'approver not permitted' => [new ApproverNotPermittedException('run-1', 'denied'), ApprovalHandBackReason::ApproverNotPermitted];
        yield 'configuration inactive' => [new RunConfigurationInactiveException('run-1', 'inactive'), ApprovalHandBackReason::ConfigurationInactive];
        yield 'not awaiting approval' => [new RunNotAwaitingApprovalException('run-1', 'not waiting'), ApprovalHandBackReason::NotAwaitingApproval];
        yield 'anything else' => [new RuntimeException('something new'), ApprovalHandBackReason::Unknown];
    }

    #[Test]
    #[DataProvider('exceptions')]
    public function eachExceptionMapsToItsReason(Throwable $e, ApprovalHandBackReason $expected): void
    {
        self::assertSame($expected, ApprovalHandBackReason::fromException($e));
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
