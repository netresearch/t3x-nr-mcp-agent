<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant;

use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * SkillLabels over a fixed set of labels, keyed without the file prefix
 * ('skill.seo-optimieren.title' => 'SEO optimieren').
 */
trait SkillLabelsStub
{
    /**
     * @param array<string, string> $labels
     */
    private function skillLabels(array $labels): SkillLabels
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $input): string => str_starts_with($input, SkillLabels::FILE . ':')
                ? ($labels[substr($input, strlen(SkillLabels::FILE) + 1)] ?? '')
                : '',
        );
        $factory = $this->createMock(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturn($languageService);

        return new SkillLabels($factory);
    }
}
