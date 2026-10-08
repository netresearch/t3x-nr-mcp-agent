<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

/**
 * The human name of each tool that declares one, for the approval card to
 * name the change instead of the tool (editorial rules 14, 15 and 22).
 *
 * The names are nr-llm's editor action declarations (nr-llm ADR-152): a
 * translatable label per writing tool, written for an editor rather than for
 * the model. A tool without one is simply absent, and the card then falls
 * back to a generic wording.
 */
interface EditorActionLabelsInterface
{
    /**
     * @return array<string, string> tool name => `LLL:` reference of its human name
     */
    public function labelReferences(): array;
}
