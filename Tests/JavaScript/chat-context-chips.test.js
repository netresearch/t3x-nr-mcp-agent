// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The row of chips above the chat input (NEXT-221): the page of the chat in
 * the page module, the page and element of a guided process step, the (i)
 * that says what the assistant may change, and the element chip that marks
 * its element in the page module beside the panel. Both surfaces render the
 * same row; only the panel has the page module beside it.
 */

import {describe, test, expect, beforeEach, afterEach, jest} from '@jest/globals';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';

/** The German labels of the chat as the reader gets them (de.locallang_chat.xlf). */
function germanLabels() {
    const xml = readFileSync(join(import.meta.dirname, '../../Resources/Private/Language/de.locallang_chat.xlf'), 'utf8');
    const labels = {};
    for (const unit of xml.matchAll(/<trans-unit id="([^"]+)"[^>]*>[\s\S]*?<target>([\s\S]*?)<\/target>/g)) {
        labels[unit[1]] = unit[2];
    }
    return labels;
}

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false, panel: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true, panel: true},
];

const TOUR = {pageUid: 20, languageUid: 0, pageTitle: 'AI Chat Agent', languageName: 'Deutsch'};
const HERO = {table: 'tt_content', uid: 100, title: 'Hero'};
const SKILL = {identifier: '3:seo/page-tour', name: 'SEO einer Seite', title: null, bindsPage: true};

/** The page module beside the chat shows page 20, whose title the server named. */
const PAGE_MODULE = {backendModule: 'web_layout', backendPage: {pageId: 20}, pageTitle: {pageId: 20, title: 'AI Chat Agent'}};

async function render(modulePath, tag, open, overrides = {}) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'Seite', status: 'idle', messageCount: 1, pinned: false}],
        messages: [{role: 'user', content: 'Hallo'}],
        status: 'idle',
        errorMessage: '',
        skill: null,
        tour: null,
        tourStart: null,
        guided: {progress: null, highlight: null},
        backendPage: {pageId: 0},
        backendModule: '',
        pageTitle: null,
        ...overrides,
    });
    // The page module's page is set by the test, not read from the window.
    el.chat.syncPageWatch = jest.fn();
    el.chat.loadPageTitle = jest.fn();
    if (open) el.state = 'OPEN';
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

const row = (el) => el.shadowRoot.querySelector('.context-chips');
const chipTexts = (el) => [...row(el).querySelectorAll('.context-chip')].map((chip) => chip.querySelector('.context-chip-text')?.textContent ?? chip.textContent);
const tooltip = (el) => el.shadowRoot.getElementById(el.shadowRoot.querySelector('.context-info').getAttribute('aria-describedby'));

beforeEach(() => {
    document.body.replaceChildren();
    globalThis.TYPO3 = {...(globalThis.TYPO3 ?? {}), lang: germanLabels()};
});

afterEach(() => {
    delete globalThis.TYPO3.lang;
    delete window.list_frame;
});

