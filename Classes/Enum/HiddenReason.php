<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

/**
 * Why an image the "missing alt texts" check found is not shown in the
 * frontend right now (ADR-026): the hint "Aktuell nicht sichtbar · <reason>".
 * The image still needs an alt text once its content is visible again, so
 * the hit counts like any other.
 *
 * Where several apply, the one higher up wins: a parent page's inheritance
 * before the page, the page before the element or record, being hidden
 * before a start or end time, the content before the image's own reference.
 */
enum HiddenReason: string
{
    /** The page is hidden. */
    case Page = 'page';

    /** A page above it is hidden and passes that on to its subpages (`extendToSubpages`). */
    case ParentPage = 'parentPage';

    /** The content element is hidden. */
    case Element = 'element';

    /** The record is hidden. */
    case Record = 'record';

    /** The start time lies in the future; the hit carries it. */
    case Scheduled = 'scheduled';

    /** The end time has passed; the hit carries it. */
    case Expired = 'expired';

    /** The image reference itself is hidden. */
    case Image = 'image';
}
