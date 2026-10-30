<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant;

use Netresearch\NrMcpAgent\Service\Assistant\ContentTypeProvider;
use Netresearch\NrMcpAgent\Service\ToolAvailabilityInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Which kinds "Create content" offers: those whose table exists, which the
 * user may change, and whose tool the user's chat may call.
 */
final class ContentTypeProviderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $globals = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->globals  = ['TCA' => $GLOBALS['TCA'] ?? null, 'BE_USER' => $GLOBALS['BE_USER'] ?? null];
        $GLOBALS['TCA'] = ['pages' => ['ctrl' => []], 'tx_news_domain_model_news' => ['ctrl' => []]];
    }

    protected function tearDown(): void
    {
        foreach ($this->globals as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }

        parent::tearDown();
    }

    /**
     * @param list<string> $modifiable
     */
    private function user(bool $admin, array $modifiable = []): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('check')->willReturnCallback(static fn (string $type, string $value): bool => $type === 'tables_modify' && in_array($value, $modifiable, true));

        return $user;
    }

    /**
     * @param list<string> $unavailable
     */
    private function provider(array $unavailable = [], ?array &$asked = null): ContentTypeProvider
    {
        $tools = $this->createMock(ToolAvailabilityInterface::class);
        $tools->method('unavailableTools')->willReturnCallback(static function (array $names) use ($unavailable, &$asked): array {
            $asked = $names;

            return array_values(array_intersect($names, $unavailable));
        });

        return new ContentTypeProvider($tools);
    }

    #[Test]
    public function anAdminGetsEveryKindWhoseToolIsAvailable(): void
    {
        $GLOBALS['BE_USER'] = $this->user(true);

        self::assertSame(['page', 'news'], $this->provider([], $asked)->available());
        self::assertSame(['create_page_draft', 'create_record_draft'], $asked);
        self::assertSame(['news'], $this->provider(['create_page_draft'])->available());
        self::assertSame(['page'], $this->provider(['create_record_draft'])->available());
    }

    #[Test]
    public function aKindWhoseTableDoesNotExistIsLeftOut(): void
    {
        $GLOBALS['BE_USER'] = $this->user(true);
        unset($GLOBALS['TCA']['tx_news_domain_model_news']);

        self::assertSame(['page'], $this->provider([], $asked)->available());
        self::assertSame(['create_page_draft'], $asked);
    }

    #[Test]
    public function anEditorGetsOnlyTheTablesTheyMayChange(): void
    {
        $GLOBALS['BE_USER'] = $this->user(false, ['tx_news_domain_model_news']);

        self::assertSame(['news'], $this->provider([], $asked)->available());
        self::assertSame(['create_record_draft'], $asked);
    }

    #[Test]
    public function withoutAnyTableTheToolsAreNotAsked(): void
    {
        $GLOBALS['BE_USER'] = $this->user(false);
        $tools              = $this->createMock(ToolAvailabilityInterface::class);
        $tools->expects(self::never())->method('unavailableTools');

        self::assertSame([], (new ContentTypeProvider($tools))->available());
    }

    #[Test]
    public function withoutABackendUserNothingIsOffered(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertSame([], $this->provider()->available());
    }
}
