<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/Classes')
    ->in(__DIR__ . '/Configuration')
    ->in(__DIR__ . '/Tests');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'header_comment' => [
            'header' => "SPDX-License-Identifier: GPL-2.0-or-later\nSPDX-FileCopyrightText: Netresearch DTT GmbH",
            'comment_type' => 'comment',
            'location' => 'after_open',
            'separate' => 'both',
        ],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_line_empty_body' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_functions' => false,
            'import_constants' => false,
        ],
    ])
    ->setFinder($finder);
