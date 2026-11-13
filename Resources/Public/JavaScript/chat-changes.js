// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';

/**
 * "Änderungen dieses Auftrags" (ADR-034): what the runs of the conversation
 * changed, as nr-llm observed it (nr-llm ADR-292), for the reader.
 *
 * A disclosure the reader opens; the list is fetched then, and again when the
 * conversation changes while it is open. One row per record. Every fact the
 * server states is its own badge — action, workspace, language, hidden,
 * restrictions, target or dependent, failure — and never one combined status:
 * a record that is not hidden can still be a draft, and nothing here calls it
 * public. The list never claims to be complete: a note says what it covers,
 * and says more where the server reports a capture that failed, entries left
 * out, records the reader may not read, or runs from before the capture
 * existed.
 *
 * Titles, table labels, language names and links come from the server; they
 * are bound as text and as `href`s of backend URLs, never as markup.
 *
 * Also here: the facts the approval card adds to a call (ADR-034): a refused
 * call is marked, and a configuration that requires a draft workspace says so
 * to a reader who works live.
 */

export const chatChangesStyles = css`
    .changes-view { margin: 8px 0; border: 1px solid var(--nr-chat-border, #c4c4c4); border-radius: 4px; background: var(--nr-chat-surface-low, transparent); }
    .changes-view > summary { cursor: pointer; padding: 8px 12px; font-weight: 600; color: var(--nr-chat-text, inherit); }
    .changes-view > summary:focus-visible { outline: 2px solid var(--nr-chat-focus-ring, #0078e6); outline-offset: 2px; }
    .changes-body { padding: 0 12px 10px; }
    .changes-list { list-style: none; margin: 0; padding: 0; }
    .change { padding: 8px 0; border-top: 1px solid var(--nr-chat-border, #c4c4c4); }
    .change:first-child { border-top: 0; }
    .change-record { margin: 0 0 4px; overflow-wrap: anywhere; color: var(--nr-chat-text, inherit); }
    .change-record a { color: var(--nr-chat-link, #0078d4); }
    .change-table { color: var(--nr-chat-text-variant, inherit); }
    .change-badges { list-style: none; display: flex; flex-wrap: wrap; gap: 4px; margin: 0 0 4px; padding: 0; }
    .change-badge {
        padding: 1px 8px;
        border: 1px solid var(--nr-chat-border, #c4c4c4);
        border-radius: 10px;
        font-size: 0.85em;
        color: var(--nr-chat-neutral-text, var(--nr-chat-text, inherit));
        background: var(--nr-chat-neutral-bg, transparent);
    }
    .change-badge[data-tone="warning"] { color: var(--nr-chat-warning-text, #8a5300); background: var(--nr-chat-warning-bg, transparent); border-color: var(--nr-chat-warning-border, currentColor); }
    .change-badge[data-tone="danger"] { color: var(--nr-chat-danger-text, #c62828); background: var(--nr-chat-danger-bg, transparent); border-color: currentColor; }
    .change-badge[data-tone="info"] { color: var(--nr-chat-info-text, inherit); background: var(--nr-chat-info-bg, transparent); }
    .change-links { margin: 0; display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 0.9em; }
    .change-links a { color: var(--nr-chat-link, #0078d4); }
    .changes-note { margin: 8px 0 0; font-size: 0.9em; color: var(--nr-chat-text-variant, inherit); }
    .changes-note[data-tone="warning"] { color: var(--nr-chat-warning-text, #8a5300); }
    .call-refused { margin: 0 0 4px; }
    .approval-note { margin: 8px 0 0; font-size: 0.9em; color: var(--nr-chat-info-text, inherit); }
`;

/**
 * Open a backend URL in the backend's content frame when there is one, so the
 * chat's own frame stays where it is; a modified click, or no content frame,
 * follows the link as it is.
 *
 * @param {MouseEvent} event
 * @param {string} url
 */
export function openInContentFrame(event, url) {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return;
    }

    let container = null;
    try {
        container = globalThis.top?.TYPO3?.Backend?.ContentContainer ?? globalThis.TYPO3?.Backend?.ContentContainer ?? null;
    } catch {
        container = null;
    }

    if (typeof container?.setUrl === 'function') {
        event.preventDefault();
        container.setUrl(url);
    }
}

const ACTIONS = ['created', 'updated', 'moved', 'deleted', 'restored'];

const RESTRICTION_LABELS = {
    starttime: 'chat.changeRestrictionStarttime',
    endtime: 'chat.changeRestrictionEndtime',
    fe_group: 'chat.changeRestrictionFeGroup',
};

/**
 * The facts of one change as separate badges, each with the tone that tells
 * how much attention it deserves.
 *
 * @param {object} change one entry of the server's list
 * @returns {Array<{key: string, text: string, tone: string}>}
 */
export function changeBadges(change) {
    const badges = [];
    const add = (key, text, tone = 'neutral') => badges.push({key, text, tone});

    add('action', ACTIONS.includes(change.action) ? lll(`chat.changeAction.${change.action}`) : lll('chat.changeActionUnknown'));
    if (change.legacy) {
        // Only the tool's own record is known for a run from before the capture.
        add('legacy', lll('chat.changeLegacy'), 'info');
    } else if (change.draft) {
        add('workspace', change.workspace?.title
            ? lll('chat.changeWorkspaceDraft', change.workspace.title)
            : lll('chat.changeWorkspaceDraftUntitled'), 'info');
    } else {
        add('workspace', lll('chat.changeWorkspaceLive'), 'warning');
    }

    if (change.language) {
        add('language', lll('chat.changeLanguage', change.language.label));
    }
    if (change.hidden === true) {
        add('hidden', lll('chat.changeHidden'));
    } else if (change.hidden === false) {
        add('hidden', lll('chat.changeNotHidden'));
    }
    for (const restriction of change.restrictions ?? []) {
        if (RESTRICTION_LABELS[restriction]) {
            add(`restriction-${restriction}`, lll(RESTRICTION_LABELS[restriction]));
        }
    }

    add('role', change.writeTarget ? lll('chat.changeTarget') : lll('chat.changeDependent'));
    if (change.complete === false) {
        add('partial', lll('chat.changePartial'), 'warning');
    }
    if (change.failedAfterWrite) {
        add('failed', lll('chat.changeFailed'), 'danger');
    }

    return badges;
}

