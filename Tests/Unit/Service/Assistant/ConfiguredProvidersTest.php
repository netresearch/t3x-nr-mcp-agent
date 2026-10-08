<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Service\Assistant\ConfiguredGuidedSkillProvider;
use Netresearch\NrMcpAgent\Service\Assistant\ConfiguredQuickTaskProvider;
use Netresearch\NrMcpAgent\Service\Assistant\GuidedSkill;
use Netresearch\NrMcpAgent\Service\Assistant\QuickTask;
use Netresearch\NrMcpAgent\Service\Assistant\SkillResolverInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The configured guided tours and frequent tasks: in the configured order,
 * and only those an editor can read a name for.
 */
final class ConfiguredProvidersTest extends TestCase
{
    use SkillLabelsStub;

    private const LABELS = [
        'skill.seo-optimieren.title' => 'SEO optimieren',
        'skill.seo-optimieren.description' => 'Seitentitel und Meta Description prüfen.',
        'skill.inhalt-verbessern.title' => 'Inhalt verbessern',
        'task.neue-seite' => 'Neue Seite anlegen',
    ];

    /** Every skill resolves to `3:<skill>`, except those listed, which do not resolve. */
    private function resolver(string ...$missing): SkillResolverInterface
    {
        $resolver = $this->createMock(SkillResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static fn(string $skill): ?string => in_array($skill, $missing, true) ? null : '3:' . $skill);

        return $resolver;
    }

    #[Test]
    public function guidedToursCarryTheirTitleAndDescriptionAndSkipUnlabelledSkills(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getDashboardGuidedSkills')->willReturn(['inhalt-verbessern', 'unlabelled', 'seo-optimieren']);

        $skills = (new ConfiguredGuidedSkillProvider($config, $this->skillLabels(self::LABELS), $this->resolver()))->getGuidedSkills();

        self::assertEquals(
            [
                new GuidedSkill('3:inhalt-verbessern', 'Inhalt verbessern', ''),
                new GuidedSkill('3:seo-optimieren', 'SEO optimieren', 'Seitentitel und Meta Description prüfen.'),
            ],
            $skills,
        );
    }

    #[Test]
    public function quickTasksCarryTheirLabelAndPageNeedAndSkipUnlabelledSkills(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getDashboardQuickTasks')->willReturn([
            ['skill' => 'seo-optimieren', 'needsPage' => true],
            ['skill' => 'unlabelled', 'needsPage' => false],
            ['skill' => 'neue-seite', 'needsPage' => false],
        ]);

        $tasks = (new ConfiguredQuickTaskProvider($config, $this->skillLabels(self::LABELS), $this->resolver()))->getQuickTasks();

        self::assertEquals(
            [
                new QuickTask('SEO optimieren', '3:seo-optimieren', true),
                new QuickTask('Neue Seite anlegen', '3:neue-seite', false),
            ],
            $tasks,
        );
    }

    /** A labelled skill the chat cannot start is not offered: no link the chat would refuse. */
    #[Test]
    public function aGuidedTourThatDoesNotResolveIsLeftOut(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getDashboardGuidedSkills')->willReturn(['seo-optimieren', 'inhalt-verbessern']);

        $skills = (new ConfiguredGuidedSkillProvider($config, $this->skillLabels(self::LABELS), $this->resolver('seo-optimieren')))->getGuidedSkills();

        self::assertSame(['3:inhalt-verbessern'], array_map(static fn(GuidedSkill $skill): string => $skill->identifier, $skills));
    }

    #[Test]
    public function aQuickTaskThatDoesNotResolveIsLeftOut(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getDashboardQuickTasks')->willReturn([
            ['skill' => 'seo-optimieren', 'needsPage' => true],
            ['skill' => 'neue-seite', 'needsPage' => false],
        ]);

        $tasks = (new ConfiguredQuickTaskProvider($config, $this->skillLabels(self::LABELS), $this->resolver('neue-seite')))->getQuickTasks();

        self::assertSame(['3:seo-optimieren'], array_map(static fn(QuickTask $task): string => $task->skill, $tasks));
    }
}
