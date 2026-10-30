// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Clicks in the "Recent chats" dashboard widget: open the conversation in the
 * chat panel when there is one, and its page in the page module; follow the
 * link otherwise.
 */

import {describe, test, expect, jest, beforeAll, beforeEach, afterEach} from '@jest/globals';

function link(attrs) {
    const a = document.createElement('a');
    a.href = '/typo3/module/nr/mcp/agent/chat';
    for (const [k, v] of Object.entries(attrs)) a.setAttribute(k, v);
    a.textContent = 'x';
    document.body.append(a);
    return a;
}

function click(el, init = {}) {
    const event = new MouseEvent('click', {bubbles: true, cancelable: true, composed: true, ...init});
    el.dispatchEvent(event);
    return event;
}

describe('dashboard widget', () => {
    beforeAll(async () => {
        await import('../../Resources/Public/JavaScript/dashboard-widget.js');
    });

    beforeEach(() => document.body.replaceChildren());

    function fakePanel(state = 'hidden') {
        const panel = document.createElement('ai-chat-panel-fake');
        panel.state = state;
        panel.toggle = jest.fn(() => { panel.state = 'expanded'; });
        panel.chat = {selectConversation: jest.fn(), handleNewConversation: jest.fn()};
        // The script looks the panel up by its tag name.
        jest.spyOn(document, 'querySelector').mockImplementation((sel) => (sel === 'ai-chat-panel' ? panel : null));
        return panel;
    }

    test('a conversation opens in the panel', () => {
        const panel = fakePanel();
        const event = click(link({'data-nr-chat-conversation': '12'}));

        expect(event.defaultPrevented).toBe(true);
        expect(panel.toggle).toHaveBeenCalled();
        expect(panel.chat.selectConversation).toHaveBeenCalledWith(12);
        document.querySelector.mockRestore();
    });

    test('"New chat" starts one in the panel without toggling an open panel shut', () => {
        const panel = fakePanel('expanded');
        click(link({'data-nr-chat-new': '1'}));

        expect(panel.toggle).not.toHaveBeenCalled();
        expect(panel.chat.handleNewConversation).toHaveBeenCalled();
        document.querySelector.mockRestore();
    });

    test.each([
        ['Ctrl', {ctrlKey: true}],
        ['Cmd', {metaKey: true}],
        ['Shift', {shiftKey: true}],
        ['Alt', {altKey: true}],
        ['middle button', {button: 1}],
    ])('a %s click opens the link the way the browser does', (_name, init) => {
        const panel = fakePanel();
        const event = click(link({'data-nr-chat-conversation': '12'}), init);

        expect(event.defaultPrevented).toBe(false);
        expect(panel.chat.selectConversation).not.toHaveBeenCalled();
        document.querySelector.mockRestore();
    });

    test('a panel popped out into its own window is found through the backend window', async () => {
        const {PANEL_PROPERTY} = await import('../../Resources/Public/JavaScript/chat-panel-registry.js');
        const popped = {isConnected: true, state: 'expanded', toggle: jest.fn(), chat: {selectConversation: jest.fn(), handleNewConversation: jest.fn()}};
        window[PANEL_PROPERTY] = popped;
        try {
            const event = click(link({'data-nr-chat-conversation': '12'}));

            expect(event.defaultPrevented).toBe(true);
            expect(popped.chat.selectConversation).toHaveBeenCalledWith(12);
        } finally {
            delete window[PANEL_PROPERTY];
        }
    });

    test('without a panel the link to the module is followed', () => {
        const event = click(link({'data-nr-chat-conversation': '12'}));

        expect(event.defaultPrevented).toBe(false);
    });

    test('other links are left alone', () => {
        const panel = fakePanel();
        const event = click(link({}));

        expect(event.defaultPrevented).toBe(false);
        expect(panel.chat.selectConversation).not.toHaveBeenCalled();
        document.querySelector.mockRestore();
    });

    describe('a conversation about a page', () => {
        let showModule;

        beforeEach(() => {
            showModule = jest.fn();
            globalThis.TYPO3 = {ModuleMenu: {App: {showModule}}};
        });

        afterEach(() => {
            delete globalThis.TYPO3;
        });

        test('also shows the page in the page module, in its language', () => {
            const panel = fakePanel();
            click(link({'data-nr-chat-conversation': '12', 'data-nr-chat-page': '7', 'data-nr-chat-language': '1'}));

            expect(panel.chat.selectConversation).toHaveBeenCalledWith(12);
            expect(showModule).toHaveBeenCalledTimes(1);
            const [module, query] = showModule.mock.calls[0];
            const params = new URLSearchParams(query);
            expect(module).toBe('web_layout');
            expect(params.get('id')).toBe('7');
            // TYPO3 13 reads `language`, 14 reads `languages[]`.
            expect(params.get('language')).toBe('1');
            expect(params.get('languages[0]')).toBe('1');
            document.querySelector.mockRestore();
        });

        test('without a known language the page module keeps its own', () => {
            fakePanel();
            click(link({'data-nr-chat-conversation': '12', 'data-nr-chat-page': '7', 'data-nr-chat-language': '-1'}));

            expect(showModule).toHaveBeenCalledWith('web_layout', 'id=7');
            document.querySelector.mockRestore();
        });

        // A deleted page, or none: the template leaves the attribute empty.
        test('without a page only the chat opens', () => {
            const panel = fakePanel();
            click(link({'data-nr-chat-conversation': '12', 'data-nr-chat-page': '', 'data-nr-chat-language': ''}));

            expect(panel.chat.selectConversation).toHaveBeenCalledWith(12);
            expect(showModule).not.toHaveBeenCalled();
            document.querySelector.mockRestore();
        });

        test('a modified click opens neither the chat nor the page', () => {
            const panel = fakePanel();
            click(link({'data-nr-chat-conversation': '12', 'data-nr-chat-page': '7'}), {ctrlKey: true});

            expect(panel.chat.selectConversation).not.toHaveBeenCalled();
            expect(showModule).not.toHaveBeenCalled();
            document.querySelector.mockRestore();
        });

        test('without the panel the link to the chat module is followed and the page is left alone', () => {
            const event = click(link({'data-nr-chat-conversation': '12', 'data-nr-chat-page': '7'}));

            expect(event.defaultPrevented).toBe(false);
            expect(showModule).not.toHaveBeenCalled();
        });

        test('without core\'s module menu the chat still opens', () => {
            delete globalThis.TYPO3;
            const panel = fakePanel();
            const event = click(link({'data-nr-chat-conversation': '12', 'data-nr-chat-page': '7'}));

            expect(event.defaultPrevented).toBe(true);
            expect(panel.chat.selectConversation).toHaveBeenCalledWith(12);
            document.querySelector.mockRestore();
        });
    });
});

