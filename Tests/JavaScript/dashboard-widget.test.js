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
