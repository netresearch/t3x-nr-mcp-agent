<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use BackedEnum;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Throwable;

/**
 * The nr-llm side of {@see SkillCatalogueInterface}.
 *
 * The catalogue is what nr-llm ADR-214 calls the run's catalogue: enabled
 * skills attached to the chat's configuration or to its Task. The Task is the
 * one the user's groups map to, as for a turn (ADR-015).
 *
 * A skill reaches the run as a forced skill (`RunAugmentation`), the per-run
 * mechanism nr-llm has today. nr-llm composes it as it composes any skill —
 * as reference data, subject to its trust and data-class ceilings — and
 * removes a duplicate of an attached one. Every nr-llm call is guarded, and
 * the presence of the classes and members it needs is checked, so an nr-llm
 * without them degrades to "no skill" rather than failing the turn.
 */
final readonly class NrLlmSkillCatalogue implements SkillCatalogueInterface
{
    public function __construct(
        private ExtensionConfiguration $config,
        private TaskRepository $taskRepository,
    ) {}

    public function isAvailable(): bool
    {
        // composer.json allows nr-llm versions in which these exist; the
        // check is what keeps a future nr-llm that drops one from failing
        // every turn. PHPStan sees only the installed version.
        return class_exists(RunAugmentation::class)
            && property_exists(AgentRunRequest::class, 'augmentation')
            // @phpstan-ignore function.alreadyNarrowedType
            && method_exists(LlmConfiguration::class, 'getSkills');
    }

    public function catalogue(): array
    {
        $entries = [];
        foreach ($this->skills() as $skill) {
            $entries[$skill->getIdentifier()] ??= [
                'identifier'  => $skill->getIdentifier(),
                'name'        => $skill->getName() !== '' ? $skill->getName() : $skill->getIdentifier(),
                'description' => $skill->getDescription(),
                'uid'         => (int) $skill->getUid(),
                // nr-llm's process marker (nr-llm ADR-214, PR 1025).
                'process' => $skill->isProcess(),
                // What the skill declares about a page (nr-llm ADR-229, ADR-025).
                'page' => $this->pageOf($skill),
            ];
        }

        return array_values($entries);
    }

    public function find(string $identifier): ?array
    {
        foreach ($this->catalogue() as $entry) {
            if ($entry['identifier'] === $identifier) {
                return $entry;
            }
        }

        return null;
    }

    public function augmentationFor(string $identifier): ?RunAugmentation
    {
        if ($identifier === '') {
            return null;
        }

        foreach ($this->skills() as $skill) {
            if ($skill->getIdentifier() === $identifier) {
                return new RunAugmentation(forcedSkills: [$skill]);
            }
        }

        return null;
    }

    public function requiresSecondApprover(): bool
    {
        try {
            $task = $this->taskRepository->findByUid($this->config->getLlmTaskUid());

            return $task instanceof Task && ($task->getConfiguration()?->requiresSecondApprover() ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The skill's declared page need, null when it declares none or the
     * installed nr-llm has no such field: composer.json allows nr-llm
     * versions without it, and SkillPageNeed::of() then keeps the behaviour
     * from before. Only the enum's value is read, so the nr-llm enum need not
     * exist either.
     */
    private function pageOf(Skill $skill): ?string
    {
        if (!method_exists($skill, 'getPageNeed')) {
            return null;
        }

        $need = $skill->getPageNeed();

        return $need instanceof BackedEnum && is_string($need->value) ? $need->value : null;
    }

    /**
     * @return list<Skill>
     */
    private function skills(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $task = $this->taskRepository->findByUid($this->config->getLlmTaskUid());

            return $task instanceof Task ? $this->attachedTo($task) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The configuration's skills loaded always, its process skills loaded on
     * demand, and the Task's skills. nr-llm's form attaches a process skill
     * on demand only, and an invocation reaches either load mode (nr-llm
     * ADR-214 items 4 and 6). Other on-demand skills are the model's to load,
     * not the chat's to force.
     *
     * @return list<Skill>
     */
    private function attachedTo(Task $task): array
    {
        $configuration = $task->getConfiguration();
        $processes     = [];
        foreach ($configuration?->getSkillsOnDemand() ?? [] as $skill) {
            if ($skill instanceof Skill && $skill->isProcess()) {
                $processes[] = $skill;
            }
        }

        $skills = [];
        foreach ([...($configuration?->getSkills() ?? []), ...$processes, ...$task->getSkills()] as $skill) {
            if ($skill instanceof Skill && $skill->isEnabled() && $skill->getIdentifier() !== '') {
                $skills[] = $skill;
            }
        }

        return $skills;
    }
}
