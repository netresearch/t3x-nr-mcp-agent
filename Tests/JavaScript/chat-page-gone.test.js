// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * A conversation about a page that has been deleted is read-only: the server
 * says so with `pageGone`, the chat shows a notice where the input was, and
 * nothing is sent.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app'},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const MESSAGES = [
    {role: 'user', content: 'Hero-Text kürzen'},
    {role: 'assistant', content: 'Erledigt.'},
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
        conversations: [{uid: 1, title: 'Hero-Text kürzen', status: 'idle', messageCount: 2, pinned: false}],
        messages: MESSAGES,
        status: 'idle',
        errorMessage: '',
        ...overrides,
    });
    el.chat.syncPageWatch = jest.fn();
    el.chat._api.sendMessage = jest.fn().mockResolvedValue({status: 'processing'});
    el.chat.startPollingIfNeeded = jest.fn();
    if (open) el.state = 'expanded';
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

describe.each(SURFACES)('$name, conversation about a deleted page', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('the notice takes the place of the input', async () => {
        const el = await render(modulePath, tag, open, {pageGone: true});

        const notice = el.shadowRoot.querySelector('.read-only-notice');
        expect(notice).not.toBeNull();
        expect(notice.getAttribute('role')).toBe('note');
        expect(el.shadowRoot.querySelector('textarea')).toBeNull();
        // The transcript is still there to read.
        expect(el.shadowRoot.textContent).toContain('Erledigt.');
        el.remove();
    });

    test('a conversation about an existing page keeps its input', async () => {
        const el = await render(modulePath, tag, open, {pageGone: false});

        expect(el.shadowRoot.querySelector('.read-only-notice')).toBeNull();
        expect(el.shadowRoot.querySelector('textarea')).not.toBeNull();
        el.remove();
    });

    test('nothing is sent', async () => {
        const el = await render(modulePath, tag, open, {pageGone: true, inputValue: 'Weiter', hasInput: true});

        await el.chat.handleSend();

        expect(el.chat._api.sendMessage).not.toHaveBeenCalled();
        el.remove();
    });
});

describe('the flag comes from the server and belongs to one conversation', () => {
    test('loading a conversation reads it, opening another one resets it', async () => {
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
        const el = document.createElement('ai-chat-panel');
        document.body.append(el);
        el.chat.startPollingIfNeeded = jest.fn();
        el.chat.syncPageWatch = jest.fn();
        el.chat._api.getMessages = jest.fn()
            .mockResolvedValueOnce({messages: MESSAGES, status: 'idle', totalCount: 2, pageGone: true})
            .mockResolvedValueOnce({messages: MESSAGES, status: 'idle', totalCount: 2});

        el.chat.activeUid = 1;
        await el.chat.loadMessages();
        expect(el.chat.isReadOnly()).toBe(true);

        const opening = el.chat.selectConversation(2);
        // Reset before the other conversation's answer is in.
        expect(el.chat.isReadOnly()).toBe(false);
        await opening;
        expect(el.chat.isReadOnly()).toBe(false);
        el.remove();
    });
});
