<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

/**
 * Whether a conversation with a skill works on one page (ADR-025).
 *
 * The skill declares it in its frontmatter (nr-llm ADR-229); the catalogue
 * entry carries the declaration as `page`. A skill that declares nothing —
 * or an nr-llm that does not expose the field — keeps the behaviour from
 * before: a process skill, and a skill the catalogue does not mark or does
 * not list, works on a page; any other may.
 */
enum SkillPageNeed: string
{
    /** Works on one page: asked for when missing, kept once set. */
    case Required = 'required';

    /** May work on a page: the page the browser shows, with every message. */
    case Optional = 'optional';

    /** Works on no page: none is bound, asked for or put into the prompt. */
    case None = 'none';

    /**
     * The page need of a catalogue entry, null for a skill the catalogue does
     * not list.
     *
     * @param array{process?: bool|null, page?: string|null}|null $entry
     */
    public static function of(?array $entry): self
    {
        $declared = self::declaredBy($entry);
        if ($declared instanceof self) {
            return $declared;
        }

        return ($entry['process'] ?? null) !== false ? self::Required : self::Optional;
    }

    /**
     * What the skill itself declares, or null where it declares nothing.
     *
     * @param array{page?: string|null}|null $entry
     */
    public static function declaredBy(?array $entry): ?self
    {
        $page = $entry['page'] ?? null;

        return is_string($page) ? self::tryFrom($page) : null;
    }
}
