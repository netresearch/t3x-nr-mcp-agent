<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Throwable;

/**
 * The nr-llm side of {@see EditorActionLabelsInterface}.
 *
 * Injected rather than looked up in the container: nr-llm registers
 * ToolAvailabilityServiceInterface as a private service, which a container
 * lookup does not find. `editorActions()` exists in every nr-llm version this
 * extension supports (0.37 to 0.40).
 *
 * `editorActions()` runs each tool's own declaration code. A label is only
 * presentation, so any failure there costs the card its action names and
 * nothing else: the card then uses its generic wording, and the decision it
 * carries is unaffected.
 */
final readonly class NrLlmEditorActionLabels implements EditorActionLabelsInterface
{
    public function __construct(
        private ToolAvailabilityServiceInterface $toolAvailability,
    ) {}

    public function labelReferences(): array
    {
        try {
            $actions = $this->toolAvailability->editorActions();
        } catch (Throwable) {
            return [];
        }

        $labels = [];
        foreach ($actions as $toolName => $action) {
            $labels[$toolName] = $action->labelKey;
        }

        return $labels;
    }
}
