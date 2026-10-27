<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

/**
 * Why an image is not sent to the AI provider (ADR-026): the note code the
 * card renders in the reader's language, and the folder it names, where the
 * reason is an excluded folder.
 */
final readonly class AltTextImageRefusal
{
    public function __construct(
        public string $note,
        public string $folder = '',
    ) {}
}
