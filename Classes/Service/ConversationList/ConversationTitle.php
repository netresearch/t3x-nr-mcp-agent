<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ConversationList;

/**
 * The title a list shows for a conversation (ADR-027).
 *
 * The stored title is the conversation's first user message, cut at 255
 * characters, the title "Alt-Text: <file>" of a conversation about a missing
 * alt text, or the title the editor gave it. A guided tour used to start with
 * the fixed message "Diese Seite prüfen", so every tour carried that title;
 * new tours store "<skill> · <page>" instead.
 *
 * The rules, decided at display time so that stored rows need no migration:
 * - a guided tour whose title is still automatic — empty, a start message of
 *   a tour in any shipped language, or "<skill> · …" as a new tour stores it —
 *   is shown as "<skill> · <page>", with the page's current title;
 * - any other title is shown as the editor reads it best in a list: the
 *   whitespace collapsed, and a long one shortened to its first sentence or
 *   cut at a word boundary with "…". The full text stays available as the
 *   tooltip. A renamed title is short and passes unchanged; a rename sets no
 *   marker, so "renamed" is everything that is not automatic.
 *
 * Pure functions; ConversationListPresenter supplies the labels.
 */
final class ConversationTitle
{
    /** Separates the parts of a derived title and of a context line. */
    public const SEPARATOR = ' · ';

    /** Where a list cuts a title. */
    public const MAX_LENGTH = 60;

    /**
     * A first sentence shorter than this ("Hi.") says too little to stand for
     * the conversation; the cut at the word boundary is used instead.
     */
    private const MIN_SENTENCE_LENGTH = 20;

    /** Collapse runs of whitespace, line breaks included, to one space. */
    public static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The title shortened for a list: unchanged up to $max characters, else
     * its first sentence when that fits and is long enough to say something,
     * else cut at the last word boundary before $max, with "…".
     */
    public static function shorten(string $title, int $max = self::MAX_LENGTH): string
    {
        $title = self::collapse($title);
        if (mb_strlen($title) <= $max) {
            return $title;
        }

        if (preg_match('/^(.+?[.!?])\s/u', $title, $match) === 1) {
            $sentence = $match[1];
            $length   = mb_strlen($sentence);
            if ($length >= self::MIN_SENTENCE_LENGTH && $length <= $max) {
                return $sentence;
            }
        }

        $cut   = mb_substr($title, 0, $max);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t,;:-–—") . '…';
    }

    /**
     * Whether the stored title of a guided tour is one the extension gave it,
     * so the derived title may replace it.
     *
     * @param list<string> $startMessages the tour's start message in every shipped language
     * @param string|null  $tourName      the skill's title, or the name a new tour stored with the page
     */
    public static function isAutomaticTourTitle(string $title, array $startMessages, ?string $tourName): bool
    {
        $title = self::collapse($title);
        if ($title === '' || in_array($title, array_map(self::collapse(...), $startMessages), true)) {
            return true;
        }

        return $tourName !== null && $tourName !== ''
            && ($title === $tourName || str_starts_with($title, $tourName . self::SEPARATOR));
    }

    /**
     * The title of a guided tour: "<skill> · <page>", or the skill alone when
     * the page cannot be named (the user may not see it, or it is deleted).
     */
    public static function tourTitle(string $tourName, ?string $pageTitle): string
    {
        $tourName  = self::collapse($tourName);
        $pageTitle = $pageTitle === null ? '' : self::collapse($pageTitle);

        return $pageTitle === '' ? $tourName : $tourName . self::SEPARATOR . $pageTitle;
    }
}
