// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';
import {format} from '@netresearch/nr-mcp-agent/text-format.js';
import {HIGHLIGHT_MESSAGE} from '@netresearch/nr-mcp-agent/page-highlight.js';
import {ICON_INFO, ICON_PAGE} from '@netresearch/nr-mcp-agent/icons.js';
import {contextOfAltText} from '@netresearch/nr-mcp-agent/chat-alt-text.js';

/**
 * The row of chips above the chat input that says what the chat is about
 * (NEXT-221): the page, in a guided process also the element its current
 * step is about (a content element or a property of the page), and an (i)
 * whose tooltip says what the assistant may change. A conversation about a missing alt text (ADR-026) shows its image
 * in the same row.
 *
 * Shared by the full-page module and the panel, like chat-alt-text.js, so
 * the two cannot drift. Only the panel passes the window it lives in: there
 * the page module is beside the chat, and hovering, focusing or clicking the
 * element chip marks the element there. In the module the chip is text, and
 * so is a page property's chip everywhere: the page module has no element
 * for it.
 */

/** The page module (Layout), the one module whose page the row names. */
export const PAGE_MODULE = 'web_layout';

const INFO_ID = 'nr-chat-context-info';
const SHOW_ID = 'nr-chat-context-show';

export const contextChipsStyles = css`
    .context-chips {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        gap: 4px;
        padding: 6px 12px 0;
        font-size: 12px;
    }
    .context-chip-list {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        flex: 1 1 0;
        min-width: 0;
        margin: 0;
        padding: 0;
        list-style: none;
    }
    .context-chip-list > li {
        display: flex;
        min-width: 0;
        max-width: 100%;
    }
    .context-chip {
        box-sizing: border-box;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        height: 22px;
        max-width: 170px;
        margin: 0;
        padding: 0 6px;
        border: 1px solid transparent;
        border-radius: 4px;
        font: inherit;
        font-size: 12px;
        line-height: 20px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .context-chip svg {
        flex: none;
    }
    .context-chip-text {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .context-chip.page,
    .context-chip.plain {
        background: var(--nr-chat-chip-page-bg);
        color: var(--nr-chat-chip-page-text);
    }
    .context-chip.element {
        background: var(--nr-chat-chip-element-bg);
        color: var(--nr-chat-chip-element-ink);
        border-color: var(--nr-chat-chip-element-ink);
    }
    button.context-chip {
        cursor: pointer;
    }
    button.context-chip:hover {
        background: color-mix(in srgb, currentColor 8%, var(--nr-chat-chip-element-bg));
    }
    .context-chip:focus-visible,
    .context-info:focus-visible {
        outline: 2px solid var(--nr-chat-focus-ring);
        outline-offset: 1px;
    }
    /* A black circle with a dot in the accent: the element the step is about. */
    .context-marker {
        flex: none;
        position: relative;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: var(--nr-chat-chip-marker);
        box-shadow: 0 0 0 1px var(--nr-chat-chip-element-ink);
    }
    .context-marker::after {
        content: '';
        position: absolute;
        inset: 3px;
        border-radius: 50%;
        background: var(--nr-chat-chip-accent);
    }
    .context-chips-note {
        flex-basis: 100%;
        margin: 0;
        color: var(--nr-chat-text-variant);
    }
    .context-info-wrap {
        position: relative;
        flex: none;
        display: inline-flex;
    }
    .context-info {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        padding: 0;
        border: 0;
        border-radius: 50%;
        background: none;
        color: var(--nr-chat-text-variant);
        cursor: help;
    }
    .context-tooltip {
        display: none;
        position: absolute;
        right: 0;
        bottom: calc(100% + 4px);
        z-index: 10;
        box-sizing: border-box;
        width: max-content;
        max-width: min(280px, 80vw);
        padding: 6px 8px;
        border: 1px solid var(--nr-chat-border);
        border-radius: 4px;
        background: var(--nr-chat-surface);
        color: var(--nr-chat-text);
        box-shadow: 0 2px 8px rgb(0 0 0 / 15%);
        font-size: 12px;
        line-height: 1.4;
        white-space: normal;
    }
    /* Bridges the gap between the (i) and the tooltip, so the pointer can move
       onto the tooltip without leaving the wrap (WCAG 1.4.13, hoverable). */
    .context-tooltip::before {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        top: 100%;
        height: 5px;
    }
    .context-info-wrap:hover .context-tooltip,
    .context-info-wrap:focus-within .context-tooltip {
        display: block;
    }
    .context-info-wrap[data-dismissed] .context-tooltip {
        display: none;
    }
    .context-chips .visually-hidden {
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
 * The page title the page module's page has, once the chat has read it
 * (ChatCoreController::loadPageTitle()), or '' while it has not.
 */
function pageModuleTitle(chat) {
    const pageId = chat.backendPage?.pageId ?? 0;

    return pageId > 0 && chat.pageTitle?.pageId === pageId ? (chat.pageTitle.title ?? '') : '';
}

/**
 * Whether the chat beside the page module is about the page it shows: a
 * chat without a skill, or with one that works on a page (ADR-025), while
 * the page module (not another module) shows a page.
 */
export function aboutPageModulePage(chat) {
    return chat.backendModule === PAGE_MODULE
        && (chat.backendPage?.pageId ?? 0) > 0
        && chat.skill?.bindsPage !== false;
}

/**
 * What the row shows, or null for no row.
 *
 * In this order, the first that applies: the image of a conversation about a
 * missing alt text; the page of a guided process (the conversation's page,
 * which stays when the page tree moves on, ADR-023) and the element of its
 * current step; the page the page module beside the chat shows.
 *
 * @param {object} chat the ChatCoreController
 * @returns {{label: string, chips: Array<{kind: string, text: string, property?: boolean, highlight?: {table: string, uid: number}}>, info: string, note: string, altText: boolean}|null}
 */
export function contextChips(chat) {
    const altText = contextOfAltText(chat);
    if (altText) {
        return {
            label: lll('altText.context.label'),
            chips: altText.chips.map((text) => ({kind: 'plain', text})),
            info: '',
            note: altText.info,
            altText: true,
        };
    }

    const tour = chat.tour;
    if (tour) {
        const page = tour.pageTitle ?? '';
        if (page === '') {
            return null;
        }
        const highlight = chat.guided?.highlight;
        const named = (highlight?.table === 'tt_content' || highlight?.table === 'pages') && typeof highlight.title === 'string';
        const element = named ? highlight.title : '';
        // A property of the page (NEXT-221): named by its label, with nothing
        // to mark in the page module.
        const property = element !== '' && highlight.table === 'pages';
        const chips = [{kind: 'page', text: page}];
        if (property) {
            chips.push({kind: 'element', text: element, property: true});
        } else if (element !== '') {
            chips.push({kind: 'element', text: element, highlight: {table: 'tt_content', uid: highlight.uid}});
        }

        let info = format(lll('context.info.page'), page);
        if (property) {
            info = format(lll('context.info.pageField'), element, page);
        } else if (element !== '') {
            info = format(lll('context.info.element'), element, page);
        }

        return {
            label: lll('context.label'),
            chips,
            info,
            note: '',
            altText: false,
        };
    }

    const title = aboutPageModulePage(chat) ? pageModuleTitle(chat) : '';
    if (title === '') {
        return null;
    }

    return {
        label: lll('context.label'),
        chips: [{kind: 'page', text: title}],
        info: format(lll('context.info.page'), title),
        note: '',
        altText: false,
    };
}

/**
 * The page module frame the element chip can mark its element in, or null:
 * only beside the panel (a window is passed), while the page module shows
 * the process's page.
 *
 * @param {object} chat the ChatCoreController
 * @param {Window|undefined} win the window the panel lives in
 */
export function highlightFrame(chat, win) {
    const frame = win?.frames?.list_frame;
    if (!frame || !chat.tour || chat.backendModule !== PAGE_MODULE || chat.backendPage?.pageId !== chat.tour.pageUid) {
        return null;
    }

    return frame;
}

/**
 * Ask the page module to mark the element: without scrolling on hover and
 * focus, scrolled into view on click. The step's own mark (sendHighlight())
 * is the same element, so nothing is cleared afterwards.
 *
 * @param {Window} win the window the panel lives in
 * @param {Window} frame the page module frame
 * @param {{table: string, uid: number}} highlight
 * @param {boolean} scroll
 */
export function postChipHighlight(win, frame, highlight, scroll) {
    frame.postMessage({type: HIGHLIGHT_MESSAGE, version: 1, table: highlight.table, uid: highlight.uid, scroll}, win.location.origin);
}

/**
 * Escape hides the tooltip until the pointer or the focus leaves (WCAG
 * 1.4.13). That Escape is the tooltip's: the panel, which collapses on
 * Escape, does not get it; the next one collapses the panel as before.
 */
function dismissTooltip(event) {
    if (event.key === 'Escape' && !event.currentTarget.hasAttribute('data-dismissed')) {
        event.currentTarget.setAttribute('data-dismissed', '');
        event.stopPropagation();
    }
}

/**
 * While the pointer shows the tooltip, Escape hides it wherever the focus
 * is: the key is listened for on the document as long as the pointer stays.
 */
const escapeWhileHovered = new WeakMap();

function watchEscape(event) {
    const wrap = event.currentTarget;
    const doc = wrap.ownerDocument;
    if (escapeWhileHovered.has(wrap) || !doc) {
        return;
    }
    const listener = (key) => {
        // The row went away under the pointer: nothing left to hide.
        if (!wrap.isConnected) {
            doc.removeEventListener('keydown', listener, true);
            escapeWhileHovered.delete(wrap);
            return;
        }
        if (key.key === 'Escape' && !wrap.hasAttribute('data-dismissed')) {
            wrap.setAttribute('data-dismissed', '');
            key.stopPropagation();
        }
    };
    doc.addEventListener('keydown', listener, true);
    escapeWhileHovered.set(wrap, () => doc.removeEventListener('keydown', listener, true));
}

function restoreTooltip(event) {
    const wrap = event.currentTarget;
    if (event.type === 'focusout' && wrap.contains(event.relatedTarget)) {
        return;
    }
    if (event.type === 'mouseleave') {
        escapeWhileHovered.get(wrap)?.();
        escapeWhileHovered.delete(wrap);
    }
    wrap.removeAttribute('data-dismissed');
}

function renderChip(chip, legacyClass, win, frame) {
    if (chip.kind === 'plain') {
        return html`<span class="context-chip plain ${legacyClass}" title="${chip.text}">${chip.text}</span>`;
    }

    if (chip.kind === 'page') {
        return html`<span class="context-chip page" title="${chip.text}">${ICON_PAGE(12)}<span class="visually-hidden">${lll('context.page')}: </span><span class="context-chip-text">${chip.text}</span></span>`;
    }

    const content = html`<span class="context-marker" aria-hidden="true"></span><span class="visually-hidden">${lll(chip.property ? 'context.pageField' : 'context.element')}: </span><span class="context-chip-text">${chip.text}</span>`;
    if (!frame || !chip.highlight) {
        return html`<span class="context-chip element" title="${chip.text}">${content}</span>`;
    }

    return html`
        <button type="button" class="context-chip element" data-action="show-element" title="${chip.text}" aria-describedby="${SHOW_ID}"
            @mouseenter=${() => postChipHighlight(win, frame, chip.highlight, false)}
            @focus=${() => postChipHighlight(win, frame, chip.highlight, false)}
            @click=${() => postChipHighlight(win, frame, chip.highlight, true)}>${content}</button>
        <span id="${SHOW_ID}" class="visually-hidden">${lll('context.showElement')}</span>
    `;
}

/**
 * The row above the input, or nothing.
 *
 * @param {object} chat the ChatCoreController
 * @param {{win?: Window}} [options] the panel's window, where the page module is beside it
 */
export function renderContextChips(chat, {win} = {}) {
    const context = contextChips(chat);
    if (!context) {
        return nothing;
    }

    const frame = highlightFrame(chat, win);
    const legacyChip = context.altText ? 'alt-text-chip' : '';

    return html`
        <div class="context-chips ${context.altText ? 'alt-text-context' : ''}" role="group" aria-label="${context.label}">
            <ul class="context-chip-list">
                ${context.chips.map((chip) => html`<li>${renderChip(chip, legacyChip, win, frame)}</li>`)}
            </ul>
            ${context.info ? html`
                <span class="context-info-wrap" @keydown=${dismissTooltip} @mouseenter=${watchEscape} @mouseleave=${restoreTooltip} @focusout=${restoreTooltip}>
                    <button type="button" class="context-info" aria-label="${lll('context.info')}" aria-describedby="${INFO_ID}">${ICON_INFO(14)}</button>
                    <span id="${INFO_ID}" class="context-tooltip" role="tooltip">${context.info}</span>
                </span>
            ` : nothing}
            ${context.note ? html`<p class="context-chips-note ${context.altText ? 'alt-text-context-info' : ''}">${context.note}</p>` : nothing}
        </div>
    `;
}
