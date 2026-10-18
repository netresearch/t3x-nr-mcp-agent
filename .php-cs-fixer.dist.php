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
);
