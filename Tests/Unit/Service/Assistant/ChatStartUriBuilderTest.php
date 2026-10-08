<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant;

use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;

/**
 * The single place that names the chat-start parameters. The functional
 * ChatStartUriBuilderTest checks the form target against a real module URL.
 */
final class ChatStartUriBuilderTest extends TestCase
{
    /**
     * @param list<array{0: string, 1: array<string, int|string>}> $calls
     */
    private function builder(array &$calls): ChatStartUriBuilder
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static function (string $route, array $parameters = []) use (&$calls): Uri {
                $calls[] = [$route, $parameters];

                return new Uri('/typo3/module/chat?' . http_build_query(['token' => 'abc'] + $parameters) . '#x');
            },
        );

        return new ChatStartUriBuilder($uriBuilder);
    }

    #[Test]
    public function aSkillOnAPageIsStartedThroughTheChatModule(): void
    {
        $calls = [];
        $uri = $this->builder($calls)->build('seo-optimieren', 21);

        self::assertSame([['nr_mcp_agent_chat', ['skill' => 'seo-optimieren', 'pageUid' => 21, 'languageUid' => 0]]], $calls);
        self::assertSame('/typo3/module/chat?token=abc&skill=seo-optimieren&pageUid=21&languageUid=0#x', $uri);
    }

    /** Decision 4: the language of the page version travels with the page, in #199's `languageUid`. */
    #[Test]
    public function thePagesLanguageTravelsWithThePage(): void
    {
        $calls = [];
        $uri = $this->builder($calls)->build('3:seo-optimieren', 21, 2);

        self::assertSame([['nr_mcp_agent_chat', ['skill' => '3:seo-optimieren', 'pageUid' => 21, 'languageUid' => 2]]], $calls);
        self::assertStringContainsString('&languageUid=2', $uri);
    }

    /** No page, no language; a negative language is the default one. */
    #[Test]
    public function aLanguageWithoutAPageIsNotSentAndANegativeOneIsTheDefault(): void
    {
        $calls = [];
        $this->builder($calls)->build('neue-seite', 0, 2);
        $this->builder($calls)->build('seo-optimieren', 21, -1);

        self::assertSame(['skill' => 'neue-seite'], $calls[0][1]);
        self::assertSame(0, $calls[1][1]['languageUid']);
    }

    #[Test]
    public function aSkillWithoutAPageCarriesNoPageParameter(): void
    {
        $calls = [];
        $this->builder($calls)->build('neue-seite');

        self::assertSame([['nr_mcp_agent_chat', ['skill' => 'neue-seite']]], $calls);
    }

    /** The form's own fields win: the module URL's values for them are not repeated as hidden fields. */
    #[Test]
    public function theFormTargetLeavesTheSkillAndPageToTheFormsFields(): void
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/module/chat?token=abc&skill=x&pageUid=3&languageUid=1&id=7'));

        $target = (new ChatStartUriBuilder($uriBuilder))->formTarget();

        self::assertSame(['token' => 'abc', 'id' => '7'], $target['hidden']);
    }

    #[Test]
    public function theFormTargetCarriesEveryOtherParameterAsAHiddenField(): void
    {
        $calls = [];
        $target = $this->builder($calls)->formTarget();

        self::assertSame(
            ['action' => '/typo3/module/chat', 'hidden' => ['token' => 'abc'], 'skillField' => 'skill', 'pageField' => 'pageUid', 'languageField' => 'languageUid'],
            $target,
        );
    }
}
