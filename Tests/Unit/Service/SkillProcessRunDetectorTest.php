<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Service\SkillCatalogueInterface;
use Netresearch\NrMcpAgent\Service\SkillProcessRunDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A conversation with a skill runs a guided process unless nr-llm marks the
 * skill as plain (ADR-022).
 */
#[CoversClass(SkillProcessRunDetector::class)]
final class SkillProcessRunDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{bool|null|false, bool}>
     */
    public static function markers(): iterable
    {
        yield 'marked as a process' => [true, true];
        yield 'nr-llm without the marker' => [null, true];
        yield 'marked as plain' => [false, false];
    }

    #[Test]
    #[DataProvider('markers')]
    public function aSkillIsAProcessUnlessMarkedPlain(?bool $process, bool $isProcess): void
    {
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('find')->with('seo-check')->willReturn(['identifier' => 'seo-check', 'name' => 'SEO', 'description' => '', 'uid' => 7, 'process' => $process]);
        $conversation = new Conversation();
        $conversation->setSkillIdentifier('seo-check', 7);

        self::assertSame($isProcess, (new SkillProcessRunDetector($skills))->isProcessRun($conversation));
    }

    #[Test]
    public function aConversationWithoutASkillIsNoProcess(): void
    {
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->expects(self::never())->method('find');

        self::assertFalse((new SkillProcessRunDetector($skills))->isProcessRun(new Conversation()));
    }

    /** A skill the user can no longer invoke still runs as the process it started. */
    #[Test]
    public function aSkillNoLongerInTheCatalogueStaysAProcess(): void
    {
        $skills = $this->createMock(SkillCatalogueInterface::class);
        $skills->method('find')->willReturn(null);
        $conversation = new Conversation();
        $conversation->setSkillIdentifier('seo-check', 7);

        self::assertTrue((new SkillProcessRunDetector($skills))->isProcessRun($conversation));
    }
}
