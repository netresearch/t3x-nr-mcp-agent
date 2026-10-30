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

import {describe, test, expect, beforeEach, jest} from '@jest/globals';

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
    lastActivityAt: '2026-10-24T10:42:00+02:00',
    lastActivity: {label: 'Heute, 10:42', absolute: '24. Oktober 2026 um 10:42'},
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
    lastActivityAt: '2026-10-23T09:00:00+02:00',
    lastActivity: {label: 'Gestern, 09:00', absolute: '23. Oktober 2026 um 09:00'},
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
    datetime: '2026-10-24T10:42:00+02:00',
    absolute: '24. Oktober 2026 um 10:42',
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
        expect(tour.getAttribute('title')).toBe('SEO optimieren · Über uns\nGuided Tour · Punkt 2 von 5\nstatus.awaitingInput\n24. Oktober 2026 um 10:42');
    });

    test('the "more" list shows the same rows and searches the context', async () => {
        // Newer than the tour, so the tour is one of the three in the list.
        const many = Array.from({length: 6}, (_, i) => ({...OWN, uid: 20 + i, pinned: false, lastActivityAt: `2026-10-25T0${i}:00:00+02:00`, displayTitle: `Frage ${i}`, fullTitle: `Frage ${i}`, contextLabel: `Seite „Seite ${i}“`}));
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
