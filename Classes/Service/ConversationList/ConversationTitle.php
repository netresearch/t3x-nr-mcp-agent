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
 * - a title the editor gave is marked so (Conversation::TITLE_MANUAL) and is
 *   never replaced;
 * - a guided tour whose title is automatic is shown as "<skill> · <page>",
 *   with the page's current title. Automatic is a title marked as the tour's
 *   own (Conversation::TITLE_TOUR), and, for a row without a mark, a title
 *   that is exactly one the extension gives: empty, the start message of a
 *   tour in any language the presenter knows, or the derived title itself.
 *   Never a prefix: "<skill> · <page> (Kunde X)" is the editor's;
 * - any other title is shown as the editor reads it best in a list: the
 *   whitespace collapsed, and a long one shortened to its first sentence or
 *   cut at a word boundary with "…", counted in grapheme clusters. The full
 *   text stays available as the tooltip.
 *
 * Pure functions; ConversationListPresenter supplies the labels.
 */
final class ConversationTitle
{
    /** Separates the parts of a derived title and of a context line. */
    public const SEPARATOR = ' · ';

    /** Where a list cuts a title. */
    public const MAX_LENGTH = 60;

    /** What `tx_nrmcpagent_conversation.title` holds: varchar(255), characters. */
    public const COLUMN_LENGTH = 255;

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
        if (self::length($title) <= $max) {
            return $title;
        }

        if (preg_match('/^(.+?[.!?])\s/u', $title, $match) === 1) {
            $sentence = $match[1];
            $length   = self::length($sentence);
            if ($length >= self::MIN_SENTENCE_LENGTH && $length <= $max) {
                return $sentence;
            }
        }

        $cut   = self::head($title, $max);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t,;:-–—") . '…';
    }

    /**
     * The title cut to what the column holds, at most $max characters (code
     * points, as a varchar counts them), and never inside a grapheme cluster:
     * a cluster that would not fit whole is left out.
     */
    public static function fit(string $title, int $max = self::COLUMN_LENGTH): string
    {
        if (mb_strlen($title) <= $max) {
            return $title;
        }

        $fitted   = '';
        $length   = 0;
        $clusters = preg_split('/(\X)/u', $title, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($clusters === false ? [] : $clusters as $cluster) {
            $clusterLength = mb_strlen($cluster);
            if ($length + $clusterLength > $max) {
                break;
            }

            $fitted .= $cluster;
            $length += $clusterLength;
        }

        return rtrim($fitted);
    }

    /**
     * The length in grapheme clusters — what a reader counts as characters:
     * "👍🏽" or an "e" with a combining accent is one. Code points where
     * ext-intl is missing.
     */
    private static function length(string $text): int
    {
        $length = function_exists('grapheme_strlen') ? grapheme_strlen($text) : false;

        return is_int($length) ? $length : mb_strlen($text);
    }

    /** The first $length grapheme clusters, never half of one. */
    private static function head(string $text, int $length): string
    {
        $head = function_exists('grapheme_substr') ? grapheme_substr($text, 0, $length) : false;

        return is_string($head) ? $head : mb_substr($text, 0, $length);
    }

    /**
     * Whether the stored title of a guided tour without a mark is exactly one
     * the extension gives, so the derived title may replace it. Compared
     * whole, whitespace collapsed; a title that merely begins like one is the
     * editor's.
     *
     * @param list<string> $startMessages the tour's start message in every language the presenter knows
     * @param list<string> $derived       the titles the tour would get: "<skill> · <page>", the skill alone
     */
    public static function isAutomaticTourTitle(string $title, array $startMessages, array $derived): bool
    {
        $title = self::collapse($title);

        return $title === ''
            || in_array($title, array_map(self::collapse(...), [...$startMessages, ...$derived]), true);
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
