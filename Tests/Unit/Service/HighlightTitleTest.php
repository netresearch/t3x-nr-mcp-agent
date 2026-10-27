<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Service\HighlightTargetPolicyInterface;
use Netresearch\NrMcpAgent\Service\HighlightTitle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The name of a guided step's element (NEXT-221): a highlight that names no
 * content element gets no name and reaches neither the database nor the
 * policy. Names of real elements, and every access check on them, are covered
 * by the functional ContextChipsTest against a database.
 */
#[CoversClass(HighlightTitle::class)]
final class HighlightTitleTest extends TestCase
{
    /**
     * @return iterable<string, array{array{table: string, uid: int}|null}>
     */
    public static function highlightsWithoutAnElement(): iterable
    {
        yield 'no highlight' => [null];
        yield 'a page, not a content element' => [['table' => 'pages', 'uid' => 20]];
        yield 'uid zero' => [['table' => 'tt_content', 'uid' => 0]];
        yield 'negative uid' => [['table' => 'tt_content', 'uid' => -1]];
    }

    /**
     * @param array{table: string, uid: int}|null $highlight
     */
    #[Test]
    #[DataProvider('highlightsWithoutAnElement')]
    public function aHighlightThatNamesNoContentElementHasNoName(?array $highlight): void
    {
        $policy = $this->createMock(HighlightTargetPolicyInterface::class);
        $policy->expects(self::never())->method('allows');
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->expects(self::never())->method('check');

        $conversation = new Conversation();
        $conversation->setBeUser(3);
        $conversation->setViewContext(20, 'web_layout');

        self::assertSame('', (new HighlightTitle($policy))->of($conversation, $highlight, $user));
    }
}
