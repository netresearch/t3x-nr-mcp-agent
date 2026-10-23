// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * A running turn is shown as running.
 *
 * ai-chat:process and ai-chat:worker keep a conversation `locked` for the
 * whole turn, so no second consumer can take it. The API reports that status
 * as it is; the chat shows it like `processing` (label and ⟳), because for
 * the reader both mean "the assistant is working on it". Before, `locked`
 * was shown as the word "locked" with ⊘ for the length of every turn.
 */

import {describe, test, expect, beforeEach} from '@jest/globals';
import {PROCESSING_STATUSES, displayStatus, statusLabel, ChatCoreController} from '../../Resources/Public/JavaScript/chat-core.js';

describe('displayStatus', () => {
    test('shows a locked conversation as processing', () => {
        expect(displayStatus('locked')).toBe('processing');
    });

    test.each(['idle', 'processing', 'tool_loop', 'awaiting_approval', 'failed'])('leaves %s as it is', (status) => {
        expect(displayStatus(status)).toBe(status);
    });
});

/**
 * The reader sees a word, never the internal status value: the chat module
 * listed "AWAITING_INPUT" and "IDLE" on the demo (2026-10-23).
 */
describe('statusLabel', () => {
    test.each([
        ['idle', 'status.idle'],
        ['processing', 'status.processing'],
        ['locked', 'status.processing'],
        ['tool_loop', 'status.processing'],
        ['awaiting_approval', 'status.awaitingApproval'],
        ['awaiting_input', 'status.awaitingInput'],
        ['failed', 'status.failed'],
    ])('labels %s with %s', (status, label) => {
        expect(statusLabel(status)).toBe(label);
    });

    test('labels a status it does not know with the generic label', () => {
        expect(statusLabel('archived_later')).toBe('status.unknown');
    });
});

describe('a locked conversation is busy', () => {
    test('locked is one of the processing statuses', () => {
        expect(PROCESSING_STATUSES.has('locked')).toBe(true);
    });

    test('the controller treats a locked conversation as processing', () => {
        // isProcessing() is what polling and the disabled composer hang on;
        // without `locked` in the set the chat would stop polling mid-turn.
        const controller = Object.create(ChatCoreController.prototype);
        controller.status = 'locked';
        expect(controller.isProcessing()).toBe(true);
    });
});

const LOCKED = {uid: 7, title: 'Running', status: 'locked', pinned: false, tstamp: 1};

async function render(modulePath, tag, state) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 7,
        available: true,
        loading: false,
        issues: [],
        conversations: [LOCKED],
        status: 'locked',
        messages: [],
    });
    if (state) {
        el.state = state;
    }
    el.requestUpdate();
    await el.updateComplete;

    return el;
}

describe('rendering a locked conversation', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    test('chat-app lists it as processing', async () => {
        const el = await render('../../Resources/Public/JavaScript/chat-app.js', 'nr-chat-app');
        const badge = el.shadowRoot.querySelector('.conversation-item .status-badge');

        expect(badge.textContent.trim()).toBe('status.processing');
        expect(badge.classList.contains('status-processing')).toBe(true);
        expect(badge.classList.contains('status-locked')).toBe(false);
    });

    test('the panel header and tab show the processing icon', async () => {
        const el = await render('../../Resources/Public/JavaScript/ai-chat-panel.js', 'ai-chat-panel', 'expanded');
        const header = el.shadowRoot.querySelector('.panel-header .status-badge');
        const tab = el.shadowRoot.querySelector('.conv-tab .tab-icon');

        expect(header.textContent.trim()).toBe('⟳');
        expect(header.getAttribute('title')).toBe('status.processing');
        expect(tab.textContent.trim()).toBe('⟳');
        expect(tab.classList.contains('status-processing')).toBe(true);
        expect(tab.closest('.conv-tab').getAttribute('title')).toBe('Running (status.processing)');
    });

    test('the maximized panel sidebar shows the processing icon', async () => {
        const el = await render('../../Resources/Public/JavaScript/ai-chat-panel.js', 'ai-chat-panel', 'maximized');
        const badge = el.shadowRoot.querySelector('.sidebar-item .status-badge');

        expect(el.shadowRoot.querySelector('.status-locked')).toBeNull();
        expect(badge).not.toBeNull();
        expect(badge.textContent.trim()).toBe('⟳');
        expect(badge.getAttribute('title')).toBe('status.processing');
    });

    test('chat-app lists a conversation waiting for an answer with its label', async () => {
        const el = await render('../../Resources/Public/JavaScript/chat-app.js', 'nr-chat-app');
        el.chat.conversations = [{...LOCKED, status: 'awaiting_input'}];
        el.requestUpdate();
        await el.updateComplete;
        const badge = el.shadowRoot.querySelector('.conversation-item .status-badge');

        expect(badge.textContent.trim()).toBe('status.awaitingInput');
        expect(badge.classList.contains('status-awaiting_input')).toBe(true);
    });
});
