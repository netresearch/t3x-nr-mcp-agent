<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use TYPO3\CMS\Core\Resource\FileReference;

/**
 * The image policy until the privacy setting "Bildanalyse" exists (the
 * specification's third slice): every image may be sent, as a scaled copy,
 * where the provider can see images. Slice 3 replaces this alias in
 * Services.yaml.
 */
final readonly class SendEveryImage implements AltTextImagePolicyInterface
{
    public function refusal(FileReference $reference): ?string
    {
        return null;
    }
}
