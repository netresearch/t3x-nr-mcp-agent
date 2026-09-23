import {html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';

/**
 * What the assistant does in the current turn, step by step (NEXT-172).
 *
 * The worker writes a summary of every model round and tool call onto the
 * conversation while the turn runs, and the chat's poll carries it here. The
 * pending approval is not a step the runtime reports: it is taken from the
 * card the chat already holds, and shown as the last entry while the turn
 * waits for it.
 *
 * Shared by the module and the panel, like chat-editing.js.
 */

export const chatActivityStyles = css`
    .activity {
        display: flex;
        flex-direction: column;
        min-height: 0;
        background: var(--nr-chat-surface-low);
        font-size: 12px;
    }
    .activity-strip {
        max-height: 30%;
        border-bottom: 1px solid var(--nr-chat-border);
        flex-shrink: 0;
    }
    .activity-sidebar {
        width: 240px;
        min-width: 240px;
        border-left: 1px solid var(--nr-chat-border);
    }
    .activity h3 {
        margin: 0;
        padding: 8px 10px 4px;
        font-size: 12px;
        font-weight: 600;
        color: var(--nr-chat-text);
    }
    .activity ol {
        list-style: none;
        margin: 0;
        padding: 0 10px 8px;
        overflow-y: auto;
    }
    .activity li {
        display: flex;
        align-items: baseline;
        gap: 6px;
        padding: 3px 0;
        color: var(--nr-chat-text);
        border-bottom: 1px dashed var(--nr-chat-border);
    }
    .activity li:last-child { border-bottom: none; }
    .activity .activity-icon { flex-shrink: 0; width: 1.2em; text-align: center; }
    .activity .activity-label { flex: 1; min-width: 0; overflow-wrap: anywhere; }
    .activity .activity-meta { flex-shrink: 0; color: var(--nr-chat-text-variant); font-size: 11px; }
    .activity .activity-error { color: var(--nr-chat-status-danger); }
    .activity .activity-waiting { color: var(--nr-chat-status-info); }
    .activity .activity-empty { color: var(--nr-chat-text-variant); border-bottom: none; }
`;

function formatDuration(ms) {
    if (typeof ms !== 'number' || ms <= 0) return '';
    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;
}

/**
 * The entries as the list shows them: the recorded steps, then — derived
 * from the conversation's state — a pending approval or the running turn.
 *
 * @returns {Array<{icon: string, label: string, meta: string, tone: string}>}
 */
export function activityEntries(chat) {
    const entries = (chat.activity || []).map((entry) => {
        if (entry.kind === 'tool') {
            return {
                icon: entry.error ? '✕' : '⚙',
                label: lll('activity.tool', entry.tool || '?'),
                meta: entry.error ? lll('activity.failed') : formatDuration(entry.ms),
                tone: entry.error ? 'error' : '',
            };
        }
        if (entry.kind === 'approval') {
            return {
                icon: entry.approved ? '✓' : '⊘',
                label: entry.approved ? lll('activity.approved') : lll('activity.denied'),
                meta: '',
                tone: '',
            };
        }
        return {icon: '◌', label: lll('activity.modelRound', entry.round ?? 0), meta: formatDuration(entry.ms), tone: ''};
    });

    if (chat.status === 'awaiting_approval') {
        const names = (chat.pendingApproval?.calls || []).map((c) => c.name).join(', ');
        entries.push({icon: '⏸', label: lll('activity.waiting'), meta: names, tone: 'waiting'});
    } else if (chat.isProcessing()) {
        entries.push({icon: '⟳', label: lll('activity.working'), meta: '', tone: 'waiting'});
    }

    return entries;
}

/**
 * @param {object} chat the ChatCoreController
 * @param {'strip'|'sidebar'} placement
 */
export function renderActivity(chat, placement) {
    if (!chat.activityOpen || !chat.activeUid) {
        return nothing;
    }
    const entries = activityEntries(chat);
    return html`
        <aside class="activity activity-${placement}" aria-label="${lll('activity.title')}">
            <h3>${lll('activity.title')}</h3>
            <ol aria-live="polite">
                ${entries.length === 0
                    ? html`<li class="activity-empty">${lll('activity.empty')}</li>`
                    : entries.map((e) => html`
                        <li class="${e.tone ? `activity-${e.tone}` : ''}">
                            <span class="activity-icon" aria-hidden="true">${e.icon}</span>
                            <span class="activity-label">${e.label}</span>
                            ${e.meta ? html`<span class="activity-meta">${e.meta}</span>` : nothing}
                        </li>
                    `)}
            </ol>
        </aside>
    `;
}
