// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Receiver of the chat's highlight message, loaded into the page module
 * (ADR-020).
 *
 * The contract, and nothing else is accepted:
 *
 *   {type: 'nr-mcp-agent:highlight', version: 1, table: 'tt_content', uid: <positive integer>}
 *
 * from the same origin, posted by the window that holds this frame — the
 * backend window with the chat panel. The element is found by the id TYPO3
 * gives every content element in the page module (`element-tt_content-<uid>`
 * in 13.4 and 14.3); no selector or markup ever comes from the message.
 */

export const HIGHLIGHT_MESSAGE = 'nr-mcp-agent:highlight';

const HIGHLIGHT_CLASS = 'nr-mcp-agent-highlight';
const STYLE_ID = 'nr-mcp-agent-highlight-style';

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
    if (data?.type !== HIGHLIGHT_MESSAGE || data.version !== 1 || data.table !== 'tt_content'
        || !Number.isInteger(data.uid) || data.uid <= 0) {
        return false;
    }

    return highlightContentElement(win.document, data.uid);
}

/**
 * Mark one content element and scroll it into view; the mark of the one
 * before is removed. Returns false when the page module does not show it.
 *
 * @param {Document} doc
 * @param {number} uid
 */
export function highlightContentElement(doc, uid) {
    doc.querySelectorAll(`.${HIGHLIGHT_CLASS}`).forEach((el) => el.classList.remove(HIGHLIGHT_CLASS));
    const element = doc.getElementById(`element-tt_content-${uid}`);
    if (!element) {
        return false;
    }

    ensureStyle(doc);
    element.classList.add(HIGHLIGHT_CLASS);
    element.scrollIntoView?.({block: 'center', behavior: 'smooth'});
    return true;
}

function ensureStyle(doc) {
    if (doc.getElementById(STYLE_ID)) {
        return;
    }
    const style = doc.createElement('style');
    style.id = STYLE_ID;
    // The backend's focus colour, so the mark follows the colour scheme.
    style.textContent = `.${HIGHLIGHT_CLASS} { outline: 3px solid var(--typo3-component-focus-ring-color, Highlight); outline-offset: 2px; }`;
    doc.head.append(style);
}

// Only a framed page listens: the module frame beside the chat. The chat
// window imports this file for the message type and must not react itself.
const own = globalThis.window;
if (own && own.parent !== own) {
    own.addEventListener('message', (event) => handleHighlightMessage(event));
}
