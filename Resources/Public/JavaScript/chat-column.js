// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import Persistent from '@typo3/backend/storage/persistent.js';

/**
 * The chat as a column beside the page tree (ADR-024).
 *
 * TYPO3 offers no API for a column in the backend frame. The column is placed
 * as a sibling BEFORE the module router inside the content area, which gives
 * "page tree | chat | module" in modules with a tree and makes the chat the
 * leftmost column in modules without one. The content area outlives module
 * switches, so the column does too.
 *
 * The content area is found by its shape, never by the TYPO3 version:
 *  - 14.3: the `content` slot of `typo3-backend-content-navigation`
 *    (`ScaffoldContentArea` in `@typo3/backend/enum/viewport/scaffold-identifier.js`),
 *  - 13.4: `.t3js-scaffold-content-module`.
 * Both are horizontal flex rows around the router. A candidate counts only if
 * the router is its direct child: the workspace-denied and no-module-access
 * pages render an infobox there instead, and then the chat stays floating.
 */

export const LAYOUT = Object.freeze({FLOATING: 'floating', COLUMN: 'column'});

export const COLUMN_MIN_WIDTH = 320;
export const COLUMN_DEFAULT_WIDTH = 380;
/** One arrow key press on the splitter, in px. */
export const COLUMN_KEY_STEP = 16;

/** Keys in the backend user's uc, namespaced like core's `resize.backend.navigation`. */
export const PREFERENCE_LAYOUT = 'nrMcpAgent.chatPanel.layout';
export const PREFERENCE_COLUMN_WIDTH = 'nrMcpAgent.chatPanel.columnWidth';

const MOUNT_SELECTORS = [
    'typo3-backend-content-navigation[identifier="backend"] > [slot="content"]',
    '.t3js-scaffold-content-module',
];

/** Set on the content area while the column is open there. */
export const MOUNT_ATTRIBUTE = 'data-nr-chat-column';

/**
 * Where the column goes, or null when the backend has no such place.
 *
 * @param {Document} doc the backend's top document
 * @returns {{element: HTMLElement, router: HTMLElement}|null}
 */
export function findColumnMount(doc) {
    for (const selector of MOUNT_SELECTORS) {
        const element = doc?.querySelector?.(selector);
        if (!element) {
            continue;
        }
        const router = [...element.children].find((child) => child.localName === 'typo3-backend-module-router');
        if (router) {
            return {element, router};
        }
    }

    return null;
}

/**
 * The widest the column may be: half the content area, like core's page tree.
 * Where half is less than the minimum, the minimum is also the maximum: the
 * column keeps a usable width and the module gets the rest.
 *
 * @param {number} areaWidth width of the content area in px
 */
export function columnMaxWidth(areaWidth) {
    return Math.max(COLUMN_MIN_WIDTH, Math.floor((Number(areaWidth) || 0) / 2));
}

/**
 * @param {number} width the width asked for
 * @param {number} areaWidth width of the content area in px
 */
export function clampColumnWidth(width, areaWidth) {
    const value = Number.isFinite(width) ? Math.round(width) : COLUMN_DEFAULT_WIDTH;

    return Math.min(columnMaxWidth(areaWidth), Math.max(COLUMN_MIN_WIDTH, value));
}

/**
 * The width after a key press on the splitter (WAI-ARIA Window Splitter):
 * the arrows step, Home and End jump to the limits. Right widens the column,
 * which sits left of the splitter; in a right-to-left backend the column is on
 * the right, so the arrows swap.
 *
 * @param {string} key KeyboardEvent.key
 * @param {number} width current width
 * @param {number} areaWidth width of the content area in px
 * @param {boolean} [rtl]
 * @returns {number|null} the new width, or null when the key is not the splitter's
 */
export function splitterKeyWidth(key, width, areaWidth, rtl = false) {
    const wider = rtl ? 'ArrowLeft' : 'ArrowRight';
    const narrower = rtl ? 'ArrowRight' : 'ArrowLeft';
    switch (key) {
        case wider:
            return clampColumnWidth(width + COLUMN_KEY_STEP, areaWidth);
        case narrower:
            return clampColumnWidth(width - COLUMN_KEY_STEP, areaWidth);
        case 'Home':
            return COLUMN_MIN_WIDTH;
        case 'End':
            return columnMaxWidth(areaWidth);
        default:
            return null;
    }
}

