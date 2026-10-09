<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant;

use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final class SkillLabelsTest extends TestCase
{
    use SkillLabelsStub;

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /** Labels are in the language of the user looking at them. */
    #[Test]
    public function labelsAreInTheCurrentUsersLanguage(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $GLOBALS['BE_USER'] = $user;
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturn('SEO optimieren');
        $factory = $this->createMock(LanguageServiceFactory::class);
        $factory->expects(self::once())->method('createFromUserPreferences')->with(self::identicalTo($user))->willReturn($languageService);

        self::assertSame('SEO optimieren', (new SkillLabels($factory))->title('seo'));
    }

    #[Test]
    public function aSkillIsNamedByItsLabels(): void
    {
        $labels = $this->skillLabels(['skill.seo.title' => 'SEO optimieren', 'skill.seo.description' => ' Seitentitel prüfen ']);

        self::assertSame('SEO optimieren', $labels->title('seo'));
        self::assertSame('Seitentitel prüfen', $labels->description('seo'));
    }

    /** A catalogue identifier carries `:` and `/`; its label key replaces them with `_`. */
    #[Test]
    public function aCatalogueIdentifierIsLabelledUnderASafeKey(): void
    {
        $labels = $this->skillLabels(['skill.3_skills_seo-optimieren.title' => 'SEO optimieren', 'task.3_skills_seo-optimieren' => 'SEO prüfen']);

        self::assertSame('3_skills_seo-optimieren', SkillLabels::key('3:skills/seo-optimieren'));
        self::assertSame('SEO optimieren', $labels->title('3:skills/seo-optimieren'));
        self::assertSame('SEO prüfen', $labels->taskLabel('3:skills/seo-optimieren'));
    }

    /** Decision 1: a resolved identifier reads the labels of its slug unless it has its own. */
    #[Test]
    public function aQualifiedIdentifierFallsBackToTheLabelsOfItsSlug(): void
    {
        $labels = $this->skillLabels([
            'skill.seo-optimieren.title' => 'SEO optimieren',
            'skill.seo-optimieren.description' => 'Seitentitel prüfen',
            'task.seo-optimieren' => 'SEO prüfen',
            'skill.4_seo-optimieren.title' => 'SEO (Quelle 4)',
        ]);

        self::assertSame('SEO optimieren', $labels->title('3:seo-optimieren'));
        self::assertSame('Seitentitel prüfen', $labels->description('3:seo-optimieren'));
        self::assertSame('SEO prüfen', $labels->taskLabel('3:seo-optimieren'));
        self::assertSame('SEO (Quelle 4)', $labels->title('4:seo-optimieren'));
        self::assertNull($labels->title('x:seo-optimieren'));
    }

    #[Test]
    public function aSkillWithoutALabelHasNoName(): void
    {
        $labels = $this->skillLabels(['skill.seo.title' => '   ']);

        self::assertNull($labels->title('seo'));
        self::assertNull($labels->title('unknown'));
        self::assertNull($labels->description('seo'));
    }

    #[Test]
    public function aTaskUsesItsOwnLabelElseTheSkillTitle(): void
    {
        $labels = $this->skillLabels(['task.a' => 'Meta Descriptions prüfen', 'skill.a.title' => 'A', 'skill.b.title' => 'Inhalt verbessern']);

        self::assertSame('Meta Descriptions prüfen', $labels->taskLabel('a'));
        self::assertSame('Inhalt verbessern', $labels->taskLabel('b'));
        self::assertNull($labels->taskLabel('c'));
    }
}
