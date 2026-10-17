<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

/**
 * What became of a proposal of a guided process, as the chat records it
 * after nr-llm has accepted the decision on its card (ADR-023; nr-llm
 * ADR-214, item 9).
 *
 * The first three are nr-llm ADR-214's states of an approved call: applied,
 * approved but to be checked, not applied. The other two are the denials
 * that the card offers.
 */
enum ProposalOutcome: string
{
    case Applied = 'applied';
    case Check = 'check';
    case NotApplied = 'not_applied';
    case Skipped = 'skipped';
    case Variant = 'variant';
}
