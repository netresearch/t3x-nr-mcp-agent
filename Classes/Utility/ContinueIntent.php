<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Utility;

/**
 * Whether a message only says "go on" or "I approved it" (ADR-017).
 *
 * Sent while a run waits for an approval, such a message is not a new request.
 * On the demo every one of them started a new run over the same transcript,
 * which drafted the page again (NEXT-167, conversations 79, 80 and 84).
 *
 * Deliberately a short, explicit list matched against the WHOLE message: a
 * sentence that asks for anything beyond continuing is a new request and must
 * be treated as one. It never counts as an approval either — see ADR-017.
 */
final class ContinueIntent
{
    private const PATTERN = '/^(?:(?:ok(?:ay)?|ja|yes|gut|prima|danke|thanks)[\s,.!]*)*'
        . '(?:bitte\s+|please\s+)?'
        . '(?:'
        . 'weiter|mach\s+weiter|gern(?:e)?\s+weiter|weitermachen|fortfahren|fahre?\s+fort'
        . '|continue|go\s+on|go\s+ahead|proceed|carry\s+on'
        . '|(?:ich\s+)?(?:habe|hab)\s+(?:(?:alles|es|das)\s+)?(?:freigegeben|genehmigt|approved|bestätigt)'
        . '|(?:alles\s+)?(?:freigegeben|genehmigt|approved)'
        . '|habe\s+ich|hab\s+ich|done|erledigt'
        . '|(?:i\s+)?(?:have\s+)?approved(?:\s+(?:it|all|everything))?'
        . ')'
        . '(?:[\s,]+(?:bitte|please|danke|thanks))?[\s.!]*$/iu';

    public static function matches(string $message): bool
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 60) {
            return false;
        }

        return preg_match(self::PATTERN, $message) === 1;
    }
}
