// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Every list of conversations shows the same (ADR-027): the title, a line
 * with the context, the status in words and when the last message was
 * written — the chat module's list, the panel's sidebar and its "more" list.
 * The tabs show the status icon and the title and carry the rest in their
 * tooltip; the minimized bar names the open conversation the same way.
 *
 * On the demo, 20 of 49 conversations were called "Diese Seite prüfen" and
 * every list showed nothing but that title and the status.
 */

import {describe, test, expect, beforeEach, afterEach, jest} from '@jest/globals';

/**
 * "Now" for the times the lists word (relative-time.js), in the test's own
 * time zone: Saturday 24 October 2026, noon. Only Date is faked; Lit's
 * microtasks and the timers stay real.
 */
const NOW = new Date(2026, 9, 24, 12, 0);
const local = (day, hour, minute) => new Date(2026, 9, day, hour, minute).toISOString();

beforeEach(() => {
    jest.useFakeTimers({
        now: NOW,
        doNotFake: ['hrtime', 'nextTick', 'performance', 'queueMicrotask', 'requestAnimationFrame', 'cancelAnimationFrame', 'requestIdleCallback', 'cancelIdleCallback', 'setImmediate', 'clearImmediate', 'setInterval', 'clearInterval', 'setTimeout', 'clearTimeout'],
    });
    globalThis.TYPO3 = {...globalThis.TYPO3, lang: {'widget.time.today': 'Heute, %s', 'widget.time.yesterday': 'Gestern, %s'}};
});

afterEach(() => {
    jest.useRealTimers();
    delete globalThis.TYPO3.lang;
});

const TOUR = {
    uid: 7,
    title: 'Diese Seite prüfen',
    displayTitle: 'SEO optimieren · Über uns',
    fullTitle: 'SEO optimieren · Über uns',
    contextLabel: 'Guided Tour · Punkt 2 von 5',
    status: 'awaiting_input',
    messageCount: 4,
    pinned: false,
    tstamp: 1,
    lastActivityAt: local(24, 10, 42),
};

const OWN = {
    uid: 8,
    title: 'Welche Seiten haben keine Meta-Beschreibung und wie viele sind es insgesamt?',
    displayTitle: 'Welche Seiten haben keine Meta-Beschreibung und wie viele…',
    fullTitle: 'Welche Seiten haben keine Meta-Beschreibung und wie viele sind es insgesamt?',
    contextLabel: '',
    status: 'idle',
    messageCount: 2,
    pinned: true,
    tstamp: 2,
    lastActivityAt: local(23, 9, 0),
};

/** A conversation just created: no message, no title. */
const EMPTY = {uid: 9, title: '', displayTitle: '', status: 'idle', messageCount: 0, pinned: false, tstamp: 3};

async function render(modulePath, tag, state, chatState = {}) {
    await import(modulePath);
    document.body.replaceChildren();
    localStorage.clear();
    const el = document.createElement(tag);
    document.body.append(el);
    // Let the controller's own init() fail on the missing fetch and settle
    // before the state under test is put in place.
    await new Promise(r => setTimeout(r, 0));
    Object.assign(el.chat, {
        activeUid: 7,
        available: true,
        loading: false,
        issues: [],
        conversations: [TOUR, OWN, EMPTY],
        listLocale: 'de',
        status: 'awaiting_input',
        messages: [],
        ...chatState,
    });
    el.chat.selectConversation = jest.fn();
    if (state) {
        el.state = state;
    }
    el.requestUpdate();
    await el.updateComplete;

    return el;
}

/** What one row shows, read from the shared summary. */
function row(item) {
    return {
        title: item.querySelector('.conv-summary-title').textContent.trim(),
        tooltip: item.querySelector('.conv-summary-title').getAttribute('title'),
        context: item.querySelector('.conv-summary-context')?.textContent.trim() ?? '',
        status: item.querySelector('.conv-status').textContent.trim(),
        tone: item.querySelector('.conv-status').dataset.tone,
        time: item.querySelector('time')?.textContent.trim() ?? '',
        datetime: item.querySelector('time')?.getAttribute('datetime') ?? '',
        absolute: item.querySelector('time')?.getAttribute('title') ?? '',
    };
}

const TOUR_ROW = {
    title: 'SEO optimieren · Über uns',
    tooltip: 'SEO optimieren · Über uns',
    context: 'Guided Tour · Punkt 2 von 5',
    status: 'status.awaitingInput',
    tone: 'warning',
    time: 'Heute, 10:42',
    datetime: TOUR.lastActivityAt,
    absolute: expect.stringMatching(/^24\. Oktober 2026.*10:42$/),
};

