<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrMcpAgent\Enum\HiddenReason;

/**
 * One open hit of the "missing alt texts" check (ADR-026): one use of an
 * image, i.e. one sys_file_reference, without an alt text.
 *
 * `type` is the tab it is listed under: MissingAltTextScan::PAGES for images
 * on pages (in content elements, their inline children and the page
 * properties), else the table of the record it belongs to. `table`,
 * `recordUid` and `field` name the record and field that hold the reference
 * itself, which for a carousel item or a content element inside a news
 * record is not the record the hit is grouped under.
 *
 * Titles, headers and file names are editor input: render them escaped.
 */
final readonly class MissingAltText
{
    public function __construct(
        public int $referenceUid,
        public int $fileUid,
        public string $fileName,
        public string $crop,
        public string $type,
        public string $table,
        public int $recordUid,
        public string $field,
        public int $languageUid,
        /** Pages: the page (default language). Records: the folder the record lies in. */
        public int $pageUid,
        public string $pageTitle,
        /** Pages: the page. Records: the record the hit is grouped under. */
        public int $groupUid,
        public string $groupTitle,
        /** The content element the image belongs to, 0 for a page property or a record. */
        public int $elementUid = 0,
        public string $elementType = '',
        public string $elementHeader = '',
        public ?HiddenReason $hiddenReason = null,
        /** The start time for Scheduled, the end time for Expired, else 0. */
        public int $hiddenDate = 0,
    ) {}
}
