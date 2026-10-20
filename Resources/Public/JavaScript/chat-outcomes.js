// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';

/**
 * What became of the proposals of a guided process (ADR-023), as the chat
 * records it from the run results — never from the model's text.
 *
 * - After each approved proposal the chat's own status line, placed before
 *   the model's answer: saved, saved but to be checked, or not saved.
 * - When the process reports that it is done, a summary of every proposal
 *   in the groups Übernommen, Übernommen – bitte prüfen, Übersprungen and,
 *   when there is one, Nicht gespeichert.
 *
 * `chat.cardOutcomes` holds the server's list: each entry's `after` is the
 * number of transcript messages there were when the outcome was recorded,
 * `subject` the fields (or the change's name) and `record` the record.
 */

export const chatOutcomesStyles = css`
    .proposal-outcome { margin: 4px 0; font-weight: 600; }
    .proposal-outcome[data-outcome="check"] { color: var(--nr-chat-status-warning, #8a5300); }
    .proposal-outcome[data-outcome="not_applied"] { color: var(--nr-chat-status-danger, #c62828); }
    .proposal-summary { margin: 8px 0; padding: 8px 12px; border-left: 3px solid var(--typo3-component-primary-color, #0078e6); }
    .proposal-summary h3 { font-size: inherit; margin: 6px 0 2px; }
    .proposal-summary ul { margin: 0; padding-left: 1.2em; }
`;

const STATUS_LINES = ['applied', 'check', 'not_applied'];

/**
 * @param {{outcome: string, subject: string}} entry
 */
export function outcomeLine(entry) {
    const subject = entry.subject || lll('chat.outcomeChange');
    switch (entry.outcome) {
        case 'applied':
            return `${subject} ${lll('chat.outcomeApplied')}`;
        case 'check':
            return `${lll('chat.outcomeCheck')} ${subject}`;
        case 'not_applied':
            return `${lll('chat.outcomeNotApplied')} ${subject}`;
        default:
            return '';
    }
}

/**
 * The status lines that belong before message `index`, or after the last
 * message when `index` is the transcript's length.
 *
 * @param {object} chat the ChatCoreController
 * @param {number} index
 */
export function renderOutcomeLines(chat, index) {
    const last = index >= (chat.messages?.length ?? 0);
    const lines = (chat.cardOutcomes ?? []).filter((entry) => STATUS_LINES.includes(entry.outcome)
        && (last ? entry.after >= index : entry.after === index));

    return lines.map((entry) => html`
        <p class="message system proposal-outcome" data-outcome=${entry.outcome}>${outcomeLine(entry)}</p>
    `);
}

const GROUPS = [
    ['applied', 'chat.summaryApplied'],
    ['check', 'chat.summaryCheck'],
    ['skipped', 'chat.summarySkipped'],
    ['not_applied', 'chat.summaryNotApplied'],
];

/**
 * The end-of-process summary, from the chat's own record; nothing until the
 * process reports that it is done, or when no proposal was decided.
 *
 * @param {object} chat the ChatCoreController
 */
export function renderSummary(chat) {
    const outcomes = chat.cardOutcomes ?? [];
    if (!chat.guided?.progress?.completed || outcomes.length === 0) {
        return nothing;
    }

    const groups = GROUPS
        .map(([outcome, label]) => [label, outcomes.filter((entry) => entry.outcome === outcome)])
        .filter(([, entries]) => entries.length > 0);
    if (groups.length === 0) {
        return nothing;
    }

    return html`
        <section class="proposal-summary" aria-label=${lll('chat.summaryLabel')}>
            ${groups.map(([label, entries]) => html`
                <h3>${lll(label)}</h3>
                <ul>${entries.map((entry) => html`
                    <li>${entry.subject || lll('chat.outcomeChange')}${entry.record ? ` (${entry.record})` : ''}</li>
                `)}</ul>
            `)}
        </section>
    `;
}