describe('the chat module list', () => {
    beforeEach(() => document.body.replaceChildren());

    test('shows title, context, status and time; pinned first; no empty chat', async () => {
        const el = await render('../../Resources/Public/JavaScript/chat-app.js', 'nr-chat-app');
        const items = [...el.shadowRoot.querySelectorAll('.conversation-item')];

        expect(items).toHaveLength(2);
        expect(row(items[0]).title).toBe('\u{1F4CC} Welche Seiten haben keine Meta-Beschreibung und wie viele…');
        expect(row(items[0]).tooltip).toBe(OWN.fullTitle);
        expect(row(items[0]).context).toBe('');
        expect(row(items[0]).tone).toBe('neutral');
        expect(row(items[1])).toEqual(TOUR_ROW);
    });

    test('lists an empty chat while it is the open one', async () => {
        const el = await render('../../Resources/Public/JavaScript/chat-app.js', 'nr-chat-app', null, {activeUid: 9});
        const titles = [...el.shadowRoot.querySelectorAll('.conversation-item .conv-summary-title')].map(n => n.textContent.trim());

        expect(titles).toContain('conversations.newConversation');
        expect(titles).toHaveLength(3);
    });

    test('names the open conversation by its display title', async () => {
        const el = await render('../../Resources/Public/JavaScript/chat-app.js', 'nr-chat-app');

        expect(el.shadowRoot.querySelector('.main-header').textContent).toContain('SEO optimieren · Über uns');
        expect(el.shadowRoot.querySelector('.main-header').textContent).not.toContain('Diese Seite prüfen');
    });
});

describe('the panel', () => {
    beforeEach(() => document.body.replaceChildren());

    test('the maximized sidebar shows the same rows', async () => {
        const el = await render('../../Resources/Public/JavaScript/ai-chat-panel.js', 'ai-chat-panel', 'maximized');
        const items = [...el.shadowRoot.querySelectorAll('.sidebar-item')];

        expect(items).toHaveLength(2);
        expect(row(items[1])).toEqual(TOUR_ROW);
    });

    test('a tab shows the status icon and the title, the rest in its tooltip', async () => {
        const el = await render('../../Resources/Public/JavaScript/ai-chat-panel.js', 'ai-chat-panel', 'expanded');
        const tabs = [...el.shadowRoot.querySelectorAll('.conv-tablist [role="tab"]')];
        const tour = tabs.find(t => t.textContent.includes('Über uns'));

        expect(tabs).toHaveLength(2);
        expect(tour.querySelector('.tab-title').textContent.trim()).toBe('SEO optimieren · Über uns');
        expect(tour.querySelector('.conv-status-icon').dataset.tone).toBe('warning');
        expect(tour.getAttribute('title')).toMatch(/^SEO optimieren · Über uns\nGuided Tour · Punkt 2 von 5\nstatus\.awaitingInput\n24\. Oktober 2026.*10:42$/);
        // Keyboard, touch and screen readers get the context and the time too.
        const description = el.shadowRoot.getElementById(tour.getAttribute('aria-describedby'));
        expect(description.textContent).toBe('Guided Tour · Punkt 2 von 5, Heute, 10:42');
        // Hidden, so browse mode does not read it twice; aria-describedby still resolves it.
        expect(description.closest('[hidden]')).not.toBeNull();
        expect(description.closest('[role="tablist"]')).toBeNull();
    });

    test('the "more" list shows the same rows and searches the context', async () => {
        // Newer than the tour, so the tour is one of the three in the list.
        const many = Array.from({length: 6}, (_, i) => ({...OWN, uid: 20 + i, pinned: false, lastActivityAt: local(24, 11, i), displayTitle: `Frage ${i}`, fullTitle: `Frage ${i}`, contextLabel: `Seite „Seite ${i}“`}));
        const el = await render('../../Resources/Public/JavaScript/ai-chat-panel.js', 'ai-chat-panel', 'expanded', {
            conversations: [...many, TOUR, EMPTY],
            activeUid: 20,
        });
        const root = el.shadowRoot;

        // Seven conversations with messages, four tabs: three in the list.
        expect(root.querySelector('.conv-tab-more').textContent.trim()).toBe('conversations.more');
        root.querySelector('.conv-tab-more').click();
        await el.updateComplete;
        const options = [...root.querySelectorAll('#conv-more-list [role="option"]')];
        expect(options).toHaveLength(3);
        expect(options.map(o => o.textContent).join(' ')).not.toContain('conversations.newConversation');
        const tour = options.find(o => o.textContent.includes('Über uns'));
        expect(row(tour)).toEqual(TOUR_ROW);

        const input = root.querySelector('.conv-more-search');
        input.value = 'Guided';
        input.dispatchEvent(new Event('input'));
        await el.updateComplete;
        const found = [...root.querySelectorAll('#conv-more-list [role="option"] .conv-summary-title')].map(n => n.textContent.trim());
        expect(found).toEqual(['SEO optimieren · Über uns']);
    });

    test('the minimized bar names the open conversation with its context in the tooltip', async () => {
        const el = await render('../../Resources/Public/JavaScript/ai-chat-panel.js', 'ai-chat-panel', null);
        el.layout = 'column';
        el.narrow = false;
        el.state = 'collapsed';
        el.requestUpdate();
        await el.updateComplete;
        const bar = el.shadowRoot.querySelector('.minimized-bar');

        expect(bar).not.toBeNull();
        expect(bar.querySelector('.minimized-title').textContent.trim()).toBe('SEO optimieren · Über uns');
        expect(bar.querySelector('.minimized-text').getAttribute('title')).toContain('Guided Tour · Punkt 2 von 5');
        expect(bar.querySelector('.status-dot')).not.toBeNull();
    });
});

