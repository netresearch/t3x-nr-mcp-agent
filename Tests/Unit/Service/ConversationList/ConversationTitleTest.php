<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\ConversationList;

use Netresearch\NrMcpAgent\Service\ConversationList\ConversationTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The title rules of the conversation lists (ADR-027).
 */
final class ConversationTitleTest extends TestCase
{
    private const STARTS = ['Check this page', 'Diese Seite prüfen'];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titles(): iterable
    {
        yield 'a short title stays' => ['SEO der Startseite', 'SEO der Startseite'];
        yield 'whitespace and line breaks collapse' => ["  Bitte\n\nprüfe   die\tSeite ", 'Bitte prüfe die Seite'];
        yield 'exactly the limit stays' => [str_repeat('a', 60), str_repeat('a', 60)];
        yield 'a long text is cut at a word boundary' => [
            'Welche Inhaltselemente auf der Seite Produkte haben keine Überschrift und wie viele sind es?',
            'Welche Inhaltselemente auf der Seite Produkte haben keine…',
        ];
        yield 'a first sentence that fits is taken' => [
            'Prüfe die Startseite auf Barrierefreiheit. Danach bitte auch alle Unterseiten der Rubrik Service.',
            'Prüfe die Startseite auf Barrierefreiheit.',
        ];
        yield 'a question counts as a sentence' => [
            'Warum lädt die Startseite so langsam? Ich sehe im Netzwerk-Tab über 80 Anfragen beim ersten Aufruf.',
            'Warum lädt die Startseite so langsam?',
        ];
        yield 'a first sentence too short to say anything is not taken' => [
            'Hallo. Kannst du mir bitte erklären, wie ich ein neues Inhaltselement für Teaser anlege?',
            'Hallo. Kannst du mir bitte erklären, wie ich ein neues…',
        ];
        yield 'a word longer than the limit is cut inside' => [str_repeat('x', 70), str_repeat('x', 60) . '…'];
        yield 'a trailing comma before the cut goes' => [
            'Erstelle eine Liste aller Seiten, die seit Januar nicht geändert wurden, und sortiere sie',
            'Erstelle eine Liste aller Seiten, die seit Januar nicht…',
        ];
        yield 'multibyte characters count as one' => [str_repeat('ü', 60), str_repeat('ü', 60)];
    }

    #[Test]
    #[DataProvider('titles')]
    public function aTitleIsShortenedForAList(string $title, string $expected): void
    {
        self::assertSame($expected, ConversationTitle::shorten($title));
        self::assertLessThanOrEqual(ConversationTitle::MAX_LENGTH + 1, mb_strlen(ConversationTitle::shorten($title)));
    }

    /**
     * @return iterable<string, array{string, string|null, bool}>
     */
    public static function tourTitles(): iterable
    {
        yield 'empty' => ['', 'SEO optimieren', true];
        yield 'the German start message' => ['Diese Seite prüfen', 'SEO optimieren', true];
        yield 'the English start message' => ['Check this page', 'SEO optimieren', true];
        yield 'a start message with stray whitespace' => [" Diese Seite\nprüfen", 'SEO optimieren', true];
        yield 'the skill alone' => ['SEO optimieren', 'SEO optimieren', true];
        yield 'skill and page, as a new tour stores it' => ['SEO optimieren · Über uns', 'SEO optimieren', true];
        yield 'a renamed tour' => ['Meine SEO-Runde', 'SEO optimieren', false];
        yield 'a rename that only begins with the skill' => ['SEO optimieren für Kontakt', 'SEO optimieren', false];
        yield 'another skill' => ['Barrierefreiheit · Über uns', 'SEO optimieren', false];
        yield 'no tour name' => ['SEO optimieren · Über uns', null, false];
        yield 'an empty tour name never matches' => [' · Über uns', '', false];
    }

    #[Test]
    #[DataProvider('tourTitles')]
    public function automaticTourTitlesAreRecognised(string $title, ?string $tourName, bool $automatic): void
    {
        self::assertSame($automatic, ConversationTitle::isAutomaticTourTitle($title, self::STARTS, $tourName));
    }

    #[Test]
    public function aTourTitleNamesTheSkillAndThePage(): void
    {
        self::assertSame('SEO optimieren · Über uns', ConversationTitle::tourTitle('SEO optimieren', 'Über uns'));
        self::assertSame('SEO optimieren · Über uns', ConversationTitle::tourTitle(' SEO  optimieren', "Über\nuns "));
        self::assertSame('SEO optimieren', ConversationTitle::tourTitle('SEO optimieren', null));
        self::assertSame('SEO optimieren', ConversationTitle::tourTitle('SEO optimieren', '  '));
    }
}
