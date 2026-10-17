<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use Netresearch\NrMcpAgent\Domain\Repository\SkillTrustRepository;

/**
 * Resolves a configured skill against the chat's skill catalogue for the
 * current user.
 *
 * The catalogue names a skill `<source uid>:<path>` (nr-llm), so the same
 * skill has a different identifier on every installation. A configured
 * identifier therefore takes two forms:
 * - qualified (`3:seo-optimieren`): it resolves only to exactly that entry;
 * - a bare slug without `:` (`seo-optimieren`): it resolves to the entry
 *   whose part after `<source uid>:` equals it. Where several sources carry
 *   the slug, the most trusted source wins (nr-llm's SkillTrustLevel, read by
 *   SkillTrustRepository), then the lowest source uid.
 *
 * Either way a skill below nr-llm's minimum trust for instructions
 * (`skills.minTrustLevel`) never resolves: nr-llm drops it from the prompt,
 * so it could not guide a tour, and offering it would mislead. A slug whose
 * only sources are below the minimum resolves to nothing, and the widget says
 * that nothing is set up.
 *
 * Nothing resolves where the chat would refuse every skill at the start of a
 * conversation: no catalogue, one that says nr-llm cannot take a skill per
 * run, or a configuration that needs a second person to approve every change.
 *
 * The catalogue is the chat's `Netresearch\NrMcpAgent\Service\SkillCatalogueInterface`,
 * injected through an optional service reference (`@?` in Services.yaml), so
 * this class works whether or not that service exists yet. It is called by
 * method name for the same reason: once the catalogue is part of the
 * extension, the parameter becomes `?SkillCatalogueInterface` and the
 * method_exists() checks go away.
 */
final class CatalogueSkillResolver implements SkillResolverInterface
{
    /** @var array<string, int>|null skill record uid per catalogue identifier, read once per request */
    private ?array $catalogueEntries = null;

    /** @var array<string, string|null> */
    private array $resolved = [];

    public function __construct(
        private readonly SkillTrustRepository $trust,
        private readonly ?object $catalogue = null,
    ) {}

    public function resolve(string $skill): ?string
    {
        if ($skill === '') {
            return null;
        }

        return $this->resolved[$skill] ??= $this->lookUp($skill);
    }

    private function lookUp(string $skill): ?string
    {
        $candidates = [];
        foreach ($this->entries() as $identifier => $uid) {
            $source = preg_match('/^(\d+):(.+)$/', $identifier, $match) === 1 ? $match : null;
            $matches = str_contains($skill, ':')
                ? $identifier === $skill
                : $source !== null && $source[2] === $skill;
            if ($matches) {
                $candidates[] = ['identifier' => $identifier, 'source' => $source !== null ? (int) $source[1] : 0, 'uid' => $uid];
            }
        }

        if ($candidates === []) {
            return null;
        }

        $ranks = $this->trust->rankByUid(array_values(array_unique(array_column($candidates, 'uid'))));
        $minimum = $this->trust->minimumRank();
        $candidates = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => ($ranks[$candidate['uid']] ?? 0) >= $minimum,
        ));
        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn(array $a, array $b): int => [$ranks[$b['uid']] ?? 0, $a['source'], $a['identifier']]
                <=> [$ranks[$a['uid']] ?? 0, $b['source'], $b['identifier']],
        );

        return $candidates[0]['identifier'];
    }

    /**
     * @return array<string, int>
     */
    private function entries(): array
    {
        if ($this->catalogueEntries !== null) {
            return $this->catalogueEntries;
        }

        $this->catalogueEntries = [];
        $catalogue = $this->catalogue;
        if (
            $catalogue === null
            || !method_exists($catalogue, 'isAvailable')
            || !method_exists($catalogue, 'requiresSecondApprover')
            || !method_exists($catalogue, 'catalogue')
            || $catalogue->isAvailable() !== true
            || $catalogue->requiresSecondApprover() !== false
        ) {
            return $this->catalogueEntries;
        }

        $entries = $catalogue->catalogue();
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $identifier = is_array($entry) ? ($entry['identifier'] ?? null) : null;
            if (is_string($identifier) && $identifier !== '') {
                $uid = $entry['uid'] ?? 0;
                $this->catalogueEntries[$identifier] = is_numeric($uid) ? (int) $uid : 0;
            }
        }

        return $this->catalogueEntries;
    }
}
