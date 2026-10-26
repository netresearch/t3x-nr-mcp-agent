<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use TYPO3\CMS\Core\Resource\FileReference;

/**
 * Whether a scaled copy of an image may be sent to the AI provider for an
 * alt text proposal (ADR-026). The one place where the privacy setting
 * "Bildanalyse" decides: "Nur Kontext verwenden", and folders whose images
 * are never sent (ImageAnalysisPolicy).
 */
interface AltTextImagePolicyInterface
{
    /**
     * Null when the image may be sent; else why not, as a code the chat
     * renders in the reader's language under the image card, with the
     * folder it names.
     */
    public function refusal(FileReference $reference): ?AltTextImageRefusal;
}
