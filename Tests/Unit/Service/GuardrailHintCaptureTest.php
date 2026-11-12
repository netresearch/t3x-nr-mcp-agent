<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\ValueObject\GuardrailHint;
use Netresearch\NrLlm\Service\Guardrail\GuardrailHintCollector;
use Netresearch\NrMcpAgent\Service\GuardrailHintCapture;
use Netresearch\NrMcpAgent\Tests\Unit\Service\Fixtures\StubHint;
use Netresearch\NrMcpAgent\Tests\Unit\Service\Fixtures\StubHintCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(GuardrailHintCapture::class)]
final class GuardrailHintCaptureTest extends TestCase
{
    protected function setUp(): void
    {
        StubHintCollector::$queue    = [];
        StubHintCollector::$captures = 0;
    }

    #[Test]
    public function itNamesNrLlmsCollector(): void
    {
        self::assertSame(GuardrailHintCollector::class, GuardrailHintCapture::COLLECTOR_CLASS);
    }

    #[Test]
    public function withoutTheCollectorClassTheCallRunsPlainly(): void
    {
        $calls   = 0;
        $capture = new GuardrailHintCapture('Netresearch\\NrLlm\\Service\\Guardrail\\NoSuchCollector');

        $captured = $capture->capture(static function () use (&$calls): string {
            ++$calls;

            return 'answer';
        });

        self::assertSame(['result' => 'answer', 'hints' => []], $captured);
        self::assertSame(1, $calls);
    }

    #[Test]
    public function theCallRunsOnceInsideTheCollectorAndKeepsKindAndMessage(): void
    {
        StubHintCollector::$queue = [[
            new StubHint('violation', 'Rule broken: "best ever"', rule: 'No superlatives', passage: 'best ever'),
            new StubHint('unavailable', 'Not checked.'),
        ]];
        $calls = 0;

        $captured = (new GuardrailHintCapture(StubHintCollector::class))->capture(static function () use (&$calls): int {
            ++$calls;

            return 42;
        });

        self::assertSame(42, $captured['result']);
        self::assertSame(1, $calls);
        self::assertSame(1, StubHintCollector::$captures);
        self::assertSame(
            [
                ['kind' => 'violation', 'message' => 'Rule broken: "best ever"'],
                ['kind' => 'unavailable', 'message' => 'Not checked.'],
            ],
            $captured['hints'],
        );
    }

    /**
     * The guardrail middleware records into the shared collector the
     * container hands out; a collector made with `new` would see no hint.
     */
    #[Test]
    public function theCollectorIsTheOneTheContainerHandsOut(): void
    {
        $shared = new StubHintCollector();
        GeneralUtility::addInstance(StubHintCollector::class, $shared);

        try {
            $capture = new GuardrailHintCapture(StubHintCollector::class);
            $capture->capture(static fn (): null => null);
            // Resolved once: a second resolution would get a new instance.
            $capture->capture(static fn (): null => null);
        } finally {
            GeneralUtility::purgeInstances();
        }

        self::assertSame(2, $shared->ownCaptures);
    }

    /** The collector is resolved once and used for every later call. */
    #[Test]
    public function theCollectorIsResolvedOnce(): void
    {
        $capture = new GuardrailHintCapture(StubHintCollector::class);
        $capture->capture(static fn (): null => null);
        $capture->capture(static fn (): null => null);

        self::assertSame(2, StubHintCollector::$captures);
    }

    /**
     * With nr-llm's collector installed (ADR-288), a hint the guardrails
     * record during the call reaches the chat with its kind and message.
     * Skipped against an nr-llm release without the collector.
     */
    #[Test]
    public function nrLlmsOwnCollectorDeliversTheHintsRecordedDuringTheCall(): void
    {
        $hintClass = GuardrailHint::class;
        if (!class_exists(GuardrailHintCapture::COLLECTOR_CLASS) || !class_exists($hintClass)) {
            self::markTestSkipped('The installed nr-llm has no GuardrailHintCollector (nr-llm ADR-288).');
        }

        $collector = GeneralUtility::makeInstance(GuardrailHintCapture::COLLECTOR_CLASS);
        GeneralUtility::addInstance(GuardrailHintCapture::COLLECTOR_CLASS, $collector);
        $record = [$collector, 'record'];
        self::assertIsCallable($record);

        try {
            $captured = (new GuardrailHintCapture())->capture(static function () use ($record, $hintClass): string {
                $record(new $hintClass('ruleSetJudge', 'violation', 'Rule broken: "best ever"', 'No superlatives', 'best ever'));

                return 'proposed';
            });
        } finally {
            GeneralUtility::purgeInstances();
        }

        self::assertSame(['result' => 'proposed', 'hints' => [['kind' => 'violation', 'message' => 'Rule broken: "best ever"']]], $captured);
    }

    #[Test]
    public function entriesThatAreNoHintsAreSkipped(): void
    {
        $noMessage       = new stdClass();
        $noMessage->kind = 'violation';

        StubHintCollector::$queue = [[
            $noMessage,
            new StubHint('violation', ''),
            new StubHint('violation', 'Kept.'),
        ]];

        $captured = (new GuardrailHintCapture(StubHintCollector::class))->capture(static fn (): null => null);

        self::assertSame([['kind' => 'violation', 'message' => 'Kept.']], $captured['hints']);
    }

    #[Test]
    public function aFailingCallThrowsThroughTheCollector(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider down');

        (new GuardrailHintCapture(StubHintCollector::class))->capture(static function (): never {
            throw new RuntimeException('provider down');
        });
    }
}
