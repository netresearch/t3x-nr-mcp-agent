// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The guided process beside the conversation (ADR-020): the progress in the
 * header, and the highlight message between the chat panel and the page
 * module.
 *
 * The two ends of the message are tested apart, each against the contract:
 * jsdom has no second frame, so the real cross-frame delivery (the panel in
 * the backend window, the page module in `list_frame`) is not proven here and
 * needs a browser run against a backend.
 */

import {describe, test, expect, beforeEach, jest} from '@jest/globals';
import {handleHighlightMessage, highlightContentElement, HIGHLIGHT_MESSAGE} from '../../Resources/Public/JavaScript/page-highlight.js';
import {sendHighlight} from '../../Resources/Public/JavaScript/chat-guided.js';

const ORIGIN = 'https://backend.example';

/** What the page module renders for two content elements. */
function pageModuleDocument() {
    const doc = document.implementation.createHTMLDocument('page module');
    for (const [uid, text] of [[100, 'Wer wir sind'], [101, 'Team']]) {
        const element = doc.createElement('div');
        element.id = `element-tt_content-${uid}`;
        element.dataset.uid = String(uid);
        element.textContent = text;
        doc.body.append(element);
    }
    return doc;
}

/** A page-module frame: its own document, a parent, an origin. */
function frame() {
    return {parent: {}, location: {origin: ORIGIN}, document: pageModuleDocument()};
}

function message(win, data, overrides = {}) {
    return {origin: ORIGIN, source: win.parent, data, ...overrides};
}

const VALID = {type: HIGHLIGHT_MESSAGE, version: 1, table: 'tt_content', uid: 100};

describe('the page module receives a highlight', () => {
    test('the element TYPO3 renders for the uid is marked and scrolled to', () => {
        const win = frame();
        const element = win.document.getElementById('element-tt_content-100');
        element.scrollIntoView = jest.fn();

        expect(handleHighlightMessage(message(win, VALID), win)).toBe(true);

        expect(element.classList.contains('nr-mcp-agent-highlight')).toBe(true);
        expect(element.scrollIntoView).toHaveBeenCalledTimes(1);
        expect(win.document.getElementById('nr-mcp-agent-highlight-style')).not.toBeNull();
    });

    test('a second highlight moves the mark', () => {
        const doc = pageModuleDocument();

        highlightContentElement(doc, 100);
        highlightContentElement(doc, 101);

        expect([...doc.querySelectorAll('.nr-mcp-agent-highlight')].map((el) => el.id)).toEqual(['element-tt_content-101']);
        expect(doc.querySelectorAll('#nr-mcp-agent-highlight-style')).toHaveLength(1);
    });

    test('an element the page module does not show clears the mark and reports false', () => {
        const doc = pageModuleDocument();
        highlightContentElement(doc, 100);

        expect(highlightContentElement(doc, 999)).toBe(false);
        expect(doc.querySelectorAll('.nr-mcp-agent-highlight')).toHaveLength(0);
    });

    test.each([
        ['another origin', {}, {origin: 'https://evil.example'}],
        ['a sender that is not the backend window', {}, {source: {}}],
        ['another message type', {type: 'other'}, {}],
        ['another contract version', {version: 2}, {}],
        ['another table', {table: 'be_users'}, {}],
        ['a uid as text', {uid: '100'}, {}],
        ['a uid of 0', {uid: 0}, {}],
        ['a selector instead of a uid', {uid: undefined, selector: '#element-tt_content-100'}, {}],
    ])('%s is ignored', (_label, data, event) => {
        const win = frame();

        expect(handleHighlightMessage(message(win, {...VALID, ...data}, event), win)).toBe(false);
        expect(win.document.querySelectorAll('.nr-mcp-agent-highlight')).toHaveLength(0);
    });

    test('a window that is its own parent ignores the message', () => {
        const win = frame();
        win.parent = win;

        expect(handleHighlightMessage({origin: ORIGIN, source: win, data: VALID}, win)).toBe(false);
    });
});

