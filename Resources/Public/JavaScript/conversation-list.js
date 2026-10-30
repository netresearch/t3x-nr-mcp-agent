// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * What every list of conversations shows about one (ADR-027): the title, a
 * line with the context — page, skill, the point a guided tour is at — and
 * when the last message was written, and the status in words.
 *
 * The chat module's list, the panel's sidebar and its "more" list render a
 * row with renderConversationSummary(); the panel's tabs, too narrow for
 * more than a title, show the status icon with its label for screen readers
 * and carry the rest in their tooltip (conversationTooltip() in
 * conversation-tabs.js). The server prepares the texts
 * (ConversationListPresenter); the status comes from the conversation's
 * current status, which polling updates between two loads of the list.
 */

import {html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';
import {statusLabel, statusTone} from '@netresearch/nr-mcp-agent/chat-core.js';
import {conversationTitle} from '@netresearch/nr-mcp-agent/conversation-tabs.js';
import {describeTime} from '@netresearch/nr-mcp-agent/relative-time.js';

/**
 * One icon per tone, in the colour of the tone. Ready gets a hollow circle,
 * not a check mark: a ready conversation has not succeeded at anything.
 */
const TONE_ICONS = {
    neutral: html`<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="7"/></svg>`,
    info: html`<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M21 12a9 9 0 1 1-6.2-8.6"/></svg>`,
    warning: html`<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><line x1="12" y1="7" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    danger: html`<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true" focusable="false"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`,
};

export const conversationListStyles = css`
    .conv-summary {
        display: flex;
        flex-direction: column;
        gap: 2px;
        flex: 1;
        min-width: 0;
    }
    .conv-summary-title,
    .conv-summary-context {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .conv-summary-title {
        font-size: 13px;
    }
    /*
     * Where a start time tells two alike titles apart, the title is cut
     * short before it, never the time itself (ADR-027).
     */
    .conv-summary-title--split {
        display: flex;
        min-width: 0;
    }
    .conv-summary-title-base {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 1.5em;
    }
    .conv-summary-title-suffix {
        flex: 0 0 auto;
        white-space: pre;
    }
    .conv-summary-context {
        font-size: 11px;
        color: var(--nr-chat-text-variant);
    }
    .conv-summary-meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px 8px;
        font-size: 11px;
        color: var(--nr-chat-text-variant);
    }
    /* In the highlighted row the variant grey would sit on the active colour. */
    .active .conv-summary-context,
    .active .conv-summary-meta {
        color: inherit;
    }
    .conv-status {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 1px 6px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.4;
        white-space: nowrap;
    }
    .conv-status svg {
        flex: 0 0 auto;
    }
    /*
     * Every tone sets a background and a text colour that do not depend on
     * the row: the same badge sits in the selected row's active colour and in
     * the plain rows.
     */
    .conv-status--neutral {
        background: var(--nr-chat-neutral-bg);
        color: var(--nr-chat-neutral-text);
        font-weight: 400;
    }
    .conv-status--info {
        background: var(--nr-chat-info-bg);
        color: var(--nr-chat-info-text);
    }
    .conv-status--warning {
        background: var(--nr-chat-warning-bg);
        color: var(--nr-chat-warning-text);
        box-shadow: inset 0 0 0 1px var(--nr-chat-warning-edge);
    }
    .conv-status--danger {
        background: var(--nr-chat-danger-bg);
        color: var(--nr-chat-danger-text);
    }
    /* The icon alone, where the row has room for the title only. */
    .conv-status-icon {
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
    }
    .conv-status-icon--neutral { color: var(--nr-chat-neutral-text); }
    .conv-status-icon--info { color: var(--nr-chat-status-info); }
    .conv-status-icon--warning { color: var(--nr-chat-status-warning); }
    .conv-status-icon--danger { color: var(--nr-chat-status-danger); }
    .active .conv-status-icon { color: inherit; }
    .conv-list-visually-hidden {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
`;

/**
 * The status as a badge: icon and label, the colour of its tone.
 *
 * @param {string} status
 */
export function renderStatus(status) {
    const tone = statusTone(status);
    return html`<span class="conv-status conv-status--${tone}" data-tone="${tone}">${TONE_ICONS[tone]}${statusLabel(status)}</span>`;
}

/**
 * The status as an icon, its label for screen readers only.
 *
 * @param {string} status
 */
export function renderStatusIcon(status) {
    const tone = statusTone(status);
    return html`<span class="conv-status-icon conv-status-icon--${tone}" data-tone="${tone}">${TONE_ICONS[tone]}<span class="conv-list-visually-hidden">${statusLabel(status)}</span></span>`;
}

/**
 * When the last message was written, worded now, in the reader's time zone
 * (relative-time.js), or null for a conversation without the time.
 *
 * @param {{lastActivityAt?: string}} conversation
 * @param {string} [locale] the backend user's locale, as the list endpoint sends it
 */
export function conversationTime(conversation, locale) {
    return describeTime(conversation.lastActivityAt, {
        locale,
        today: (time) => lll('widget.time.today', time),
        yesterday: (time) => lll('widget.time.yesterday', time),
    });
}

/**
 * When the last message was written: the list's label, the full date and
 * time as the tooltip, the machine-readable time in `datetime`.
 *
 * @param {{lastActivityAt?: string}} conversation
 * @param {string} [locale]
 */
export function renderTime(conversation, locale) {
    const time = conversationTime(conversation, locale);
    if (!time) {
        return nothing;
    }
    return html`<time class="conv-time" datetime="${time.datetime}" title="${time.absolute}">${time.label}</time>`;
}

/** Between the title and what tells it apart (ConversationTitle::SEPARATOR). */
const TITLE_SEPARATOR = ' \u00B7 ';

/**
 * The title and its disambiguating suffix (the start time the server adds to
 * two alike titles), or the title alone. A title renamed since has no suffix
 * at its end any more and stays whole.
 *
 * @param {string} title
 * @param {string} [suffix]
 * @returns {[string, string]}
 */
export function splitTitle(title, suffix) {
    const end = TITLE_SEPARATOR + (suffix ?? '');
    if (!suffix || !title.endsWith(end) || title.length === end.length) {
        return [title, ''];
    }
    return [title.slice(0, -end.length), suffix];
}

/**
 * Title, context line, status and time of one conversation.
 *
 * @param {object} conversation an entry of the list endpoint
 * @param {{status?: string, locale?: string}} [options] `status` overrides the listed one
 */
export function renderConversationSummary(conversation, options = {}) {
    const title = conversationTitle(conversation, lll('conversations.newConversation'));
    const status = options.status ?? conversation.status;
    const pin = conversation.pinned ? '\u{1F4CC} ' : '';
    const [base, suffix] = splitTitle(title, conversation.titleSuffix);
    return html`
        <span class="conv-summary">
            <span class="conv-summary-title ${suffix ? 'conv-summary-title--split' : ''}" title="${conversation.fullTitle || title}">${suffix
                ? html`<span class="conv-summary-title-base">${pin}${base}</span><span class="conv-summary-title-suffix">${TITLE_SEPARATOR}${suffix}</span>`
                : html`${pin}${title}`}</span>
            ${conversation.contextLabel ? html`<span class="conv-summary-context">${conversation.contextLabel}</span>` : nothing}
            <span class="conv-summary-meta">${status ? renderStatus(status) : nothing}${renderTime(conversation, options.locale)}</span>
        </span>
    `;
}
