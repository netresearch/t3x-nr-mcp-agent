// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * A run that waits for a remote agent (nr-llm ADR-281): both chat surfaces say
 * what the run waits for and offer to stop the wait, the chat keeps asking the
 * server while it waits, and nothing new is sent meanwhile.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';
import {ChatCoreController} from '../../Resources/Public/JavaScript/chat-core.js';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const MESSAGES = [
    {role: 'user', content: 'Lass den Text vom Übersetzungsagenten übersetzen'},
];

async function render(modulePath, tag, open, overrides = {}) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'Übersetzung', status: 'awaiting_dependency', messageCount: 1, pinned: false}],
        messages: MESSAGES,
        status: 'awaiting_dependency',
        errorMessage: '',
        inputValue: 'Noch etwas',
        hasInput: true,
        ...overrides,
    });
    el.chat.syncPageWatch = jest.fn();
    el.chat.startPollingIfNeeded = jest.fn();
    if (open) el.state = 'OPEN';
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

function cancelButton(el) {
    return el.shadowRoot.querySelector('[data-action="cancel-wait"]');
}

describe.each(SURFACES)('$name while the run waits for a remote agent', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('says what the run waits for, as a status', async () => {
        const el = await render(modulePath, tag, open);
        const notice = el.shadowRoot.querySelector('.agent-wait');

        expect(notice).not.toBeNull();
        expect(notice.getAttribute('role')).toBe('status');
        expect(notice.textContent).toContain('chat.waitingForAgent');
    });

    test('offers to stop the wait, and no Retry', async () => {
        const el = await render(modulePath, tag, open);

        expect(cancelButton(el).tagName).toBe('BUTTON');
        expect(cancelButton(el).textContent.trim()).toBe('chat.cancelWait');
        expect(el.shadowRoot.textContent).not.toContain('chat.retry');
    });

    test('stopping the wait asks the server to cancel the run', async () => {
        const el = await render(modulePath, tag, open);
        el.chat._api.cancelWait = jest.fn().mockResolvedValue({status: 'idle'});
        el.chat._api.getMessages = jest.fn().mockResolvedValue({
            status: 'idle',
            messages: [{role: 'assistant', content: '[The run was cancelled while it waited for a remote agent.]', notice: 'waitCancelled'}],
            totalCount: 2,
        });
        el.chat._knownMessageCount = 1;

        cancelButton(el).click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await new Promise((resolve) => setTimeout(resolve, 0));
        await el.updateComplete;

        expect(el.chat._api.cancelWait).toHaveBeenCalledWith(1);
        expect(el.chat.status).toBe('idle');
        expect(el.shadowRoot.textContent).toContain('chat.waitCancelled');
        expect(cancelButton(el)).toBeNull();
        // The button went with the notice: focus moves to the input.
        await el.updateComplete;
        expect(el.shadowRoot.activeElement?.tagName).toBe('TEXTAREA');
    });

    test('a refused stop shows why, beside the wait', async () => {
        const el = await render(modulePath, tag, open);
        el.chat._api.cancelWait = jest.fn().mockRejectedValue(new Error('Der Lauf war schon weiter'));
        el.chat._api.getMessages = jest.fn().mockResolvedValue({status: 'awaiting_dependency', messages: [], totalCount: 1});
        el.chat._knownMessageCount = 1;

        cancelButton(el).click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await new Promise((resolve) => setTimeout(resolve, 0));
        await el.updateComplete;

        const alert = el.shadowRoot.querySelector('[role="alert"]');
        expect(alert?.textContent).toContain('Der Lauf war schon weiter');
        expect(cancelButton(el)).not.toBeNull();
    });

    test('nothing new is sent while the run waits', async () => {
        const el = await render(modulePath, tag, open);

        expect(el.shadowRoot.querySelector('.btn-send').disabled).toBe(true);

        // The same input on a conversation that waits for nothing may go.
        el.chat.status = 'idle';
        el.requestUpdate();
        await el.updateComplete;
        expect(el.shadowRoot.querySelector('.btn-send').disabled).toBe(false);
    });
});

describe('the chat while the run waits for a remote agent', () => {
    function controller(status) {
        const host = {addController: jest.fn(), requestUpdate: jest.fn(), onScrollToBottom: jest.fn(), isConnected: true};
        const chat = new ChatCoreController(host);
        chat._api = {};
        chat.status = status;
        chat.activeUid = 1;
        return chat;
    }

    test('keeps asking the server, slower than for a running turn', () => {
        jest.useFakeTimers();
        try {
            const chat = controller('awaiting_dependency');
            chat._openStream = jest.fn();
            chat.pollMessages = jest.fn().mockResolvedValue(undefined);

            chat.startPollingIfNeeded();

            expect(chat._openStream).not.toHaveBeenCalled();
            jest.advanceTimersByTime(2000);
            expect(chat.pollMessages).not.toHaveBeenCalled();
            jest.advanceTimersByTime(8000);
            expect(chat.pollMessages).toHaveBeenCalledTimes(1);
        } finally {
            jest.useRealTimers();
        }
    });

    test('stops asking once the wait has ended', async () => {
        const chat = controller('awaiting_dependency');
        chat._knownMessageCount = 1;
        chat._api.getMessages = jest.fn().mockResolvedValue({
            status: 'idle',
            messages: [{role: 'assistant', content: 'Hello world.'}],
            totalCount: 2,
        });
        chat.stopPolling = jest.fn();

        await chat.pollMessages();

        expect(chat.status).toBe('idle');
        expect(chat.stopPolling).toHaveBeenCalled();
    });

    test('keeps asking while it still waits', async () => {
        const chat = controller('awaiting_dependency');
        chat._knownMessageCount = 1;
        chat._api.getMessages = jest.fn().mockResolvedValue({status: 'awaiting_dependency', messages: [], totalCount: 1});
        chat.stopPolling = jest.fn();

        await chat.pollMessages();

        expect(chat.stopPolling).not.toHaveBeenCalled();
    });

    test('an answer that was not kept is said in the reader\'s language', () => {
        const chat = controller('idle');

        expect(chat.formatRunNotice({notice: 'answerNotKept'})).toBe('chat.answerNotKept');
        expect(chat.formatRunNotice({notice: 'waitCancelled'})).toBe('chat.waitCancelled');
    });
});
