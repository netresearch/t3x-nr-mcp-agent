<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Service\PageProperty;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The page property a guided step can be about (NEXT-221): a TCA column of
 * `pages` the user may edit, named by its label in the reader's language.
 */
#[CoversClass(PageProperty::class)]
final class PagePropertyTest extends TestCase
{
    private mixed $tca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tca      = $GLOBALS['TCA'] ?? null;
        $GLOBALS['TCA'] = ['pages' => ['columns' => [
            'title'       => ['label' => 'core.db.pages:title'],
            'description' => ['exclude' => true, 'label' => 'core.db.pages:description'],
            'nolabel'     => ['config' => []],
        ]]];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tca;
        parent::tearDown();
    }

    private function user(bool $admin, string $nonExcludeFields): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('check')->willReturnCallback(
            static fn (string $type, string $value): bool => $type === 'non_exclude_fields' && in_array($value, explode(',', $nonExcludeFields), true),
        );

        return $user;
    }

    #[Test]
    public function aColumnThatIsNotExcludedMayBeEditedByAnyone(): void
    {
        self::assertTrue(PageProperty::mayEdit('title', $this->user(false, '')));
    }

    #[Test]
    public function anExcludedColumnNeedsTheFieldOrAnAdministrator(): void
    {
        self::assertFalse(PageProperty::mayEdit('description', $this->user(false, '')));
        self::assertFalse(PageProperty::mayEdit('description', $this->user(false, 'tt_content:description')), 'the field of another table');
        self::assertTrue(PageProperty::mayEdit('description', $this->user(false, 'pages:description')));
        self::assertTrue(PageProperty::mayEdit('description', $this->user(true, '')));
    }

    #[Test]
    public function onlyAColumnOfPagesCounts(): void
    {
        $admin = $this->user(true, '');

        self::assertFalse(PageProperty::mayEdit('header', $admin), 'not a column of pages');
        self::assertFalse(PageProperty::mayEdit('', $admin));
        self::assertFalse(PageProperty::mayEdit('title,description', $admin), 'not a column name');
    }

    #[Test]
    public function theLabelIsTheTcaLabelInTheReadersLanguage(): void
    {
        $language = $this->createMock(LanguageService::class);
        $language->method('sL')->willReturnMap([['core.db.pages:description', ' Beschreibung für Suchmaschinen ']]);

        self::assertSame('Beschreibung für Suchmaschinen', PageProperty::label('description', $language));
        self::assertSame('', PageProperty::label('nolabel', $language), 'a column without a label');
        self::assertSame('', PageProperty::label('header', $language), 'not a column of pages');
        self::assertSame('', PageProperty::label('description', null), 'no language service');
    }
}
