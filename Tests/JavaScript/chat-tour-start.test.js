// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Starting a guided process anew (ADR-023): the page choice of a process
 * without a page, the answers at its end, and the prompt when the page module
 * shows another page. Every new start is offered only where the server says
 * nr-llm can start one (`tourStart`); "Fertig" and staying with the page do
 * not depend on it.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const TOUR = {pageUid: 20, languageUid: 0, pageTitle: 'Über uns', languageName: 'Deutsch'};
const START = {skill: '3:seo/page-tour', choosePage: false};

async function settle(el) {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await el.updateComplete;
}

async function render(modulePath, tag, open, overrides = {}) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'SEO', status: 'idle', messageCount: 2, pinned: false}],
        messages: [],
        status: 'idle',
        errorMessage: '',
        skill: {identifier: '3:seo/page-tour', name: 'SEO einer Seite'},
        tour: null,
        tourStart: null,
        guided: {progress: null, highlight: null},
        backendPage: {pageId: 0},
        ...overrides,
    });
    // The page module's page is set by the test, not read from the window.
    el.chat.syncPageWatch = jest.fn();
    el.chat._api.sendMessage = jest.fn().mockResolvedValue({status: 'processing'});
    el.chat._api.createConversation = jest.fn().mockResolvedValue({uid: 2});
    el.chat._api.endTour = jest.fn().mockResolvedValue({status: 'idle', skill: null});
    el.chat.loadConversations = jest.fn().mockResolvedValue(undefined);
    el.chat.selectConversation = jest.fn().mockResolvedValue(undefined);
    el.chat.startPollingIfNeeded = jest.fn();
    if (open) el.state = 'OPEN';
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

function action(el, name) {
    return el.shadowRoot.querySelector(`[data-action="${name}"]`);
}

describe.each(SURFACES)('$name page choice', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('without a new start from the server there is no choice', async () => {
        const el = await render(modulePath, tag, open);

        expect(el.shadowRoot.querySelector('.guided-choice')).toBeNull();
    });

    test('a process without a page asks for one, as a named group', async () => {
        const el = await render(modulePath, tag, open, {tourStart: {...START, choosePage: true}});
        const group = el.shadowRoot.querySelector('.guided-choice');

        expect(group.getAttribute('role')).toBe('group');
        expect(el.shadowRoot.getElementById(group.getAttribute('aria-labelledby')).textContent).toBe('guided.choosePage');
        expect(action(el, 'check-page').disabled).toBe(true);
    });

    test('with a page in the page module, "Diese Seite prüfen" sends the turn', async () => {
        const el = await render(modulePath, tag, open, {tourStart: {...START, choosePage: true}, backendPage: {pageId: 20}});
        const button = action(el, 'check-page');

        expect(button.disabled).toBe(false);
        button.click();
        await settle(el);

        expect(el.chat._api.sendMessage).toHaveBeenCalledWith(1, 'guided.checkPage', null, expect.any(Object));
    });

    test('a process with a page asks for none', async () => {
        const el = await render(modulePath, tag, open, {tourStart: START, tour: TOUR, backendPage: {pageId: 20}});

        expect(el.shadowRoot.querySelector('.guided-choice')).toBeNull();
    });
});

describe.each(SURFACES)('$name end of a process', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    const completed = {progress: {label: 'SEO', current: 5, total: 5, completed: true}, highlight: null};

    test('nothing while the process runs', async () => {
        const el = await render(modulePath, tag, open, {tour: TOUR, tourStart: START, guided: {progress: {label: 'SEO', current: 2, total: 5, completed: false}, highlight: null}});

        expect(el.shadowRoot.querySelector('.guided-end-actions')).toBeNull();
    });

    test('"Fertig" ends the process; the dashboard is linked where it exists', async () => {
        const el = await render(modulePath, tag, open, {tour: TOUR, guided: completed, dashboardUrl: '/typo3/module/dashboard'});
        const link = action(el, 'dashboard');

        expect(link.getAttribute('href')).toBe('/typo3/module/dashboard');
        expect(link.getAttribute('target')).toBe('_top');
        expect(action(el, 'choose-another-page')).toBeNull();
        action(el, 'finish-tour').click();
        await settle(el);

        expect(el.chat._api.endTour).toHaveBeenCalledWith(1);
    });

    test('without a dashboard there is no link', async () => {
        const el = await render(modulePath, tag, open, {tour: TOUR, guided: completed, dashboardUrl: ''});

        expect(action(el, 'finish-tour')).not.toBeNull();
        expect(action(el, 'dashboard')).toBeNull();
    });

    test('"Andere Seite wählen" starts the process in a new conversation that asks for a page', async () => {
        const el = await render(modulePath, tag, open, {tour: TOUR, tourStart: START, guided: completed});
        action(el, 'choose-another-page').click();
        await settle(el);

        expect(el.chat._api.createConversation).toHaveBeenCalledWith({skill: '3:seo/page-tour'});
        expect(el.chat.selectConversation).toHaveBeenCalledWith(2);
        expect(el.chat._api.endTour).not.toHaveBeenCalled();
    });
});

