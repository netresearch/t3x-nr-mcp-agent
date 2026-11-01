// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The running text in both surfaces (ADR-028): rendered like a finished answer,
 * hidden from the live region so that no screen reader follows it token by
 * token, and gone with the turn.
 */

import {afterEach, describe, test, expect, beforeAll, jest} from '@jest/globals';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app'},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

async function render(modulePath, tag, open, overrides) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'Frage', status: 'locked', messageCount: 1, pinned: false}],
        messages: [{role: 'user', content: 'Was kannst du?'}],
        errorMessage: '',
        ...overrides,
    });
    el.chat.syncPageWatch = jest.fn();
    el.chat.startPollingIfNeeded = jest.fn();
    if (open) el.state = 'expanded';
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

describe.each(SURFACES)('$name', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    afterEach(() => {
        document.body.replaceChildren();
    });

    test('only a reader at the bottom counts as following the text', async () => {
        const el = await render(modulePath, tag, open, {status: 'locked', liveText: 'Text'});
        const list = el.shadowRoot.querySelector('.messages, .panel-messages');
        Object.defineProperty(list, 'scrollHeight', {value: 1000, configurable: true});
        Object.defineProperty(list, 'clientHeight', {value: 400, configurable: true});

        list.scrollTop = 600;
        expect(el.isAtBottom()).toBe(true);

        list.scrollTop = 500;
        expect(el.isAtBottom()).toBe(false);
    });

    test('the running text is rendered as an answer and hidden from the live region', async () => {
        const el = await render(modulePath, tag, open, {status: 'locked', liveText: 'Das ist **wichtig**'});

        const live = el.shadowRoot.querySelector('[data-live-text]');
        expect(live.querySelector('strong')?.textContent).toBe('wichtig');
        expect(live.getAttribute('aria-hidden')).toBe('true');
        expect(live.hasAttribute('inert')).toBe(true);
        expect(el.shadowRoot.querySelector('.typing-indicator')).not.toBeNull();
    });

    test('a turn without text shows the typing indicator only', async () => {
        const el = await render(modulePath, tag, open, {status: 'locked', liveText: ''});

        expect(el.shadowRoot.querySelector('[data-live-text]')).toBeNull();
        expect(el.shadowRoot.querySelector('.typing-indicator')).not.toBeNull();
    });

    test('a finished turn shows no running text, whatever is left of it', async () => {
        const el = await render(modulePath, tag, open, {status: 'idle', liveText: 'Rest'});

        expect(el.shadowRoot.querySelector('[data-live-text]')).toBeNull();
    });
});
