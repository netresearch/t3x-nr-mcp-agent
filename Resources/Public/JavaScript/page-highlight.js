// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Receiver of the chat's highlight message, loaded into the page module
 * (ADR-020).
 *
 * The contract, and nothing else is accepted:
 *
 *   {type: 'nr-mcp-agent:highlight', version: 1, table: 'tt_content', uid: <positive integer>}
 *   {type: 'nr-mcp-agent:highlight', version: 1, clear: true}
 *
 * The second removes the mark, when the guided process has ended (ADR-023).
 * from the same origin, posted by the window that holds this frame — the
 * backend window with the chat panel. The element is found by the id TYPO3
 * gives every content element in the page module (`element-tt_content-<uid>`
 * in 13.4 and 14.3); no selector or markup ever comes from the message.
 */

export const HIGHLIGHT_MESSAGE = 'nr-mcp-agent:highlight';

const HIGHLIGHT_CLASS = 'nr-mcp-agent-highlight';
const LABEL_CLASS = 'nr-mcp-agent-highlight-label';
const STYLE_ID = 'nr-mcp-agent-highlight-style';

/** The badge's text when the page module has no label for it (ADR-023). */
const FALLBACK_LABEL = 'Concerns the current proposal';

/**
 * @param {MessageEvent} event
 * @param {Window} [win]
 * @returns {boolean} whether the message was one of ours and was applied
 */
export function handleHighlightMessage(event, win = globalThis) {
    if (event.origin !== win.location.origin || event.source !== win.parent || event.source === win) {
        return false;
    }

    const data = event.data;
    if (data?.type === HIGHLIGHT_MESSAGE && data.version === 1 && data.clear === true) {
        clearHighlight(win.document);
        return true;
    }
    if (data?.type !== HIGHLIGHT_MESSAGE || data.version !== 1 || data.table !== 'tt_content'
        || !Number.isInteger(data.uid) || data.uid <= 0) {
        return false;
    }

    return highlightContentElement(win.document, data.uid);
}

/**
 * The badge's text: the page module's own label in the backend user's
 * language (loaded by PageModuleHighlight), never anything from the message.
 *
 * @param {Document} doc
 */
export function highlightLabel(doc) {
    const label = doc.defaultView?.TYPO3?.lang?.['highlight.label'];

    return typeof label === 'string' && label !== '' ? label : FALLBACK_LABEL;
}

/**
 * Mark one content element with a frame and a fixed badge, and scroll it into
 * view; the mark and badge of the one before are removed. Returns false when
 * the page module does not show it.
 *
 * @param {Document} doc
 * @param {number} uid
 */
export function highlightContentElement(doc, uid) {
    clearHighlight(doc);
    const element = doc.getElementById(`element-tt_content-${uid}`);
    if (!element) {
        return false;
    }

    ensureStyle(doc);
    element.classList.add(HIGHLIGHT_CLASS);
    const badge = doc.createElement('span');
    badge.className = LABEL_CLASS;
    badge.textContent = highlightLabel(doc);
    element.prepend(badge);
    element.scrollIntoView?.({block: 'center', behavior: 'smooth'});
    return true;
}

/**
 * Remove the mark and the badge, wherever they are.
 *
 * @param {Document} doc
 */
export function clearHighlight(doc) {
    doc.querySelectorAll(`.${HIGHLIGHT_CLASS}`).forEach((el) => el.classList.remove(HIGHLIGHT_CLASS));
    doc.querySelectorAll(`.${LABEL_CLASS}`).forEach((el) => el.remove());
}

function ensureStyle(doc) {
    if (doc.getElementById(STYLE_ID)) {
        return;
    }
    const style = doc.createElement('style');
    style.id = STYLE_ID;
    // The backend's focus colour, so the mark follows the colour scheme.
    style.textContent = `.${HIGHLIGHT_CLASS} { outline: 3px solid var(--typo3-component-focus-ring-color, Highlight); outline-offset: 2px; }`
        + ` .${LABEL_CLASS} { display: inline-block; margin: 0 0 4px; padding: 1px 6px; border-radius: 3px; font-size: 11px;`
        + ` background: var(--typo3-component-focus-ring-color, Highlight); color: var(--typo3-component-primary-color-text, HighlightText); }`;
    doc.head.append(style);
}

// Only a framed page listens: the module frame beside the chat. The chat
// window imports this file for the message type and must not react itself.
const own = globalThis.window;
if (own && own.parent !== own) {
    own.addEventListener('message', (event) => handleHighlightMessage(event));
}
