<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Utility;

/**
 * Whether an assistant answer reads like a completed change (ADR-017).
 *
 * Only the trigger for a notice, never the evidence: the chat shows "nothing
 * was saved" when this matches AND the run recorded no write. A match on an
 * answer that merely talks about a change therefore costs a notice that is
 * still true, which is why the list may be generous.
 *
 * The words are the ones the demo answers used ("Erledigt", "angelegt",
 * "gespeichert", "created", "updated") plus their near relatives, in German
 * and English, the two languages the backend ships labels for.
 */
final class ChangeClaim
{
    private const PATTERN = '/(?<![\p{L}\p{N}])(?:'
        . 'erledigt|gespeichert|angelegt|erstellt|geändert|aktualisiert|hinzugefügt|eingefügt|eingetragen'
        . '|verschoben|gelöscht|übernommen|veröffentlicht|gesetzt'
        . '|done|created|updated|saved|changed|added|inserted|moved|deleted|removed|published'
        . ')(?![\p{L}\p{N}])/iu';

    public static function matches(string $text): bool
    {
        return preg_match(self::PATTERN, $text) === 1;
    }
}
