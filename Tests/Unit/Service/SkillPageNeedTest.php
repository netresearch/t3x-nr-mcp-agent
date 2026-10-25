<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Service\SkillPageNeed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The page need of a catalogue entry (ADR-025): the skill's declaration, or
 * the behaviour from before for a skill that declares nothing.
 */
#[CoversClass(SkillPageNeed::class)]
final class SkillPageNeedTest extends TestCase
{
    /**
     * @return iterable<string, array{array{process?: bool|null, page?: string|null}|null, SkillPageNeed}>
     */
    public static function entries(): iterable
    {
        yield 'declared required' => [['process' => false, 'page' => 'required'], SkillPageNeed::Required];
        yield 'declared optional on a process' => [['process' => true, 'page' => 'optional'], SkillPageNeed::Optional];
        yield 'declared none on a process' => [['process' => true, 'page' => 'none'], SkillPageNeed::None];
        yield 'a process, nothing declared' => [['process' => true, 'page' => null], SkillPageNeed::Required];
        yield 'a process, nr-llm without the field' => [['process' => true], SkillPageNeed::Required];
        yield 'no process, nothing declared' => [['process' => false, 'page' => null], SkillPageNeed::Optional];
        yield 'not marked, nothing declared' => [['process' => null], SkillPageNeed::Required];
        yield 'not in the catalogue' => [null, SkillPageNeed::Required];
        yield 'a value nr-llm does not declare' => [['process' => false, 'page' => 'sometimes'], SkillPageNeed::Optional];
    }

    /**
     * @param array{process?: bool|null, page?: string|null}|null $entry
     */
    #[Test]
    #[DataProvider('entries')]
    public function theDeclarationWinsAndOtherwiseTheProcessMarkerDecides(?array $entry, SkillPageNeed $expected): void
    {
        self::assertSame($expected, SkillPageNeed::of($entry));
    }

    #[Test]
    public function onlyADeclarationIsReadAsOne(): void
    {
        self::assertSame(SkillPageNeed::None, SkillPageNeed::declaredBy(['page' => 'none']));
        self::assertNull(SkillPageNeed::declaredBy(['process' => true]));
        self::assertNull(SkillPageNeed::declaredBy(['page' => null]));
        self::assertNull(SkillPageNeed::declaredBy(null));
    }
}