describe('keeping the lists in step', () => {
    async function controller(conversations) {
        const {ChatCoreController} = await import('../../Resources/Public/JavaScript/chat-core.js');
        const host = {requestUpdate: jest.fn(), addController: jest.fn(), onResetInput: jest.fn(), onScrollToBottom: jest.fn()};
        const chat = new ChatCoreController(host);
        Object.assign(chat, {conversations, activeUid: conversations[0].uid, messages: [], status: 'idle', available: true});
        chat.startPollingIfNeeded = jest.fn();
        chat._api = {
            renameConversation: jest.fn().mockResolvedValue({}),
            sendMessage: jest.fn().mockResolvedValue({status: 'processing'}),
            listConversations: jest.fn().mockResolvedValue({conversations}),
        };
        return chat;
    }

    /**
     * The rename field starts with the title the list shows. Leaving it as it
     * was must not store a tour's derived "<skill> · <page>" as the editor's
     * own title, nor a shortened title with its "…".
     */
    test.each([
        ['the display title', 'SEO optimieren · Über uns'],
        ['the stored title', 'Diese Seite prüfen'],
    ])('leaving %s unchanged renames nothing', async (_, value) => {
        const chat = await controller([{...TOUR}]);

        await chat.handleRename(7, `  ${value} `);

        expect(chat._api.renameConversation).not.toHaveBeenCalled();
    });

    test('a rename shows the new title at once and reloads the list for its display title', async () => {
        const chat = await controller([{...TOUR}]);
        chat._api.listConversations = jest.fn().mockResolvedValue({conversations: [{...TOUR, title: 'Meine Prüfung', displayTitle: 'Meine Prüfung'}]});

        const pending = chat.handleRename(7, 'Meine Prüfung');
        await pending;

        expect(chat._api.renameConversation).toHaveBeenCalledWith(7, 'Meine Prüfung');
        expect(chat._api.listConversations).toHaveBeenCalledTimes(1);
        expect(chat.conversations[0].displayTitle).toBe('Meine Prüfung');
    });

    /** The lists word the times in the backend user's locale, which the list endpoint names. */
    test('loading the list keeps the locale the times are worded in', async () => {
        const chat = await controller([{...TOUR}]);
        chat._api.listConversations = jest.fn().mockResolvedValue({conversations: [{...TOUR}], locale: 'de'});

        await chat.loadConversations();

        expect(chat.listLocale).toBe('de');
    });

    /** Until its first message a chat is not listed, and it has no title; afterwards it has both. */
    test('the first message of a chat reloads the list; a later one does not', async () => {
        const chat = await controller([{...EMPTY}]);
        const titled = {...EMPTY, title: 'Wie viele Seiten hat die Website?', displayTitle: 'Wie viele Seiten hat die Website?', messageCount: 1};
        chat._api.listConversations = jest.fn().mockResolvedValue({conversations: [titled]});

        await chat.handleSend('Wie viele Seiten hat die Website?');
        expect(chat._api.listConversations).toHaveBeenCalledTimes(1);
        await Promise.resolve();
        expect(chat.conversations[0].displayTitle).toBe('Wie viele Seiten hat die Website?');

        chat.status = 'idle';
        chat._api.listConversations.mockClear();
        chat.conversations = [{...EMPTY, messageCount: 2}];
        await chat.handleSend('Und wie viele davon sind versteckt?');
        expect(chat._api.listConversations).not.toHaveBeenCalled();
    });
});