/**
 * Read a preference from the backend user's uc. A failed read (13.4 loads the
 * uc with a synchronous request on first use) leaves the default.
 */
function readPreference(key, storage) {
    try {
        return storage?.get?.(key);
    } catch {
        return undefined;
    }
}

/** Write a preference; a failed request must not reach the chat. */
function writePreference(key, value, storage) {
    try {
        Promise.resolve(storage?.set?.(key, value)).catch(() => {});
    } catch {
        // Nothing to do: the setting is lost for the next page load only.
    }
}

/** @returns {'floating'|'column'} */
export function readLayoutPreference(storage = Persistent) {
    return readPreference(PREFERENCE_LAYOUT, storage) === LAYOUT.COLUMN ? LAYOUT.COLUMN : LAYOUT.FLOATING;
}

/** @param {'floating'|'column'} layout */
export function saveLayoutPreference(layout, storage = Persistent) {
    writePreference(PREFERENCE_LAYOUT, layout === LAYOUT.COLUMN ? LAYOUT.COLUMN : LAYOUT.FLOATING, storage);
}

/** The width the user chose, unclamped: the area it is clamped to changes. */
export function readColumnWidth(storage = Persistent) {
    const value = Number.parseInt(String(readPreference(PREFERENCE_COLUMN_WIDTH, storage) ?? ''), 10);

    return Number.isFinite(value) && value >= COLUMN_MIN_WIDTH ? value : COLUMN_DEFAULT_WIDTH;
}

/** @param {number} width */
export function saveColumnWidth(width, storage = Persistent) {
    writePreference(PREFERENCE_COLUMN_WIDTH, String(Math.round(width)), storage);
}

/**
 * The router keeps `:host { width: 100%; flex: 1 0 auto }`, so beside the
 * column it would overflow the content area. A rule of the outer document
 * wins over `:host`; it applies only while the column is open there.
 */
const MOUNT_RULES = `[${MOUNT_ATTRIBUTE}="open"] > typo3-backend-module-router { flex: 1 1 0; min-width: 0; width: auto; }`;

const mountSheets = new WeakMap();

/**
 * Mark the content area as holding an open column, or unmark it.
 *
 * The rule is added once per document as a constructed stylesheet, so no
 * style element is written into the backend's markup.
 *
 * @param {HTMLElement} element the content area
 * @param {boolean} open
 */
export function markColumnMount(element, open) {
    const doc = element?.ownerDocument;
    if (!doc) {
        return;
    }
    if (open) {
        adoptMountRules(doc);
        element.setAttribute(MOUNT_ATTRIBUTE, 'open');
    } else {
        element.removeAttribute(MOUNT_ATTRIBUTE);
    }
}

function adoptMountRules(doc) {
    if (mountSheets.has(doc)) {
        return;
    }
    const SheetClass = doc.defaultView?.CSSStyleSheet;
    if (typeof SheetClass !== 'function' || !Array.isArray(doc.adoptedStyleSheets)) {
        return;
    }
    try {
        const sheet = new SheetClass();
        sheet.replaceSync(MOUNT_RULES);
        doc.adoptedStyleSheets = [...doc.adoptedStyleSheets, sheet];
        mountSheets.set(doc, sheet);
    } catch {
        // Without the rule the module only overflows; the chat still works.
    }
}

/**
 * Put a new panel where the user left it: into the content area when they
 * chose the column and the backend has one, into the body otherwise. Decided
 * once, before the panel connects: every later move re-initialises it.
 *
 * @param {HTMLElement & {layout: string}} panel
 * @param {Document} doc
 */
export function attachPanel(panel, doc, storage = Persistent) {
    const mount = readLayoutPreference(storage) === LAYOUT.COLUMN ? findColumnMount(doc) : null;
    if (mount) {
        panel.layout = LAYOUT.COLUMN;
        mount.element.insertBefore(panel, mount.router);
        return;
    }
    panel.layout = LAYOUT.FLOATING;
    doc.body.append(panel);
}
