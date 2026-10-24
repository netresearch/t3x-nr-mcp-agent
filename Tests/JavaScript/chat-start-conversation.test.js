// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * A question from the prompt widget starts a conversation in the panel
 * (ai-chat-panel.js, startConversation()): the chat opens as a column where
 * there is room for one, for this opening only, a new conversation is created
 * from the connection the move leaves, and the question is its first message,
 * as typed. What would refuse the message is checked before a conversation is
 * created; a conversation whose message failed takes the next question.
 */

import {describe, test, expect, beforeAll, beforeEach, afterEach, jest} from '@jest/globals';
import Persistent from '@typo3/backend/storage/persistent.js';
import {findPanel, PANEL_PROPERTY} from '../../Resources/Public/JavaScript/chat-panel-registry.js';

function el(tag, attributes = {}, children = []) {
    const element = document.createElement(tag);
    for (const [name, value] of Object.entries(attributes)) {
        element.setAttribute(name, value);
    }
    element.append(...children);

    return element;
}

const QUESTION = 'Wie heißt die Seite <b>Über uns</b>?\nUnd was steht in **Absatz 2**?';

describe('starting a conversation from outside the panel', () => {
    let panel;
    let area;
    let requests;
    let status;
    let created;
    let failSends;
    let stored;

    beforeAll(async () => {
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
    });

    beforeEach(() => {
        globalThis.TYPO3 = {settings: {ajaxUrls: new Proxy({}, {get: (_, key) => '/ajax/' + String(key)})}};
        requests = [];
        status = {available: true, issues: []};
        created = 0;
        failSends = 0;
        stored = {};
        const answers = {
            ai_chat_status: () => [200, status],
            ai_chat_conversations: () => [200, {conversations: Array.from({length: created}, (_, i) => ({uid: 5 + i, title: '', status: 'idle'}))}],
            ai_chat_conversation_create: () => [200, {uid: 5 + created++}],
            // What the server stored: a sent message stays in its conversation.
            ai_chat_conversation_messages: (url) => {
                const list = stored[new URLSearchParams(url.split('?')[1]).get('conversationUid')] ?? [];
                return [200, {messages: list, status: 'idle', totalCount: list.length}];
            },
            ai_chat_conversation_send: (url, body) => {
                if (failSends-- > 0) {
                    return [500, {error: 'Server down'}];
                }
                (stored[body.conversationUid] ??= []).push({role: 'user', content: body.content});
                return [200, {status: 'processing'}];
            },
        };
        globalThis.fetch = jest.fn(async (url, options = {}) => {
            const route = String(url).replace(/^\/ajax\//, '').replace(/\?.*$/, '');
            const body = options.body ? JSON.parse(options.body) : null;
            requests.push({route, body, signal: options.signal});
            const [code, data] = answers[route]?.(String(url), body) ?? [404, {error: 'unknown'}];
            return {ok: code < 400, status: code, json: async () => data};
        });
        Persistent.reset();
        localStorage.clear();
        area = el('div', {slot: 'content'}, [el('typo3-backend-module-router')]);
        document.body.replaceChildren(el('div', {class: 'scaffold-content t3js-scaffold-content'}, [
            el('typo3-backend-content-navigation', {identifier: 'backend'}, [el('div', {slot: 'navigation'}), area]),
        ]));
        panel = document.createElement('ai-chat-panel');
        document.body.append(panel);
        panel._areaWidth = 1400;
    });

    afterEach(() => {
        panel.chat.stopPolling();
        panel.remove();
        delete globalThis.fetch;
        delete globalThis.TYPO3;
        delete window.documentPictureInPicture;
    });

    const sent = () => requests.filter((r) => r.route === 'ai_chat_conversation_send');
    const creates = () => requests.filter((r) => r.route === 'ai_chat_conversation_create');

    test('opens the column and sends the question unchanged as the first message', async () => {
        expect(await panel.startConversation(QUESTION)).toEqual({sent: true, error: ''});

        expect(panel.layout).toBe('column');
        expect(panel.parentElement).toBe(area);
        expect(panel.state).toBe('expanded');
        expect(sent()).toHaveLength(1);
        expect(sent()[0].body).toMatchObject({conversationUid: 5, content: QUESTION});
        expect(panel.chat.activeUid).toBe(5);
        expect(panel.chat.messages).toEqual([expect.objectContaining({role: 'user', content: QUESTION})]);
    });

    test('creates the conversation from the connection the move leaves', async () => {
        await panel.startConversation(QUESTION);

        expect(creates()[0].signal.aborted).toBe(false);
        expect(sent()[0].signal.aborted).toBe(false);
    });

    test('docks for this opening only: the chosen layout is not stored, and closing goes back to it', async () => {
        await panel.startConversation(QUESTION);
        expect(panel.layout).toBe('column');
        expect(Persistent.writes.filter(([key]) => key === 'nrMcpAgent.chatPanel.layout')).toEqual([]);

        panel.hide();
        expect(panel.layout).toBe('floating');
        expect(panel.parentElement).toBe(document.body);
        expect(Persistent.writes.filter(([key]) => key === 'nrMcpAgent.chatPanel.layout')).toEqual([]);
    });

    test('closing with the toolbar button also goes back to the chosen layout', async () => {
        await panel.startConversation(QUESTION);
        expect(panel.layout).toBe('column');

        panel.toggle();

        expect(panel.state).toBe('hidden');
        expect(panel.layout).toBe('floating');
        expect(panel.parentElement).toBe(document.body);
    });

    test('a user who chose the column keeps it after closing', async () => {
        Persistent.set('nrMcpAgent.chatPanel.layout', 'column');
        expect(panel.setLayout('column')).toBe(true);

        await panel.startConversation(QUESTION);
        panel.hide();

        expect(panel.layout).toBe('column');
        expect(panel.parentElement).toBe(area);
    });

    test('leaves a draft in the chat\'s input alone', async () => {
        await panel.chat.ready;
        panel.chat.inputValue = 'half a thought';
        panel.chat.hasInput = true;

        await panel.startConversation(QUESTION);

        expect(sent()[0].body.content).toBe(QUESTION);
        expect(panel.chat.inputValue).toBe('half a thought');
    });

    test('floats where the area is too narrow for the column, and still sends', async () => {
        panel._areaWidth = 600;

        expect((await panel.startConversation(QUESTION)).sent).toBe(true);

        expect(panel.layout).toBe('floating');
        expect(panel.parentElement).toBe(document.body);
        expect(sent()[0].body.content).toBe(QUESTION);
    });

    test('stays in its own window where the user popped it out, and sends there', async () => {
        const pipWindow = {document: document.implementation.createHTMLDocument('pip'), addEventListener: jest.fn()};
        window.documentPictureInPicture = {requestWindow: jest.fn().mockResolvedValue(pipWindow)};
        panel.state = 'expanded';
        await panel.chat.ready;
        expect(await panel.popOut()).toBe(true);
        expect(document.querySelector('ai-chat-panel')).toBeNull();

        // The widgets still find it, through the backend window.
        expect(findPanel(window)).toBe(panel);
        expect((await panel.startConversation(QUESTION)).sent).toBe(true);

        expect(panel.ownerDocument).toBe(pipWindow.document);
        expect(sent()[0].body.content).toBe(QUESTION);
    });

    test('opens a hidden panel and keeps an open one open', async () => {
        panel.state = 'collapsed';
        await panel.startConversation(QUESTION);
        expect(panel.state).toBe('expanded');
        // The run is over: the conversation is idle again, with its message.
        await panel.chat.loadMessages();
        expect(panel.chat.isProcessing()).toBe(false);
        expect(panel.chat.messages).toHaveLength(1);

        await panel.startConversation('Noch eine Frage');
        expect(panel.state).toBe('expanded');
        expect(sent().map((r) => r.body.content)).toEqual([QUESTION, 'Noch eine Frage']);
        // The first conversation holds its message, so the second question
        // gets a conversation of its own.
        expect(creates()).toHaveLength(2);
        expect(sent().map((r) => r.body.conversationUid)).toEqual([5, 6]);
    });

    test.each([[''], ['   '], ['\n\n']])('sends nothing for %j', async (text) => {
        expect(await panel.startConversation(text)).toEqual({sent: false, error: ''});

        expect(requests.filter((r) => r.route.startsWith('ai_chat_conversation_'))).toEqual([]);
    });

    test('creates nothing where the chat is not available, and says so', async () => {
        status = {available: false, issues: ['No LLM configured']};
        // As loaded when the backend starts.
        await panel.chat.init();

        expect(await panel.startConversation(QUESTION)).toEqual({sent: false, error: 'chat.notAvailable'});

        expect(creates()).toEqual([]);
        expect(sent()).toEqual([]);
    });

    test('creates nothing while another message is being sent, and says why', async () => {
        await panel.chat.ready;
        panel.chat.sending = true;

        // A valid question at the wrong moment: not marked invalid, even when
        // it is also too long.
        expect(await panel.startConversation(QUESTION)).toEqual({sent: false, error: 'chat.stillSending'});
        panel.chat.maxLength = 5;
        expect(await panel.startConversation(QUESTION)).toEqual({sent: false, error: 'chat.stillSending'});

        expect(creates()).toEqual([]);
        expect(panel.layout).toBe('floating');
    });

    test('creates nothing for a question over the configured maximum, and says why', async () => {
        status = {available: true, issues: [], maxMessageLength: 10};
        // As loaded when the backend starts.
        await panel.chat.init();

        // The question itself is refused: marked invalid.
        expect(await panel.startConversation('elf Zeichen')).toEqual({sent: false, error: 'chat.messageTooLong', invalid: true});
        expect(creates()).toEqual([]);

        // Counted in characters, as the server counts them (mb_strlen): ten
        // emoji fit, although they are twenty UTF-16 units.
        expect((await panel.startConversation('\u{1F600}'.repeat(10))).sent).toBe(true);
    });

    test('a failed send leaves no second empty conversation: the next question uses it', async () => {
        failSends = 1;

        expect(await panel.startConversation(QUESTION)).toEqual({sent: false, error: 'Server down'});
        expect(creates()).toHaveLength(1);

        expect((await panel.startConversation(QUESTION)).sent).toBe(true);
        expect(creates()).toHaveLength(1);
        expect(sent().map((r) => r.body.conversationUid)).toEqual([5, 5]);
    });

    test('a reused conversation does not send an attachment picked for it', async () => {
        failSends = 1;
        await panel.startConversation(QUESTION);
        panel.chat.pendingFile = {fileUid: 7, name: 'a.pdf', mimeType: 'application/pdf'};

        expect((await panel.startConversation(QUESTION)).sent).toBe(true);

        expect(creates()).toHaveLength(1);
        expect(sent()[1].body.fileUid ?? null).toBeNull();
        expect(panel.chat.pendingFile).toBeNull();
    });

    test('a conversation the user switched away from is not reused', async () => {
        failSends = 1;
        await panel.startConversation(QUESTION);
        panel.chat.activeUid = 99;

        await panel.startConversation(QUESTION);

        expect(creates()).toHaveLength(2);
    });

    test('a second question while the first is on its way is refused, with the reason', async () => {
        const first = panel.startConversation(QUESTION);
        expect(await panel.startConversation('zweimal')).toEqual({sent: false, error: 'chat.stillSending'});
        expect((await first).sent).toBe(true);

        expect(sent().map((r) => r.body.content)).toEqual([QUESTION]);
    });

    test('registers on the backend window while connected, and only then', async () => {
        expect(window[PANEL_PROPERTY]).toBe(panel);

        // A move reconnects in the same step: still registered.
        panel.setLayout('column');
        await Promise.resolve();
        expect(window[PANEL_PROPERTY]).toBe(panel);

        panel.remove();
        await Promise.resolve();
        expect(window[PANEL_PROPERTY]).toBeUndefined();
    });
});
