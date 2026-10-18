<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

$createConfig = require __DIR__ . '/.Build/vendor/netresearch/typo3-ci-workflows/config/php-cs-fixer/config.php';

return $createConfig(
    "SPDX-License-Identifier: GPL-2.0-or-later\nSPDX-FileCopyrightText: Netresearch DTT GmbH",
    __DIR__,
    [
        // Stricter than the shared set, which narrows the rule to arrays,
        // arguments and parameters (below its own @PER-CS3x0 base). This is
        // the @PER-CS2.0 value this file used before the shared factory, so
        // match arms and array destructuring keep their trailing comma too.
        'trailing_comma_in_multiline' => [
            'after_heredoc' => true,
            'elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters'],
        ],
    ],
);
