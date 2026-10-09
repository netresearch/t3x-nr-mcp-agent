<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * The one place that knows how a link starts a chat with a guided skill.
 *
 * Every dashboard entry that opens the chat on a skill (and, where the skill
 * works on one page, on that page) goes through here, so the query parameters
 * the chat module reads are named once: when the chat-start side settles on
 * other names, the constants below are the only thing to change.
 *
 * The parameters are a request, not a grant. The URL is in the user's hands;
 * whoever reads them must check again that the user may use the chat and may
 * access the page.
 */
final readonly class ChatStartUriBuilder
{
    public const ROUTE = 'nr_mcp_agent_chat';

    /** Query parameter carrying the skill identifier. */
    public const PARAMETER_SKILL = 'skill';

    /** Query parameter carrying the uid of the page (default language) the skill works on. */
    public const PARAMETER_PAGE = 'pageUid';

    /** Query parameter carrying the language of the page version the skill works on (0: default language). */
    public const PARAMETER_LANGUAGE = 'languageUid';

    private const OWN_PARAMETERS = [self::PARAMETER_SKILL, self::PARAMETER_PAGE, self::PARAMETER_LANGUAGE];

    public function __construct(
        private UriBuilder $uriBuilder,
    ) {}

    /**
     * The link that opens the chat module and starts a conversation on the
     * skill, on the page in the given language when a page is given. The
     * language travels only with a page: it is the language of that page's
     * version.
     */
    public function build(string $skill, int $pageUid = 0, int $languageUid = 0): string
    {
        $parameters = [self::PARAMETER_SKILL => $skill];
        if ($pageUid > 0) {
            $parameters[self::PARAMETER_PAGE] = $pageUid;
            $parameters[self::PARAMETER_LANGUAGE] = max(0, $languageUid);
        }

        return (string) $this->uriBuilder->buildUriFromRoute(self::ROUTE, $parameters);
    }

    /**
     * What a GET form needs to reach the same URL as build() with the skill,
     * page and language taken from its own fields.
     *
     * A GET form replaces the query string of its action with its fields, so
     * every parameter of the module URL that is not one of ours (the route
     * token among them) has to travel as a hidden field.
     *
     * @return array{action: string, hidden: array<string, string>, skillField: string, pageField: string, languageField: string}
     */
    public function formTarget(): array
    {
        $uri = $this->uriBuilder->buildUriFromRoute(self::ROUTE);
        parse_str($uri->getQuery(), $query);

        $hidden = [];
        foreach ($query as $name => $value) {
            if (is_string($value) && !in_array($name, self::OWN_PARAMETERS, true)) {
                $hidden[(string) $name] = $value;
            }
        }

        return [
            'action' => (string) $uri->withQuery('')->withFragment(''),
            'hidden' => $hidden,
            'skillField' => self::PARAMETER_SKILL,
            'pageField' => self::PARAMETER_PAGE,
            'languageField' => self::PARAMETER_LANGUAGE,
        ];
    }
}
