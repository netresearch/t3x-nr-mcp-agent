// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {html, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';

/**
 * The notice while the run waits for a remote agent (nr-llm ADR-281): what it
 * waits for, that it goes on by itself, and the one thing the editor can do,
 * stop the wait. Both chat surfaces render it under the transcript.
 *
 * A status, not an error: no Retry (a new turn would send the same task to
 * the agent again) and no Dismiss (the notice follows the status).
 *
 * @param {{isWaitingForAgent: () => boolean, cancellingWait: boolean, handleCancelWait: () => Promise<void>}} chat
 */
export function renderAgentWait(chat) {
    if (!chat.isWaitingForAgent()) {
        return nothing;
    }

    return html`
        <div class="message system status-notice agent-wait" role="status" tabindex="-1"
            style="color:var(--nr-chat-status-info, #0277bd);">
            ${lll('chat.waitingForAgent')}
            <button type="button" class="btn btn-sm" data-action="cancel-wait" style="margin-left:8px;"
                ?disabled=${chat.cancellingWait}
                @click=${() => chat.handleCancelWait()}>${lll('chat.cancelWait')}</button>
        </div>
    `;
}