describe.each(SURFACES)('$name another page in the page module', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('the tour\'s own page prompts nothing, and the region is always there', async () => {
        const el = await render(modulePath, tag, open, {tour: TOUR, tourStart: START, backendPage: {pageId: 20}});
        const region = el.shadowRoot.querySelector('.guided-page-change-region');

        expect(region.getAttribute('role')).toBe('status');
        expect(region.textContent.trim()).toBe('');
    });

    test('another page is announced with both answers', async () => {
        const el = await render(modulePath, tag, open, {tour: TOUR, tourStart: START, backendPage: {pageId: 30, languageId: 1}});
        const region = el.shadowRoot.querySelector('.guided-page-change-region');

        expect(region.textContent).toContain('guided.pageChanged');
        expect(action(el, 'switch-page')).not.toBeNull();
        expect(action(el, 'keep-page').textContent.trim()).toBe('guided.keepPage');
    });

    test('staying keeps the tour and does not ask again for that page', async () => {
        const el = await render(modulePath, tag, open, {tour: TOUR, tourStart: START, backendPage: {pageId: 30}});
        action(el, 'keep-page').click();
        await settle(el);

        expect(el.shadowRoot.querySelector('.guided-page-change')).toBeNull();
        expect(el.chat._api.endTour).not.toHaveBeenCalled();
        el.chat.backendPage = {pageId: 31};
        el.requestUpdate();
        await el.updateComplete;
        expect(el.shadowRoot.querySelector('.guided-page-change')).not.toBeNull();
    });

    test('switching ends the tour and starts it on the new page and language', async () => {
        const el = await render(modulePath, tag, open, {tour: TOUR, tourStart: START, backendPage: {pageId: 30, languageId: 1}});
        action(el, 'switch-page').click();
        await settle(el);
        await settle(el);

        expect(el.chat._api.endTour).toHaveBeenCalledWith(1);
        expect(el.chat._api.createConversation).toHaveBeenCalledWith({pageUid: 30, languageUid: 1, skill: '3:seo/page-tour'});
    });

    test('a refused end starts nothing', async () => {
        const el = await render(modulePath, tag, open, {tour: TOUR, tourStart: START, backendPage: {pageId: 30}});
        el.chat._api.endTour = jest.fn().mockRejectedValue(new Error('busy'));
        action(el, 'switch-page').click();
        await settle(el);

        expect(el.chat._api.createConversation).not.toHaveBeenCalled();
        expect(el.chat.tour).toEqual(TOUR);
    });

    test('without a new start only staying is offered', async () => {
        const el = await render(modulePath, tag, open, {tour: TOUR, tourStart: null, backendPage: {pageId: 30}});

        expect(action(el, 'switch-page')).toBeNull();
        expect(action(el, 'keep-page')).not.toBeNull();
    });
});

describe('following the page module', () => {
    test('reads the page while it matters, and stops after', async () => {
        jest.useFakeTimers();
        try {
            const {ChatCoreController} = await import('../../Resources/Public/JavaScript/chat-core.js');
            const host = {requestUpdate: jest.fn(), addController: jest.fn()};
            const chat = new ChatCoreController(host);
            let page = 20;
            const read = jest.fn(() => ({pageId: page, languageId: 0}));

            chat.syncPageWatch(read);
            expect(read).not.toHaveBeenCalled();

            chat.tour = TOUR;
            chat.syncPageWatch(read);
            expect(chat.backendPage).toEqual({pageId: 20, languageId: 0});
            page = 30;
            jest.advanceTimersByTime(1000);
            expect(chat.backendPage).toEqual({pageId: 30, languageId: 0});
            expect(host.requestUpdate).toHaveBeenCalled();

            chat.tour = null;
            chat.syncPageWatch(read);
            const calls = read.mock.calls.length;
            jest.advanceTimersByTime(5000);
            expect(read.mock.calls.length).toBe(calls);
        } finally {
            jest.useRealTimers();
        }
    });

    test('the panel stops following when it leaves the page', async () => {
        document.body.replaceChildren();
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
        const el = document.createElement('ai-chat-panel');
        document.body.append(el);
        el.chat.stopPageWatch = jest.fn();
        el.remove();

        expect(el.chat.stopPageWatch).toHaveBeenCalled();
    });
});
