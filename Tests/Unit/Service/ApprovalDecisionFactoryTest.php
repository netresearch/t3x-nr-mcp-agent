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

    #[Test]
    public function anApprovalIsAnApproval(): void
    {
        $decision = (new ApprovalDecisionFactory())->create(true, 7, 'digest-abc', DenyReason::Skip);

        self::assertTrue($decision->approved);
    }
}