function renderChange(change) {
    const name = change.title || lll('chat.changeUntitled');
    const label = html`<span class="change-table">${change.tableLabel}</span> ${change.editUrl
        ? html`<a href=${change.editUrl} @click=${(e) => openInContentFrame(e, change.editUrl)}>${name}</a>`
        : html`<strong>${name}</strong>`} <span class="change-uid">(${change.uid})</span>`;

    return html`
        <li class="change" data-table=${change.table} data-uid=${change.uid}>
            <p class="change-record">${label}</p>
            <ul class="change-badges" aria-label=${lll('chat.changeFacts')}>
                ${changeBadges(change).map((badge) => html`<li class="change-badge" data-badge=${badge.key} data-tone=${badge.tone}>${badge.text}</li>`)}
            </ul>
            ${change.historyUrl || change.workspaceUrl ? html`
                <p class="change-links">
                    ${change.historyUrl ? html`<a href=${change.historyUrl} aria-label=${`${lll('chat.changeHistory')}: ${name}`} @click=${(e) => openInContentFrame(e, change.historyUrl)}>${lll('chat.changeHistory')}</a>` : nothing}
                    ${change.workspaceUrl ? html`<a href=${change.workspaceUrl} @click=${(e) => openInContentFrame(e, change.workspaceUrl)}>${lll('chat.changeWorkspaceModule')}</a>` : nothing}
                </p>
            ` : nothing}
        </li>
    `;
}

/**
 * The notes under the list: always what the list covers, and what the server
 * reports it does not.
 *
 * @param {object} data the server's list
 */
function renderNotes(data) {
    const notes = [];
    if (data.notCaptured) {
        notes.push(['warning', lll('chat.changesNotCaptured')]);
    }
    if (data.omitted > 0) {
        notes.push(['warning', lll('chat.changesOmitted', data.omitted)]);
    }
    if (data.withheld > 0) {
        notes.push(['neutral', lll('chat.changesWithheld', data.withheld)]);
    }
    if (data.legacy) {
        notes.push(['neutral', lll('chat.changesLegacy')]);
    }
    if (data.unreadableRuns > 0) {
        notes.push(['warning', lll('chat.changesUnreadable', data.unreadableRuns)]);
    }
    notes.push(['neutral', lll('chat.changesCoverage')]);

    return notes.map(([tone, text]) => html`<p class="changes-note" data-tone=${tone}>${text}</p>`);
}

function renderBody(state) {
    if (state === null || (state.state === 'loading' && !state.data)) {
        return html`<p class="changes-note" role="status">${lll('chat.changesLoading')}</p>`;
    }

    const data = state.data;
    if (state.state === 'error' && !data) {
        return html`<p class="changes-note" data-tone="warning" role="alert">${lll('chat.changesError')}</p>`;
    }

    return html`
        ${state.state === 'error' ? html`<p class="changes-note" data-tone="warning" role="alert">${lll('chat.changesError')}</p>` : nothing}
        ${data.changes.length > 0 ? html`<ul class="changes-list">${data.changes.map(renderChange)}</ul>` : html`<p class="changes-note">${lll('chat.changesNone')}</p>`}
        ${renderNotes(data)}
    `;
}

/**
 * The section, or nothing while the conversation has nothing to list. A
 * native disclosure: reachable and operable by keyboard, announced as
 * expandable, with no script for its state beyond asking for the list.
 *
 * @param {object} chat the ChatCoreController
 */
export function renderChanges(chat) {
    if (!chat.changesAvailable) {
        return nothing;
    }

    const count = chat.changes?.data?.changes?.length;

    return html`
        <details class="changes-view" ?open=${chat.changesOpen}
            @toggle=${(event) => chat.setChangesOpen(event.target.open)}>
            <summary>${lll('chat.changesTitle')}${typeof count === 'number' ? ` (${count})` : ''}</summary>
            <div class="changes-body">${renderBody(chat.changes)}</div>
        </details>
    `;
}

/**
 * What a card adds to one call (ADR-034): a call nr-llm refused by a rule of
 * the configuration (nr-llm ADR-293) is marked as one that will not be carried
 * out; its preview lines are the rule's explanation and stay below.
 *
 * @param {{refused?: boolean}} call
 */
export function renderCallRefusal(call) {
    return call.refused
        ? html`<p class="approval-warning call-refused" data-refused="true"><strong>${lll('chat.approvalRefused')}</strong></p>`
        : nothing;
}

/**
 * The note beside a card whose configuration requires a draft workspace while
 * a call would write in live. Shown for the live call, refused or not: the
 * sentence says what to do, which the refusal's own text may word otherwise.
 *
 * @param {{draftWorkspaceRequired?: boolean, calls?: Array<{workspaceId?: number}>}} pending
 */
export function renderDraftWorkspaceNote(pending) {
    if (pending?.draftWorkspaceRequired !== true || !(pending.calls ?? []).some((call) => !(call.workspaceId > 0))) {
        return nothing;
    }

    return html`<p class="approval-note" role="note">${lll('chat.approvalDraftWorkspaceRequired')}</p>`;
}
