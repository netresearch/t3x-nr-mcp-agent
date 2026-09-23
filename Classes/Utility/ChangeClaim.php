<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Utility;

/**
 * Whether an assistant answer claims a completed change (ADR-017).
 *
 * Only the trigger for a notice, never the evidence: the chat shows "nothing
 * was saved" when this matches AND the run wrote nothing. A match on an answer
 * that merely talks about a change therefore costs a notice that is still
 * true, which is why the list may be generous.
 *
 * The words are the ones the demo answers used ("Erledigt", "angelegt",
 * "gespeichert", "created", "updated") plus their near relatives, in German
 * and English, the two languages the backend ships labels for.
 *
 * A word preceded by a negation within the three words before it does not
 * count: "es wurde nichts geändert" and "has not been saved" say the opposite
 * of a claim, and an answer made only of such sentences — the honest one after
 * a denied approval — must not be flagged as if it claimed success.
 */
final class ChangeClaim
{
    private const WORDS = 'erledigt|gespeichert|angelegt|erstellt|geändert|aktualisiert|hinzugefügt|eingefügt|eingetragen'
        . '|verschoben|gelöscht|übernommen|veröffentlicht|gesetzt'
        . '|done|created|updated|saved|changed|added|inserted|moved|deleted|removed|published';

    private const NEGATIONS = ['nicht', 'nichts', 'kein', 'keine', 'keinen', 'keiner', 'nie', 'not', 'no', 'nothing', 'never', "n't"];

    /** How many words before a claim word are searched for a negation. */
    private const NEGATION_WINDOW = 3;

    public static function matches(string $text): bool
    {
        $count = preg_match_all(
            '/(?<![\p{L}\p{N}])(?:' . self::WORDS . ')(?![\p{L}\p{N}])/iu',
            $text,
            $found,
            PREG_OFFSET_CAPTURE,
        );
        if ($count === false || $count === 0) {
            return false;
        }

        foreach ($found[0] as [, $offset]) {
            if (!self::isNegated(substr($text, 0, $offset))) {
                return true;
            }
        }

        return false;
    }

    private static function isNegated(string $before): bool
    {
        // The sentence the claim word stands in, and in it the last few words.
        $sentence = preg_split('/[.!?:\n]/u', $before);
        $clause   = is_array($sentence) ? (string) end($sentence) : $before;
        $words    = preg_split('/[^\p{L}\']+/u', mb_strtolower($clause), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) {
            return false;
        }

        foreach (array_slice($words, -self::NEGATION_WINDOW) as $word) {
            if (in_array($word, self::NEGATIONS, true) || str_ends_with($word, "n't")) {
                return true;
            }
        }

        return false;
    }
}