describe.each(SURFACES)('$name: when the row appears', ({module: modulePath, tag, open}) => {
    test('the chat in the page module names the selected page, and the (i) says the whole page is the scope', async () => {
        const el = await render(modulePath, tag, open, PAGE_MODULE);

        expect(row(el).getAttribute('role')).toBe('group');
        expect(row(el).getAttribute('aria-label')).toBe('Woran der Assistant arbeitet');
        expect(chipTexts(el)).toEqual(['AI Chat Agent']);
        expect(row(el).querySelector('.context-chip.page').getAttribute('title')).toBe('AI Chat Agent');
        expect(row(el).querySelector('.context-chip.page').textContent).toBe('Seite: AI Chat Agent');
        expect(tooltip(el).textContent).toBe('Bereich: ganze Seite „AI Chat Agent“. Geändert wird nur nach deiner Bestätigung.');
    });

    test.each([
        ['no page is selected', {backendPage: {pageId: 0}}],
        ['another module than the page module', {backendModule: 'web_list'}],
        ['a skill that binds no page', {skill: {...SKILL, bindsPage: false}}],
        ['the title of another page (an answer for the page before)', {pageTitle: {pageId: 19, title: 'Vorher'}}],
        ['a page the server did not name', {pageTitle: {pageId: 20, title: ''}}],
    ])('no row for %s', async (_label, overrides) => {
        const el = await render(modulePath, tag, open, {...PAGE_MODULE, ...overrides});

        expect(row(el)).toBeNull();
    });

    test('a skill that works on a page keeps the row', async () => {
        const el = await render(modulePath, tag, open, {...PAGE_MODULE, skill: SKILL});

        expect(chipTexts(el)).toEqual(['AI Chat Agent']);
    });

    test('a guided step names its page and element, and the (i) says only that element changes', async () => {
        const el = await render(modulePath, tag, open, {skill: SKILL, tour: TOUR, guided: {progress: null, highlight: HERO}});

        expect(chipTexts(el)).toEqual(['AI Chat Agent', 'Hero']);
        expect(row(el).querySelector('.context-chip.element').textContent).toBe('Element: Hero');
        expect(row(el).querySelector('.context-chip.element .context-marker').getAttribute('aria-hidden')).toBe('true');
        expect(tooltip(el).textContent).toBe('Der Assistant ändert nur das Element „Hero“ auf der Seite „AI Chat Agent“. Andere Inhalte bleiben unberührt.');
    });

    test('a step without an element, or with one the server did not name, shows the page only', async () => {
        const el = await render(modulePath, tag, open, {skill: SKILL, tour: TOUR, guided: {progress: null, highlight: null}});
        expect(chipTexts(el)).toEqual(['AI Chat Agent']);

        el.chat.guided = {progress: null, highlight: {table: 'tt_content', uid: 100, title: ''}};
        el.requestUpdate();
        await el.updateComplete;
        expect(chipTexts(el)).toEqual(['AI Chat Agent']);
        expect(tooltip(el).textContent).toBe('Bereich: ganze Seite „AI Chat Agent“. Geändert wird nur nach deiner Bestätigung.');
    });

    test('the element chip follows the step', async () => {
        const el = await render(modulePath, tag, open, {skill: SKILL, tour: TOUR, guided: {progress: null, highlight: HERO}});

        el.chat.guided = {progress: null, highlight: {table: 'tt_content', uid: 101, title: 'Teaser'}};
        el.requestUpdate();
        await el.updateComplete;

        expect(chipTexts(el)).toEqual(['AI Chat Agent', 'Teaser']);
    });

    test('a process stays with its page while the page tree moves on (ADR-023)', async () => {
        const el = await render(modulePath, tag, open, {...PAGE_MODULE, backendPage: {pageId: 30}, pageTitle: {pageId: 30, title: 'Kontakt'}, skill: SKILL, tour: TOUR});

        expect(chipTexts(el)).toEqual(['AI Chat Agent']);
    });

    test('a conversation about a missing alt text shows its image in the same, single row', async () => {
        const card = {role: 'assistant', content: '[x]', notice: 'altTextHit', data: {fileName: 'team.jpg', subjectType: '', subject: 'Startseite', partType: 'element', part: 'Team', reference: 1}};
        const el = await render(modulePath, tag, open, {...PAGE_MODULE, altText: {reference: 1}, messages: [card]});

        expect(el.shadowRoot.querySelectorAll('.context-chips')).toHaveLength(1);
        expect([...row(el).querySelectorAll('.alt-text-chip')].map((chip) => chip.textContent)).toEqual(['Startseite', 'team.jpg · Team']);
        expect(row(el).querySelector('.context-info')).toBeNull();
    });

    test('Escape hides the tooltip until the focus leaves', async () => {
        const el = await render(modulePath, tag, open, PAGE_MODULE);
        const wrap = row(el).querySelector('.context-info-wrap');

        wrap.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        expect(wrap.hasAttribute('data-dismissed')).toBe(true);

        wrap.dispatchEvent(new FocusEvent('focusout', {relatedTarget: null}));
        expect(wrap.hasAttribute('data-dismissed')).toBe(false);
    });
});

