<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Service\Tool\RunAugmentation;

/**
 * The skills a user can invoke in the chat, and how an invoked skill reaches a
 * run (ADR-019).
 *
 * The one place that knows how nr-llm takes a skill per run. nr-llm is
 * designing explicit invocation (nr-llm ADR-214); until it ships, the
 * implementation maps the identifier onto the per-run forced skills nr-llm
 * already has. Swapping that is a change here and nowhere else, and an
 * nr-llm without either answers "not available" and every skill degrades to
 * none.
 */
interface SkillCatalogueInterface
{
    /** Whether a skill can be passed to a run at all on this installation. */
    public function isAvailable(): bool;

    /**
     * The skills the current user can invoke: enabled ones attached to the
     * chat's configuration or Task, by name. Empty when not available.
     *
     * @return list<array{identifier: string, name: string, description: string, uid: int, process: bool|null}>
     */
    public function catalogue(): array;

    /**
     * One entry of catalogue(), or null when the user cannot invoke it.
     *
     * @return array{identifier: string, name: string, description: string, uid: int, process: bool|null}|null
     */
    public function find(string $identifier): ?array;

    /**
     * What a run invoking the skill carries, or null for "no skill": an empty
     * identifier, a skill that is gone, disabled or no longer attached, or an
     * nr-llm that cannot take one.
     */
    public function augmentationFor(string $identifier): ?RunAugmentation;

    /**
     * Whether the chat's configuration needs a second person to approve every
     * change (nr-llm ADR-172). A guided process is decided on the chat card
     * only (nr-llm ADR-214), where the run's owner cannot release their own
     * write, so no skill starts on such a configuration.
     */
    public function requiresSecondApprover(): bool;
}
