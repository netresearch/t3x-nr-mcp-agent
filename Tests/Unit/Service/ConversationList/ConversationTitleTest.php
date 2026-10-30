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
     * @return iterable<string, array{string, bool}>
     */
    public static function tourTitles(): iterable
    {
        yield 'empty' => ['', true];
        yield 'the German start message' => ['Diese Seite prüfen', true];
        yield 'the English start message' => ['Check this page', true];
        yield 'a start message with stray whitespace' => [" Diese Seite\nprüfen", true];
        yield 'the skill alone' => ['SEO optimieren', true];
        yield 'skill and page' => ['SEO optimieren · Über uns', true];
        yield 'skill and page with stray whitespace' => ['SEO optimieren  · Über  uns ', true];
        // Exactly, never a prefix: the rename field starts with the derived
        // title, and what the editor adds to it is theirs.
        yield 'skill and page with an addition' => ['SEO optimieren · Über uns (Kunde X)', false];
        yield 'skill and another page' => ['SEO optimieren · Alter Titel', false];
        yield 'a rename that only begins with the skill' => ['SEO optimieren für Kontakt', false];
        yield 'a start message with an addition' => ['Diese Seite prüfen, bitte', false];
        yield 'a renamed tour' => ['Meine SEO-Runde', false];
    }

    #[Test]
    #[DataProvider('tourTitles')]
    public function automaticTourTitlesAreRecognisedExactly(string $title, bool $automatic): void
    {
        self::assertSame($automatic, ConversationTitle::isAutomaticTourTitle($title, self::STARTS, ['SEO optimieren', 'SEO optimieren · Über uns']));
    }

    /**
     * A reader counts grapheme clusters, not code points: a cut never splits
     * an emoji with its skin tone, a flag, or a letter from its combining
     * accent.
     *
     * @return iterable<string, array{string}>
     */
    public static function clusters(): iterable
    {
        yield 'emoji with skin tone' => ['👍🏽'];
        yield 'flag' => ['🇩🇪'];
        yield 'combining accent' => ["e\u{0301}"];
        yield 'family' => ['👩‍👩‍👧'];
    }

    #[Test]
    #[DataProvider('clusters')]
    public function aCutNeverSplitsAGraphemeCluster(string $cluster): void
    {
        // 59 letters, then the cluster, then more: the cut at 60 falls on it.
        $title = str_repeat('a', 59) . $cluster . 'bbbb';

        $short = ConversationTitle::shorten($title);

        self::assertSame(str_repeat('a', 59) . $cluster . '…', $short);
    }

    /** A title of 60 clusters is not cut, however many code points it has. */
    #[Test]
    public function aTitleIsMeasuredInGraphemeClusters(): void
    {
        $title = str_repeat('👍🏽', 60);

        self::assertSame($title, ConversationTitle::shorten($title));
    }

    #[Test]
    public function aTourTitleNamesTheSkillAndThePage(): void
    {
        self::assertSame('SEO optimieren · Über uns', ConversationTitle::tourTitle('SEO optimieren', 'Über uns'));
        self::assertSame('SEO optimieren · Über uns', ConversationTitle::tourTitle(' SEO  optimieren', "Über\nuns "));
        self::assertSame('SEO optimieren', ConversationTitle::tourTitle('SEO optimieren', null));
        self::assertSame('SEO optimieren', ConversationTitle::tourTitle('SEO optimieren', '  '));
    }

    /**
     * The column counts code points; a cluster that would not fit whole is
     * left out, and a title that fits is kept as it is.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function fittedTitles(): iterable
    {
        yield 'fits exactly' => ['abcde', 5, 'abcde'];
        yield 'cut at the limit' => ['abcdef', 5, 'abcde'];
        yield 'emoji with skin tone across the limit' => ["abcd\u{1F44D}\u{1F3FD}", 5, 'abcd'];
        yield 'emoji with skin tone inside the limit' => ["abc\u{1F44D}\u{1F3FD}x", 5, "abc\u{1F44D}\u{1F3FD}"];
        yield 'combining accent across the limit' => ["abcde\u{0301}", 5, 'abcd'];
        yield 'no trailing space' => ['abc  def', 5, 'abc'];
    }

    #[Test]
    #[DataProvider('fittedTitles')]
    public function aTitleIsFittedToTheColumnWithoutSplittingACharacter(string $title, int $max, string $expected): void
    {
        self::assertSame($expected, ConversationTitle::fit($title, $max));
    }

    #[Test]
    public function theColumnHolds255Characters(): void
    {
        self::assertSame(str_repeat('ä', 255), ConversationTitle::fit(str_repeat('ä', 300)));
    }
}
