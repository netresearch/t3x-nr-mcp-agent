// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';
import {HIGHLIGHT_MESSAGE} from '@netresearch/nr-mcp-agent/page-highlight.js';

/**
 * What a guided process shows beside the conversation (ADR-020): its
 * progress in the header, and the content element it is about, highlighted in
 * the page module next to the floating panel.
 */
export const chatGuidedStyles = css`
    .guided-progress {
        font-size: 11px;
        font-weight: normal;
        color: var(--nr-chat-text-variant);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
    }
`;

/**
 * "<label> · Punkt 2 von 5", or nothing without progress. A status region, so
 * a screen reader hears when the point changes.
 */
export function renderProgress(chat) {
    const progress = chat.guided?.progress;
    if (!progress) {
        return nothing;
    }

    return html`
        <span class="guided-progress" role="status">
            ${progress.label} · ${progress.completed ? lll('guided.completed') : lll('guided.progress', progress.current, progress.total)}
        </span>
    `;
}

/**
 * Ask the page module to highlight the conversation's current element, once
 * per element. Only the floating panel has the page module beside it; the
 * message goes to the backend's module frame, same origin only.
 *
 * @param {object} chat the ChatCoreController
 * @param {Window} [win] the window the panel lives in
 * @returns {boolean} whether a message was posted
 */
export function sendHighlight(chat, win = globalThis) {
    const highlight = chat.guided?.highlight;
    const key = highlight ? `${chat.activeUid}:${highlight.table}:${highlight.uid}` : '';
    if (!highlight || key === chat._sentHighlight) {
        return false;
    }

    const frame = win.frames?.list_frame;
    if (!frame) {
        return false;
    }

    chat._sentHighlight = key;
    frame.postMessage({type: HIGHLIGHT_MESSAGE, version: 1, table: highlight.table, uid: highlight.uid}, win.location.origin);
    return true;
}
