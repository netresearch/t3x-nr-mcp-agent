<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Utility;

use Netresearch\NrMcpAgent\Utility\ContinueIntent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContinueIntent::class)]
final class ContinueIntentTest extends TestCase
{
    /**
     * The messages that, on the demo, each started a new run over a transcript
     * that was waiting for an approval (NEXT-167, analysis C.4), verbatim, and
     * their English twins.
     *
     * @return iterable<string, array{string}>
     */
    public static function continueMessages(): iterable
    {
        yield 'conversation 79, U2' => ['gern weiter'];
        yield 'conversation 79, U4' => ['alles approved'];
        yield 'conversation 79, U5' => ['habe alles freigegeben'];
        yield 'conversation 79, U6' => ['habe ich'];
        yield 'conversation 84, U2' => ['weiter'];
        yield 'with punctuation' => ['Weiter!'];
        yield 'polite' => ['ok, bitte weiter'];
        yield 'English' => ['continue'];
        yield 'English, approved' => ['I approved it'];
        yield 'go ahead' => ['go ahead please'];
    }

    #[Test]
    #[DataProvider('continueMessages')]
    public function aMessageThatOnlySaysGoOnMatches(string $message): void
    {
        self::assertTrue(ContinueIntent::matches($message));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function newRequests(): iterable
    {
        yield 'conversation 80, U3' => ['Es fehlt noch das Element für die neue Unterseite'];
        yield 'a new instruction after the word' => ['weiter, und setze danach noch die Meta-Description'];
        yield 'a question' => ['Hast du weiter gemacht?'];
        yield 'a correction' => ['das element ist nicht angelegt'];
        yield 'empty' => ['   '];
    }

    #[Test]
    #[DataProvider('newRequests')]
    public function aMessageThatAsksForMoreDoesNotMatch(string $message): void
    {
        self::assertFalse(ContinueIntent::matches($message));
    }
}