describe('the tooltip and the panel\'s Escape', () => {
    test('the first Escape hides the tooltip and leaves the panel open, the next one collapses it', async () => {
        const el = await render('../../Resources/Public/JavaScript/ai-chat-panel.js', 'ai-chat-panel', true, PAGE_MODULE);
        const info = row(el).querySelector('.context-info');
        info.focus();

        info.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, composed: true}));
        await el.updateComplete;
        expect(row(el).querySelector('.context-info-wrap').hasAttribute('data-dismissed')).toBe(true);
        expect(el.state).toBe('OPEN');

        info.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, composed: true}));
        expect(el.state).not.toBe('OPEN');
    });

    test('while the pointer shows it, Escape hides it wherever the focus is, and only then', async () => {
        const el = await render('../../Resources/Public/JavaScript/chat-app.js', 'nr-chat-app', false, PAGE_MODULE);
        const wrap = row(el).querySelector('.context-info-wrap');

        document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        expect(wrap.hasAttribute('data-dismissed')).toBe(false);

        wrap.dispatchEvent(new MouseEvent('mouseenter'));
        document.body.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        expect(wrap.hasAttribute('data-dismissed')).toBe(true);

        wrap.dispatchEvent(new MouseEvent('mouseleave'));
        expect(wrap.hasAttribute('data-dismissed')).toBe(false);
        document.body.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        expect(wrap.hasAttribute('data-dismissed')).toBe(false);
    });
});

describe.each(SURFACES)('$name: the element chip and the page module', ({module: modulePath, tag, open, panel}) => {
    const besidePageModule = {skill: SKILL, tour: TOUR, guided: {progress: null, highlight: HERO}, backendModule: 'web_layout', backendPage: {pageId: 20}};

    test(panel ? 'beside the page module the chip is a button that marks the element' : 'the module has no page module beside it: the chip is text', async () => {
        const postMessage = jest.fn();
        window.list_frame = {postMessage};
        const el = await render(modulePath, tag, open, besidePageModule);
        const chip = row(el).querySelector('.context-chip.element');
        postMessage.mockClear();

        if (!panel) {
            expect(chip.tagName).toBe('SPAN');
            expect(row(el).querySelector('[data-action="show-element"]')).toBeNull();
            return;
        }

        expect(chip.tagName).toBe('BUTTON');
        expect(el.shadowRoot.getElementById(chip.getAttribute('aria-describedby')).textContent).toBe(globalThis.TYPO3.lang['context.showElement']);

        chip.dispatchEvent(new MouseEvent('mouseenter'));
        chip.dispatchEvent(new FocusEvent('focus'));
        chip.click();

        const sent = {type: 'nr-mcp-agent:highlight', version: 1, table: 'tt_content', uid: 100};
        expect(postMessage.mock.calls).toEqual([
            [{...sent, scroll: false}, window.location.origin],
            [{...sent, scroll: false}, window.location.origin],
            [{...sent, scroll: true}, window.location.origin],
        ]);
    });

    test.each([
        ['no page module frame', {}, false],
        ['another module in the frame', {backendModule: 'web_list'}, true],
        ['another page in the page module', {backendPage: {pageId: 30}}, true],
    ])('with %s the chip does not pretend to mark anything', async (_label, overrides, frame) => {
        if (frame) window.list_frame = {postMessage: jest.fn()};
        const el = await render(modulePath, tag, open, {...besidePageModule, ...overrides});

        expect(row(el).querySelector('.context-chip.element').tagName).toBe('SPAN');
    });
});

