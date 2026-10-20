<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\ApprovalDenialReason;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use Netresearch\NrMcpAgent\Service\ApprovalDecisionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The seam that hands a denial's reason to nr-llm (ADR-018), as nr-llm's
 * enum ApprovalDenialReason (nr-llm PR 1024).
 */
#[CoversClass(ApprovalDecisionFactory::class)]
final class ApprovalDecisionFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{DenyReason, string}>
     */
    public static function reasons(): iterable
    {
        yield 'Andere Variante' => [DenyReason::Variant, 'variant'];
        yield 'Überspringen' => [DenyReason::Skip, 'skip'];
    }

    #[Test]
    #[DataProvider('reasons')]
    public function aDenialCarriesNrLlmsReason(DenyReason $reason, string $value): void
    {
        $decision = (new ApprovalDecisionFactory())->create(false, 7, 'digest-abc', $reason);
        self::assertFalse($decision->approved);
        self::assertSame(7, $decision->decidedByBeUser);
        self::assertSame('digest-abc', $decision->turnDigest);
        self::assertSame(ApprovalDenialReason::from($value), $decision->denialReason);
    }

    #[Test]
    public function aDenialWithoutAReasonCarriesNone(): void
    {
        self::assertNull((new ApprovalDecisionFactory())->create(false, 7, 'digest-abc')->denialReason);
    }

    /** nr-llm refuses a reason beside an approval; the factory never sends one. */
    #[Test]
    public function anApprovalIsAnApproval(): void
    {
        $decision = (new ApprovalDecisionFactory())->create(true, 7, 'digest-abc', DenyReason::Skip);

        self::assertTrue($decision->approved);
        self::assertNull($decision->denialReason);
    }
}