describe('the chat panel sends the highlight', () => {
    let win;
    let chat;

    beforeEach(() => {
        win = {location: {origin: ORIGIN}, frames: {list_frame: {postMessage: jest.fn()}}};
        chat = {activeUid: 1, guided: {progress: null, highlight: {table: 'tt_content', uid: 100}}, _sentHighlight: ''};
    });

    test('to the module frame, same origin only, in the contract shape', () => {
        expect(sendHighlight(chat, win)).toBe(true);

        expect(win.frames.list_frame.postMessage).toHaveBeenCalledWith(VALID, ORIGIN);
    });

    test('once per element, again for the next one', () => {
        sendHighlight(chat, win);
        sendHighlight(chat, win);
        chat.guided = {...chat.guided, highlight: {table: 'tt_content', uid: 101}};
        sendHighlight(chat, win);

        expect(win.frames.list_frame.postMessage).toHaveBeenCalledTimes(2);
    });

    test('nothing without a highlight', () => {
        chat.guided = {progress: null, highlight: null};

        expect(sendHighlight(chat, win)).toBe(false);
        expect(win.frames.list_frame.postMessage).not.toHaveBeenCalled();
    });

    test('nothing where no module frame is beside the panel, and it is tried again later', () => {
        delete win.frames.list_frame;

        expect(sendHighlight(chat, win)).toBe(false);

        win.frames.list_frame = {postMessage: jest.fn()};
        expect(sendHighlight(chat, win)).toBe(true);
    });
});

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

async function renderSurface(modulePath, tag, open, guided) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'Seite prüfen', status: 'idle', messageCount: 1, pinned: false}],
        messages: [{role: 'user', content: 'Prüfe die Seite'}],
        status: 'idle',
        guided,
    });
    if (open) {
        el.state = 'OPEN';
    }
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

describe.each(SURFACES)('$name header', ({module: modulePath, tag, open}) => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    test('shows the label and the point as a status', async () => {
        const el = await renderSurface(modulePath, tag, open, {progress: {label: 'Über uns · Deutsch', current: 2, total: 5}, highlight: null});
        const progress = el.shadowRoot.querySelector('.guided-progress');

        expect(progress.getAttribute('role')).toBe('status');
        // The mocked lll() returns the key; the label comes from the run.
        expect(progress.textContent.replace(/\s+/g, ' ').trim()).toBe('Über uns · Deutsch · guided.progress');
    });

    test('shows nothing without a guided process', async () => {
        const el = await renderSurface(modulePath, tag, open, {progress: null, highlight: null});

        expect(el.shadowRoot.querySelector('.guided-progress')).toBeNull();
    });
});

describe('the panel posts the highlight when it renders', () => {
    test('to the module frame beside it, the full-page module does not', async () => {
        const postMessage = jest.fn();
        // In jsdom window.frames is the window, so this is the named frame.
        window.list_frame = {postMessage};
        try {
            const guided = {progress: null, highlight: {table: 'tt_content', uid: 100}};
            await renderSurface('../../Resources/Public/JavaScript/chat-app.js', 'nr-chat-app', false, guided);
            expect(postMessage).not.toHaveBeenCalled();

            await renderSurface('../../Resources/Public/JavaScript/ai-chat-panel.js', 'ai-chat-panel', true, guided);
            expect(postMessage).toHaveBeenCalledWith(VALID, window.location.origin);
        } finally {
            delete window.list_frame;
        }
    });
});

describe('the chat core keeps the guided state of the poll', () => {
    test('takes it from the messages response', async () => {
        const {ChatCoreController} = await import('../../Resources/Public/JavaScript/chat-core.js');
        const host = {addController: () => {}, requestUpdate: () => {}, onScrollToBottom: () => {}};
        const chat = new ChatCoreController(host);
        const guided = {progress: {label: 'Über uns · Deutsch', current: 1, total: 3}, highlight: {table: 'tt_content', uid: 100}};
        chat._api = {getMessages: jest.fn().mockResolvedValue({messages: [], totalCount: 0, status: 'idle', guided})};
        chat.activeUid = 1;

        await chat.loadMessages();

        expect(chat.guided).toEqual(guided);
    });
});