describe('the chat core reads the page title', () => {
    async function core() {
        const {ChatCoreController} = await import('../../Resources/Public/JavaScript/chat-core.js');
        const host = {requestUpdate: jest.fn(), addController: jest.fn()};
        const chat = new ChatCoreController(host);
        chat._api = {getPageTitle: jest.fn()};
        chat.pageTitleWanted = true;
        return chat;
    }

    test('of the page the page module shows, and keeps the module', async () => {
        const chat = await core();
        chat._api.getPageTitle.mockResolvedValue({pageId: 20, title: 'AI Chat Agent'});

        chat.refreshBackendPage(() => ({pageId: 20, module: 'web_layout'}));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(chat.backendModule).toBe('web_layout');
        expect(chat._api.getPageTitle).toHaveBeenCalledWith(20);
        expect(chat.pageTitle).toEqual({pageId: 20, title: 'AI Chat Agent'});
    });

    test('not for a page another module shows', async () => {
        const chat = await core();

        chat.refreshBackendPage(() => ({pageId: 20, module: 'web_list'}));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(chat._api.getPageTitle).not.toHaveBeenCalled();
    });

    test('once per page, also while it is read', async () => {
        const chat = await core();
        chat._api.getPageTitle.mockResolvedValue({pageId: 20, title: 'AI Chat Agent'});

        chat.refreshBackendPage(() => ({pageId: 20, module: 'web_layout'}));
        chat.refreshBackendPage(() => ({pageId: 20, module: 'web_layout'}));
        await new Promise((resolve) => setTimeout(resolve, 0));
        chat.refreshBackendPage(() => ({pageId: 20, module: 'web_layout'}));

        expect(chat._api.getPageTitle).toHaveBeenCalledTimes(1);
    });

    test('an answer that arrives after the page module moved on is dropped', async () => {
        const chat = await core();
        let answer;
        chat._api.getPageTitle.mockImplementation((pageId) => (pageId === 20
            ? new Promise((resolve) => { answer = resolve; })
            : Promise.resolve({pageId, title: 'Kontakt'})));

        chat.refreshBackendPage(() => ({pageId: 20, module: 'web_layout'}));
        chat.refreshBackendPage(() => ({pageId: 30, module: 'web_layout'}));
        await new Promise((resolve) => setTimeout(resolve, 0));
        answer({pageId: 20, title: 'AI Chat Agent'});
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(chat.pageTitle).toEqual({pageId: 30, title: 'Kontakt'});
    });

    test('a page the server does not name gets no title, and a failed read none either', async () => {
        const chat = await core();
        chat._api.getPageTitle.mockResolvedValueOnce({pageId: 20, title: null}).mockRejectedValueOnce(new Error('HTTP 500'));

        chat.refreshBackendPage(() => ({pageId: 20, module: 'web_layout'}));
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(chat.pageTitle).toEqual({pageId: 20, title: ''});

        chat.refreshBackendPage(() => ({pageId: 30, module: 'web_layout'}));
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(chat.pageTitle).toEqual({pageId: 20, title: ''});
    });

    test('nothing is read while the chat is closed, and the page is read when it opens', async () => {
        const chat = await core();
        chat.pageTitleWanted = false;
        chat._api.getPageTitle.mockResolvedValue({pageId: 20, title: 'AI Chat Agent'});

        chat.refreshBackendPage(() => ({pageId: 20, module: 'web_layout'}));
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(chat._api.getPageTitle).not.toHaveBeenCalled();

        chat.pageTitleWanted = true;
        await chat.loadPageTitle();
        expect(chat.pageTitle).toEqual({pageId: 20, title: 'AI Chat Agent'});
    });

    test('the panel wants the title while its input shows, and reads it when it opens', async () => {
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
        const el = document.createElement('ai-chat-panel');
        document.body.append(el);
        el.chat.syncPageWatch = jest.fn();
        const load = jest.spyOn(el.chat, 'loadPageTitle').mockResolvedValue(undefined);
        el.hide();
        await el.updateComplete;
        expect(el.chat.pageTitleWanted).toBe(false);
        load.mockClear();

        el.toggle();
        await el.updateComplete;

        expect(el.chat.pageTitleWanted).toBe(true);
        expect(load).toHaveBeenCalledTimes(1);
        el.chat.stopPolling?.();
        el.remove();
    });

    test('the panel reads where it is when it connects', async () => {
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
        const el = document.createElement('ai-chat-panel');
        const refresh = jest.spyOn(el.chat, 'refreshBackendPage').mockImplementation(() => {});

        document.body.append(el);

        expect(refresh).toHaveBeenCalled();
        el.remove();
    });
});
