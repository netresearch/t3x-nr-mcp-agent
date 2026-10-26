<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;

/**
 * Whether a file may be sent to the AI provider as it is (ADR-026): the one
 * place where the privacy setting "Bildanalyse" decides, for the scaled copy
 * of an image for an alt text proposal (AltTextImage) and for a file attached
 * in the chat (ChatService, its upload formats included). "Nur Kontext
 * verwenden" sends no image, and no file from a folder whose images are
 * never sent goes as it is (ImageAnalysisPolicy).
 */
interface AltTextImagePolicyInterface
{
    /**
     * Null when the image may be sent; else why not, as a code the chat
     * renders in the reader's language under the image card, with the
     * folder it names.
     */
    public function refusal(FileReference $reference): ?AltTextImageRefusal;

    /**
     * As refusal(), for a file attached in the chat. A refused image is not
     * sent; a refused document is sent as its extracted text only, never as
     * the provider's native document, which carries its images along.
     */
    public function refusalForFile(File $file): ?AltTextImageRefusal;

    /** Whether images go to the provider at all ("Bilder analysieren"). */
    public function analysesImages(): bool;
}
