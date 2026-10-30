// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The start links of the assistant widgets (chat-start.js): a plain click
 * starts a conversation in the chat panel, beside the page module where the
 * action concerns a page; any other click, or one without the panel, follows
 * the link to the chat module.
 */

import {describe, test, expect, jest, beforeAll, beforeEach, afterEach} from '@jest/globals';

let startOf;
let startFromLink;

function link(attrs) {
    const a = document.createElement('a');
    a.href = '/typo3/module/nr/mcp/agent/chat?skill=x';
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

function fakePanel({state = 'hidden', layout = 'floating', uid = 7} = {}) {
    const panel = document.createElement('ai-chat-panel-fake');
    panel.state = state;
    panel.layout = layout;
    panel.calls = [];
    panel.toggle = jest.fn(() => { panel.calls.push('toggle'); panel.state = 'expanded'; });
    panel.expand = jest.fn(() => { panel.calls.push('expand'); panel.state = 'expanded'; });
    panel.setLayout = jest.fn((value, options) => { panel.calls.push(`layout:${value}:${options.remember}`); panel.layout = value; });
    panel.requestUpdate = jest.fn();
    panel.onFocusInput = jest.fn(() => panel.calls.push('focus'));
    panel.updateComplete = Promise.resolve(true);
    panel.chat = {
        ready: Promise.resolve(),
        inputValue: '',
        hasInput: false,
        handleNewConversation: jest.fn(async () => { panel.calls.push('new'); return uid; }),
    };
    jest.spyOn(document, 'querySelector').mockImplementation((sel) => (sel === 'ai-chat-panel' ? panel : null));
    return panel;
}

function fakeModuleMenu(panel = null) {
    const showModule = jest.fn(() => panel?.calls.push('page'));
    globalThis.TYPO3 = {ModuleMenu: {App: {showModule}}};
    return showModule;
}

/** Let the started promise chain settle. */
async function settle() {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
}

describe('chat start links', () => {
    beforeAll(async () => {
        ({startOf, startFromLink} = await import('../../Resources/Public/JavaScript/chat-start.js'));
    });

    beforeEach(() => document.body.replaceChildren());

    afterEach(() => {
        document.querySelector.mockRestore?.();
        delete globalThis.TYPO3;
    });

    test('a tour on a page opens the page module on that page in its language and the chat beside it', async () => {
        const panel = fakePanel();
        const showModule = fakeModuleMenu(panel);
        const event = click(link({'data-nr-chat-start': '1', 'data-nr-chat-skill': '3:seo-optimieren', 'data-nr-chat-page': '21', 'data-nr-chat-language': '1'}));
        await settle();

        expect(event.defaultPrevented).toBe(true);
        expect(showModule).toHaveBeenCalledWith('web_layout', 'id=21&language=1&languages%5B0%5D=1');
        expect(panel.chat.handleNewConversation).toHaveBeenCalledWith({skill: '3:seo-optimieren', pageUid: 21, languageUid: 1});
        // Placed and opened before the conversation is created: docking
        // reconnects the panel and aborts what runs on the old connection.
        // The page module opens last: it replaces the dashboard this runs in.
        expect(panel.calls).toEqual(['layout:column:false', 'expand', 'new', 'page']);
    });

    test('a tour without a page opens the page module where the page tree is, and the tour asks for the page', async () => {
        const panel = fakePanel();
        const showModule = fakeModuleMenu();
        click(link({'data-nr-chat-start': '1', 'data-nr-chat-skill': '3:inhalt-verbessern', 'data-nr-chat-page-module': '1'}));
        await settle();

        expect(showModule).toHaveBeenCalledWith('web_layout');
        expect(panel.chat.handleNewConversation).toHaveBeenCalledWith({skill: '3:inhalt-verbessern'});
        expect(panel.setLayout).toHaveBeenCalledWith('column', {remember: false});
    });

    test('"Seite bearbeiten" opens the page module and a new chat without a skill', async () => {
        const panel = fakePanel({state: 'expanded', layout: 'column'});
        const showModule = fakeModuleMenu();
        click(link({'data-nr-chat-start': '1', 'data-nr-chat-page-module': '1'}));
        await settle();

        expect(showModule).toHaveBeenCalledWith('web_layout');
        expect(panel.chat.handleNewConversation).toHaveBeenCalledWith(null);
        // Already a column and open: nothing is moved or toggled shut.
        expect(panel.setLayout).not.toHaveBeenCalled();
        expect(panel.toggle).not.toHaveBeenCalled();
        expect(panel.expand).not.toHaveBeenCalled();
    });

    test('an action without a page leaves the page module and the user\'s placement alone, and only prefills the input', async () => {
        const panel = fakePanel();
        const showModule = fakeModuleMenu();
        click(link({'data-nr-chat-start': '1', 'data-nr-chat-prefill': 'I would like to create a new page.'}));
        await settle();

        expect(showModule).not.toHaveBeenCalled();
        expect(panel.setLayout).not.toHaveBeenCalled();
        expect(panel.chat.handleNewConversation).toHaveBeenCalledWith(null);
        expect(panel.chat.inputValue).toBe('I would like to create a new page.');
        expect(panel.chat.hasInput).toBe(true);
        // Put into the input and focused there, never sent.
        expect(panel.calls).toEqual(['expand', 'new', 'focus']);
        expect(panel.chat.handleSend).toBeUndefined();
    });

    test('no prefill when the conversation could not be created', async () => {
        const panel = fakePanel({uid: null});
        click(link({'data-nr-chat-start': '1', 'data-nr-chat-prefill': 'Hello'}));
        await settle();

        expect(panel.chat.inputValue).toBe('');
        expect(panel.onFocusInput).not.toHaveBeenCalled();
    });

    test('a second click while the first start runs starts nothing more', async () => {
        const panel = fakePanel();
        let release;
        panel.chat.ready = new Promise((resolve) => { release = resolve; });
        const a = link({'data-nr-chat-start': '1', 'data-nr-chat-skill': 's'});
        click(a);
        click(a);
        release();
        await settle();

        expect(panel.chat.handleNewConversation).toHaveBeenCalledTimes(1);

        // Once it is done, the next click starts again.
        click(a);
        await settle();
        expect(panel.chat.handleNewConversation).toHaveBeenCalledTimes(2);
    });

    test.each([
        ['Ctrl', {ctrlKey: true}],
        ['Cmd', {metaKey: true}],
        ['Shift', {shiftKey: true}],
        ['Alt', {altKey: true}],
        ['middle button', {button: 1}],
    ])('a %s click follows the link the way the browser does', async (_name, init) => {
        const panel = fakePanel();
        const event = click(link({'data-nr-chat-start': '1', 'data-nr-chat-skill': 's'}), init);
        await settle();

        expect(event.defaultPrevented).toBe(false);
        expect(panel.chat.handleNewConversation).not.toHaveBeenCalled();
    });

    test('without the panel the link to the chat module is followed', () => {
        jest.spyOn(document, 'querySelector').mockImplementation(() => null);
        const event = click(link({'data-nr-chat-start': '1', 'data-nr-chat-skill': 's'}));

        expect(event.defaultPrevented).toBe(false);
    });

    test('a link without the start attribute is not taken over', () => {
        const panel = fakePanel();
        const event = click(link({'data-nr-chat-skill': 's'}));

        expect(event.defaultPrevented).toBe(false);
        expect(panel.chat.handleNewConversation).not.toHaveBeenCalled();
    });

    test('a click on the chevron inside the row starts the row', async () => {
        const panel = fakePanel();
        const a = link({'data-nr-chat-start': '1', 'data-nr-chat-skill': 's'});
        const chevron = document.createElement('span');
        a.append(chevron);
        click(chevron);
        await settle();

        expect(panel.chat.handleNewConversation).toHaveBeenCalledWith({skill: 's'});
    });

    test.each([
        [{'data-nr-chat-page': 'abc'}, {start: {}, pageModule: false, prefill: ''}],
        [{'data-nr-chat-page': '0', 'data-nr-chat-language': '2'}, {start: {}, pageModule: false, prefill: ''}],
        [{'data-nr-chat-page': '5'}, {start: {pageUid: 5}, pageModule: true, prefill: ''}],
        [{'data-nr-chat-language': '2'}, {start: {}, pageModule: false, prefill: ''}],
        [{'data-nr-chat-page-module': '0'}, {start: {}, pageModule: false, prefill: ''}],
        [{'data-nr-chat-page-module': '1'}, {start: {}, pageModule: true, prefill: ''}],
        [{'data-nr-chat-skill': '  ', 'data-nr-chat-prefill': '  '}, {start: {}, pageModule: false, prefill: ''}],
    ])('reads %j as %j', (attrs, expected) => {
        expect(startOf(link(attrs))).toEqual(expected);
    });

    test('where the module menu is out of reach the chat still opens', async () => {
        const panel = fakePanel();
        await startFromLink(panel, link({'data-nr-chat-start': '1', 'data-nr-chat-page': '4'}));

        expect(panel.chat.handleNewConversation).toHaveBeenCalledWith({pageUid: 4});
    });
});
