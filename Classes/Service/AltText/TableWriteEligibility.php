<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Whether a table may be written at all, before any record or field right
 * is asked (ADR-026): not on nr-llm's denylist of credential and audit
 * tables, not `readOnly`, and not `adminOnly` unless the user is an admin.
 *
 * The list of missing alt texts and the writers ask it of the record that
 * holds the image and of every owner above it, so the list never shows an
 * image the writers then refuse.
 */
final readonly class TableWriteEligibility
{
    public function __construct(
        private TableReadAccessService $sensitive = new TableReadAccessService(),
    ) {}

    public function mayWrite(string $table, BackendUserAuthentication $user): bool
    {
        if ($this->sensitive->isSensitiveTable($table)) {
            return false;
        }

        $tca    = $GLOBALS['TCA'] ?? null;
        $config = is_array($tca) ? ($tca[$table] ?? null) : null;
        $ctrl   = is_array($config) && is_array($config['ctrl'] ?? null) ? $config['ctrl'] : [];
        if ((bool) ($ctrl['readOnly'] ?? false)) {
            return false;
        }

        return $user->isAdmin() || !(bool) ($ctrl['adminOnly'] ?? false);
    }
}