/**
 * The widget's times are rendered in the server's time zone; the script words
 * them again in the reader's, from `datetime`, with the labels and the locale
 * the list carries (ADR-027).
 */
describe('the times of the recent chats', () => {
    const local = (day, hour, minute) => new Date(2026, 9, day, hour, minute).toISOString();

    function list(times) {
        const ul = document.createElement('ul');
        ul.className = 'nr-recent-chats';
        Object.assign(ul.dataset, {nrChatLocale: 'de', nrChatToday: 'Heute, %s', nrChatYesterday: 'Gestern, %s'});
        for (const iso of times) {
            const time = document.createElement('time');
            time.className = 'nr-recent-chats-time';
            time.setAttribute('datetime', iso);
            time.textContent = 'server';
            const li = document.createElement('li');
            li.append(time);
            ul.append(li);
        }
        return ul;
    }

    beforeEach(() => document.body.replaceChildren());

    test('are worded in the reader\'s calendar day', async () => {
        const {relabelTimes} = await import('../../Resources/Public/JavaScript/dashboard-widget.js');
        document.body.append(list([local(24, 10, 42), local(23, 9, 0), local(3, 9, 0)]));

        relabelTimes(document, new Date(2026, 9, 24, 12, 0));

        const times = [...document.querySelectorAll('time')];
        expect(times.map((t) => t.textContent)).toEqual(['Heute, 10:42', 'Gestern, 09:00', '3. Okt.']);
        expect(times[0].title).toMatch(/^24\. Oktober 2026.*10:42$/);
    });

    test('a list the dashboard renders later is worded as it appears', async () => {
        await import('../../Resources/Public/JavaScript/dashboard-widget.js');
        document.body.append(list(['2020-01-01T08:00:00Z']));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.querySelector('time').textContent).not.toBe('server');
    });

    test('a list without a locale is left as the server rendered it', async () => {
        const {relabelTimes} = await import('../../Resources/Public/JavaScript/dashboard-widget.js');
        const ul = list([local(24, 10, 42)]);
        delete ul.dataset.nrChatLocale;
        document.body.append(ul);

        relabelTimes(document);

        expect(document.querySelector('time').textContent).toBe('server');
    });
});
