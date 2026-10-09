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
        display: inline-flex;
        max-width: 100%;
        font-size: 11px;
        font-weight: normal;
        color: var(--nr-chat-text-variant);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
    }
    /* Short of space, the page name gives way first; the count never does. */
    .guided-page {
        flex: 0 1 auto;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .guided-language,
    .guided-count {
        flex: none;
    }
    .guided-end {
        flex: none;
        padding: 0 4px;
        border: 0;
        background: none;
        color: inherit;
        font-size: 14px;
        line-height: 1;
        cursor: pointer;
    }
    .guided-end:disabled {
        cursor: default;
        opacity: .5;
    }
    .guided-announcement {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
    }
`;

/**
 * Says to a screen reader which element the page module beside the panel now
 * marks (ADR-023): a fixed sentence and the element's uid, set when the
 * highlight was sent. The region is always present, so the change is
 * announced.
 */
export function renderHighlightAnnouncement(chat) {
    return html`<span class="guided-announcement" role="status">${chat.highlightAnnounced
        ? `${lll('guided.highlightAnnounced')} ${chat.highlightAnnounced}`
        : ''}</span>`;
}

/**
 * Where a guided process stands, as a status region, so a screen reader hears
 * when the point changes (ADR-020, ADR-023).
 *
 * In a conversation about a page with a skill: "<Seite> · <Sprache> ·
 * Punkt 2 von 5" — page and language from the conversation (`chat.tour`), the
 * language by its name; before the first progress report "Analyse läuft".
 * Otherwise the process's own label, as before.
 */
export function renderProgress(chat) {
    const progress = chat.guided?.progress;
    const tour = chat.tour;
    if (!progress && !tour) {
        return nothing;
    }

    const state = !progress
        ? lll('guided.analysing')
        : (progress.completed ? lll('guided.completed') : lll('guided.progress', progress.current, progress.total));

    if (!tour) {
        return html`<span class="guided-progress" role="status">${progress.label} · ${state}</span>`;
    }

    return html`
        <span class="guided-progress" role="status"><span class="guided-page">${tour.pageTitle}</span>${tour.languageName
            ? html`<span class="guided-language">&nbsp;· ${tour.languageName}</span>`
            : nothing}<span class="guided-count">&nbsp;· ${state}</span></span>
    `;
}

/**
 * The × that ends the guided process (ADR-023): beside the header's progress,
 * while a conversation runs a skill about a page. Ending it withdraws a
 * proposal still waiting; what was applied stays.
 */
export function renderEndTour(chat) {
    if (!chat.tour) {
        return nothing;
    }

    return html`<button type="button" class="guided-end" data-action="end-tour"
        ?disabled=${chat.endingTour || chat.isProcessing()}
        @click=${() => chat.endTour()}
        aria-label="${lll('guided.endTour')}" title="${lll('guided.endTour')}">&times;</button>`;
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
    chat.highlightAnnounced = String(highlight.uid);
    frame.postMessage({type: HIGHLIGHT_MESSAGE, version: 1, table: highlight.table, uid: highlight.uid}, win.location.origin);
    return true;
}

/**
 * Ask the page module to remove the mark, when the guided process has ended.
 * Only what this chat marked is cleared.
 *
 * @param {object} chat the ChatCoreController
 * @param {Window} [win] the window the panel lives in
 * @returns {boolean} whether a message was posted
 */
export function clearSentHighlight(chat, win = globalThis) {
    const had = chat._sentHighlight !== '';
    chat._sentHighlight = '';
    chat.highlightAnnounced = '';
    const frame = win.frames?.list_frame;
    if (!had || !frame) {
        return false;
    }

    frame.postMessage({type: HIGHLIGHT_MESSAGE, version: 1, clear: true}, win.location.origin);
    return true;
}
