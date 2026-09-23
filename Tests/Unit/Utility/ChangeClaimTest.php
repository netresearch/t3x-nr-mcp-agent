<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Utility;

use Netresearch\NrMcpAgent\Utility\ChangeClaim;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeClaim::class)]
final class ChangeClaimTest extends TestCase
{
    /**
     * Answers from the demo installation that claimed a change none of their
     * runs had written (NEXT-167, analysis C.3 and C.5), and English twins.
     *
     * @return iterable<string, array{string}>
     */
    public static function claims(): iterable
    {
        yield 'conversation 35, U2' => ["Erledigt 👍\n\nDer Ordner **„Daten“** (inkl. aller Cluster) liegt jetzt unter **„Frontend User“**."];
        yield 'conversation 92, U19' => ["Erledigt:\n\n- Seite bearbeitet: `pages:10023`\n- Seitentitel in Englisch gesetzt auf: `ESSEC & Mannheim Executive MBA`"];
        yield 'a created element' => ['Das fehlende Element ist jetzt angelegt: Text-Element UID 10077.'];
        yield 'saved' => ['Die Meta-Description ist gespeichert.'];
        yield 'English, created' => ['I have created the page "About us" (uid 10073).'];
        yield 'English, updated' => ['Updated the SEO title of page 85.'];
        yield 'case does not matter' => ['ERLEDIGT.'];
    }

    #[Test]
    #[DataProvider('claims')]
    public function aClaimedChangeMatches(string $answer): void
    {
        self::assertTrue(ChangeClaim::matches($answer));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonClaims(): iterable
    {
        yield 'a question back (conversation 92, U28)' => ["Ich habe unter `10011` zwei Seiten mit diesem Titel gefunden:\n\n- `pages:10041`\n- `pages:10042`\n\nWelche davon soll ich bearbeiten?"];
        yield 'an offer' => ['Soll ich die Seite anlegen und die Meta-Description setzen?'];
        yield 'a word that only contains a claim' => ['Die Einstellungen sind unverändert; der Updatedienst läuft.'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('nonClaims')]
    public function anAnswerThatClaimsNoChangeDoesNotMatch(string $answer): void
    {
        self::assertFalse(ChangeClaim::matches($answer));
    }
}
