<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * What an editor reads for a skill: its title, its description, and the label
 * of a frequent task that starts it, in the backend user's language.
 *
 * The labels live in locallang_dashboard.xlf under keys derived from the skill
 * identifier, so an integrator labels a skill of their own through TYPO3's
 * language overrides (locallangXMLOverride). In the key, every character of
 * the identifier other than a letter, digit, `-` or `_` becomes `_`:
 * `3:skills/seo` is labelled by `skill.3_skills_seo.title`. For a qualified
 * identifier without labels of its own, the labels of its slug — the part
 * after `<source uid>:` — apply: `3:seo-optimieren` reads
 * `skill.seo-optimieren.title`. A skill without a title has no
 * name an editor would understand; callers leave it out rather than show its
 * identifier.
 */
final readonly class SkillLabels
{
    public const FILE = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_dashboard.xlf';

    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function title(string $skill): ?string
    {
        return $this->labelFor('skill.', $skill, '.title');
    }

    public function description(string $skill): ?string
    {
        return $this->labelFor('skill.', $skill, '.description');
    }

    /** The label of a frequent task: its own, else the skill's title. */
    public function taskLabel(string $skill): ?string
    {
        return $this->labelFor('task.', $skill, '') ?? $this->title($skill);
    }

    /** The part of a label key that names the skill. */
    public static function key(string $skill): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $skill);
    }

    /**
     * The label under `<prefix><key><suffix>` for the identifier, else for its
     * slug when the identifier is qualified.
     */
    private function labelFor(string $prefix, string $skill, string $suffix): ?string
    {
        $label = $this->label($prefix . self::key($skill) . $suffix);
        if ($label === null && preg_match('/^\d+:(.+)$/', $skill, $match) === 1) {
            return $this->label($prefix . self::key($match[1]) . $suffix);
        }

        return $label;
    }

    private function label(string $key): ?string
    {
        $label = trim($this->languageService()->sL(self::FILE . ':' . $key));

        return $label === '' ? null : $label;
    }

    /** The current user's, asked each time: the service is shared, the user is not. */
    private function languageService(): LanguageService
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $this->languageServiceFactory->createFromUserPreferences(
            $user instanceof BackendUserAuthentication ? $user : null,
        );
    }
}
