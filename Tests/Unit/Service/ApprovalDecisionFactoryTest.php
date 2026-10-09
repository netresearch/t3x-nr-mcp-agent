<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Enum\DenyReason;
use Netresearch\NrMcpAgent\Service\ApprovalDecisionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionParameter;

/**
 * The seam that hands a denial's reason to nr-llm once its decision takes
 * one (ADR-018). The installed nr-llm takes none, so what is pinned here is
 * the fallback: a plain decision with everything else intact.
 */
#[CoversClass(ApprovalDecisionFactory::class)]
final class ApprovalDecisionFactoryTest extends TestCase
{
    #[Test]
    public function theInstalledNrLlmCarriesNoReason(): void
    {
        self::assertFalse((new ApprovalDecisionFactory())->carriesReason());
    }

    #[Test]
    public function aDenialWithAReasonFallsBackToAPlainDenial(): void
    {
        $decision = (new ApprovalDecisionFactory())->create(false, 7, 'digest-abc', DenyReason::Variant);

        self::assertFalse($decision->approved);
        self::assertSame(7, $decision->decidedByBeUser);
        self::assertSame('digest-abc', $decision->turnDigest);
    }

    /**
     * nr-llm PR 1024 types the argument as its enum ApprovalDenialReason
     * (cases `variant`, `skip`); a string-typed one takes the value. A type
     * that is neither, or an enum without the case, carries nothing.
     */
    #[Test]
    public function theReasonTakesTheShapeOfTheParameter(): void
    {
        $enumTyped = new ReflectionParameter(static function (?ApprovalDenialReasonFixture $denialReason = null): void {}, 0);
        $stringTyped = new ReflectionParameter(static function (?string $denialReason = null): void {}, 0);
        $intTyped = new ReflectionParameter(static function (int $denialReason = 0): void {}, 0);
        $narrowEnum = new ReflectionParameter(static function (?ApprovalDenialReasonWithoutSkip $denialReason = null): void {}, 0);

        self::assertSame(ApprovalDenialReasonFixture::Variant, ApprovalDecisionFactory::valueFor($enumTyped->getType(), DenyReason::Variant));
        self::assertSame(ApprovalDenialReasonFixture::Skip, ApprovalDecisionFactory::valueFor($enumTyped->getType(), DenyReason::Skip));
        self::assertSame('skip', ApprovalDecisionFactory::valueFor($stringTyped->getType(), DenyReason::Skip));
        self::assertNull(ApprovalDecisionFactory::valueFor($intTyped->getType(), DenyReason::Skip));
        self::assertNull(ApprovalDecisionFactory::valueFor($narrowEnum->getType(), DenyReason::Skip));
    }

    #[Test]
    public function anApprovalIsAnApproval(): void
    {
        $decision = (new ApprovalDecisionFactory())->create(true, 7, 'digest-abc', DenyReason::Skip);

        self::assertTrue($decision->approved);
    }
}

/** The shape of nr-llm's ApprovalDenialReason (nr-llm PR 1024). */
enum ApprovalDenialReasonFixture: string
{
    case Variant = 'variant';
    case Skip = 'skip';
}

enum ApprovalDenialReasonWithoutSkip: string
{
    case Variant = 'variant';
}
