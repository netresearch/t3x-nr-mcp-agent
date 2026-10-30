// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The panel's conversation row (NEXT-172).
 *
 * The row used to render one tab per conversation and wrap: with thirty
 * conversations it grew to several rows and the chat area below had no height
 * left. It now shows a fixed number of tabs — the active one always among
 * them — and puts the rest into a searchable list.
 *
 * jsdom does no layout, so these tests pin what decides the height — how many
 * tabs are rendered, and that the row may not wrap — and the keyboard contract
 * of the list. The height itself is measured in a real browser by the
 * Playwright spec chat-panel-conversation-row.spec.ts.
 */

import {describe, test, expect, beforeEach, jest} from '@jest/globals';
import {
    VISIBLE_TAB_COUNT,
    conversationTitle,
    conversationTooltip,
    lastActivity,
    listedConversations,
    orderConversations,
    splitConversationTabs,
    filterConversations,
} from '../../Resources/Public/JavaScript/conversation-tabs.js';

/** n conversations, uid 1 the oldest, uid n the newest. */
function conversations(n) {
    return Array.from({length: n}, (_, i) => ({
        uid: i + 1,
        title: `Chat ${i + 1}`,
        status: 'idle',
        pinned: false,
        tstamp: 1000 + i,
        messageCount: 2,
    }));
}

describe('orderConversations', () => {
    test('puts pinned conversations first, then the most recent', () => {
        const list = conversations(4);
        list[0].pinned = true; // the oldest

        expect(orderConversations(list).map(c => c.uid)).toEqual([1, 4, 3, 2]);
    });

    test('does not reorder the array it was given', () => {
        const list = conversations(3);
        orderConversations(list);
        expect(list.map(c => c.uid)).toEqual([1, 2, 3]);
    });

    /** A pin or a rename moves `tstamp`; the last message is what counts (ADR-027). */
    test('orders by the last message, not by the last change', () => {
        const list = [
            {uid: 1, tstamp: 3000, lastActivityAt: '2026-10-20T10:00:00+00:00'},
            {uid: 2, tstamp: 1000, lastActivityAt: '2026-10-24T10:00:00+00:00'},
            {uid: 3, tstamp: 2000, lastActivityAt: '2026-10-22T10:00:00+00:00'},
        ];
        expect(orderConversations(list).map(c => c.uid)).toEqual([2, 3, 1]);
    });

    test('falls back to the last change without a last message time', () => {
        expect(lastActivity({tstamp: 1700000000})).toBe(1700000000000);
        expect(lastActivity({tstamp: 1700000000, lastActivityAt: 'not a date'})).toBe(1700000000000);
        expect(lastActivity({lastActivityAt: '2026-10-24T10:00:00+00:00', tstamp: 1})).toBe(Date.parse('2026-10-24T10:00:00Z'));
        expect(lastActivity({})).toBe(0);
    });
});

/** A "New chat" without a message says nothing; it is listed only while open (ADR-027). */
describe('listedConversations', () => {
    test('leaves out conversations without a message', () => {
        const list = conversations(3);
        list[1].messageCount = 0;
        expect(listedConversations(list, null).map(c => c.uid)).toEqual([3, 1]);
    });

    test('keeps the open one, even without a message', () => {
        const list = conversations(3);
        list[1].messageCount = 0;
        expect(listedConversations(list, 2).map(c => c.uid)).toEqual([3, 2, 1]);
    });

    test('a list without message counts lists everything', () => {
        const list = conversations(2).map(({messageCount, ...c}) => c);
        expect(listedConversations(list, null)).toHaveLength(2);
    });

    test('the tab row and its "more" count leave them out too', () => {
        const list = conversations(10);
        list.slice(0, 4).forEach((c) => { c.messageCount = 0; });
        const {visible, overflow} = splitConversationTabs(list, 10);
        expect([...visible, ...overflow].map(c => c.uid)).toEqual([10, 9, 8, 7, 6, 5]);
        expect(overflow).toHaveLength(2);
    });
});

describe('conversationTitle and conversationTooltip', () => {
    test('the display title wins over the stored one, the label stands in for none', () => {
        expect(conversationTitle({displayTitle: 'SEO optimieren · Über uns', title: 'Diese Seite prüfen'}, 'New chat')).toBe('SEO optimieren · Über uns');
        expect(conversationTitle({title: 'Diese Seite prüfen'}, 'New chat')).toBe('Diese Seite prüfen');
        expect(conversationTitle({title: ''}, 'New chat')).toBe('New chat');
    });

    test('the tooltip carries the full title, the context, the status and the time, one per line', () => {
        const conversation = {
            displayTitle: 'Bitte prüfe alle Seiten…',
            fullTitle: 'Bitte prüfe alle Seiten unter Produkte',
            contextLabel: 'Seite „Produkte“ · SEO optimieren',
        };
        expect(conversationTooltip(conversation, 'Bereit', 'New chat', '24. Oktober 2026 um 10:42')).toBe(
            'Bitte prüfe alle Seiten unter Produkte\nSeite „Produkte“ · SEO optimieren\nBereit\n24. Oktober 2026 um 10:42',
        );
        expect(conversationTooltip({title: ''}, 'Bereit', 'New chat')).toBe('New chat\nBereit');
    });
});

describe('splitConversationTabs', () => {
    test('shows a fixed number of tabs however many conversations exist', () => {
        const {visible, overflow} = splitConversationTabs(conversations(30), 30);

        expect(visible).toHaveLength(VISIBLE_TAB_COUNT);
        expect(overflow).toHaveLength(30 - VISIBLE_TAB_COUNT);
        expect(visible.map(c => c.uid)).toEqual([30, 29, 28, 27]);
    });

    test('keeps an old active conversation in the row', () => {
        const {visible, overflow} = splitConversationTabs(conversations(30), 2);

        expect(visible).toHaveLength(VISIBLE_TAB_COUNT);
        expect(visible.map(c => c.uid)).toContain(2);
        expect(overflow.map(c => c.uid)).not.toContain(2);
        // The tab it displaced is the first entry of the list.
        expect(overflow[0].uid).toBe(27);
        // Nothing is lost or duplicated.
        const all = [...visible, ...overflow].map(c => c.uid).sort((a, b) => a - b);
        expect(all).toEqual(Array.from({length: 30}, (_, i) => i + 1));
    });

    test('pinned conversations take the row before recent ones', () => {
        const list = conversations(10);
        list[0].pinned = true;
        list[1].pinned = true;

        const {visible} = splitConversationTabs(list, null);
        expect(visible.map(c => c.uid)).toEqual([2, 1, 10, 9]);
    });

    test('no list when everything fits', () => {
        const {visible, overflow} = splitConversationTabs(conversations(3), 1);
        expect(visible).toHaveLength(3);
        expect(overflow).toHaveLength(0);
    });
});

describe('filterConversations', () => {
    test('matches the title case-insensitively', () => {
        const list = [{uid: 1, title: 'Seiten prüfen'}, {uid: 2, title: 'Log lesen'}];
        expect(filterConversations(list, 'PRÜF').map(c => c.uid)).toEqual([1]);
    });

    test('an empty query keeps everything', () => {
        const list = conversations(3);
        expect(filterConversations(list, '  ')).toHaveLength(3);
    });

    test('an untitled conversation matches by its placeholder label', () => {
        const list = [{uid: 1, title: ''}];
        expect(filterConversations(list, 'new', 'New chat')).toHaveLength(1);
    });

    /** Twenty conversations once had the same title; the page and the skill tell them apart (ADR-027). */
    test('matches the context: the page and the skill', () => {
        const list = [
            {uid: 1, title: 'Diese Seite prüfen', displayTitle: 'SEO optimieren · Über uns', contextLabel: 'Guided Tour'},
            {uid: 2, title: 'Frage', displayTitle: 'Frage', contextLabel: 'Seite „Kontakt“ · Barrierefreiheit'},
            {uid: 3, title: 'Log lesen', displayTitle: 'Log lesen', contextLabel: ''},
        ];
        expect(filterConversations(list, 'kontakt').map(c => c.uid)).toEqual([2]);
        expect(filterConversations(list, 'über uns').map(c => c.uid)).toEqual([1]);
        expect(filterConversations(list, 'guided').map(c => c.uid)).toEqual([1]);
    });

    test('matches the full title behind a shortened one', () => {
        const list = [{uid: 1, displayTitle: 'Bitte prüfe alle Seiten…', fullTitle: 'Bitte prüfe alle Seiten unter Produkte'}];
        expect(filterConversations(list, 'produkte').map(c => c.uid)).toEqual([1]);
    });
});

async function mountPanel(chatState) {
    await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
    document.body.replaceChildren();
    const el = document.createElement('ai-chat-panel');
    document.body.append(el);
    // Let the controller's own init() fail on the missing fetch and settle
    // before the state under test is put in place.
    await new Promise(r => setTimeout(r, 0));

    Object.assign(el.chat, {
        available: true,
        loading: false,
        issues: [],
        messages: [],
        status: 'idle',
        ...chatState,
    });
    el.chat.selectConversation = jest.fn();
    el.state = 'expanded';
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

describe('ai-chat-panel conversation row', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    test('renders a fixed number of tabs and a "more" button for thirty conversations', async () => {
        const el = await mountPanel({conversations: conversations(30), activeUid: 30});
        const root = el.shadowRoot;

        expect(root.querySelectorAll('.conv-tablist [role="tab"]')).toHaveLength(VISIBLE_TAB_COUNT);
        const more = root.querySelector('.conv-tab-more');
        expect(more).not.toBeNull();
        expect(more.getAttribute('aria-haspopup')).toBe('listbox');
        expect(more.getAttribute('aria-expanded')).toBe('false');
    });

    test('the row is not allowed to wrap', async () => {
        const el = await mountPanel({conversations: conversations(30), activeUid: 30});
        const css = el.constructor.styles.map(s => s.cssText ?? '').join('\n');
        const rule = css.match(/\.conv-tabs\s*\{[^}]*\}/);

        expect(rule).not.toBeNull();
        expect(rule[0]).toMatch(/flex-wrap:\s*nowrap/);
        expect(rule[0]).not.toMatch(/flex-wrap:\s*wrap\b/);
    });

    test('opens a searchable list of the rest, operable from the keyboard', async () => {
        const el = await mountPanel({conversations: conversations(30), activeUid: 30});
        const root = el.shadowRoot;

        root.querySelector('.conv-tab-more').click();
        await el.updateComplete;

        const input = root.querySelector('.conv-more-search');
        expect(input.getAttribute('role')).toBe('combobox');
        expect(input.getAttribute('aria-controls')).toBe('conv-more-list');
        const options = root.querySelectorAll('#conv-more-list [role="option"]');
        expect(options).toHaveLength(30 - VISIBLE_TAB_COUNT);
        expect(input.getAttribute('aria-activedescendant')).toBe(options[0].id);

        input.dispatchEvent(new KeyboardEvent('keydown', {key: 'ArrowDown', bubbles: true, composed: true}));
        await el.updateComplete;
        const second = root.querySelectorAll('#conv-more-list [role="option"]')[1];
        expect(input.getAttribute('aria-activedescendant')).toBe(second.id);
        expect(second.getAttribute('aria-selected')).toBe('true');

        input.dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, composed: true}));
        await el.updateComplete;
        expect(el.chat.selectConversation).toHaveBeenCalledWith(25);
        expect(root.querySelector('.conv-more-popover')).toBeNull();
    });

    test('Home and End jump to the first and the last entry; the button names the list it opens', async () => {
        const el = await mountPanel({conversations: conversations(30), activeUid: 30});
        const root = el.shadowRoot;
        expect(root.querySelector('.conv-tab-more').hasAttribute('aria-controls')).toBe(false);
        root.querySelector('.conv-tab-more').click();
        await el.updateComplete;
        expect(root.querySelector('.conv-tab-more').getAttribute('aria-controls')).toBe('conv-more-list');

        const input = root.querySelector('.conv-more-search');
        input.dispatchEvent(new KeyboardEvent('keydown', {key: 'End', bubbles: true, composed: true}));
        await el.updateComplete;
        const options = root.querySelectorAll('#conv-more-list [role="option"]');
        expect(input.getAttribute('aria-activedescendant')).toBe(options[options.length - 1].id);

        input.dispatchEvent(new KeyboardEvent('keydown', {key: 'Home', bubbles: true, composed: true}));
        await el.updateComplete;
        expect(input.getAttribute('aria-activedescendant')).toBe(options[0].id);
    });

    test('typing filters the list', async () => {
        const el = await mountPanel({conversations: conversations(30), activeUid: 30});
        const root = el.shadowRoot;
        root.querySelector('.conv-tab-more').click();
        await el.updateComplete;

        const input = root.querySelector('.conv-more-search');
        input.value = 'Chat 1';
        input.dispatchEvent(new Event('input'));
        await el.updateComplete;

        const titles = [...root.querySelectorAll('#conv-more-list [role="option"] .conv-summary-title')].map(n => n.textContent.trim());
        expect(titles.length).toBeGreaterThan(0);
        expect(titles.every(t => t.startsWith('Chat 1'))).toBe(true);
    });

    test('Escape closes the list without collapsing the panel', async () => {
        const el = await mountPanel({conversations: conversations(30), activeUid: 30});
        const root = el.shadowRoot;
        root.querySelector('.conv-tab-more').click();
        await el.updateComplete;

        root.querySelector('.conv-more-search')
            .dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, composed: true}));
        await el.updateComplete;

        expect(root.querySelector('.conv-more-popover')).toBeNull();
        expect(el.state).toBe('expanded');
    });

    test('the empty-state hint names the row above in the expanded layout', async () => {
        const el = await mountPanel({conversations: conversations(2), activeUid: null});
        expect(el.shadowRoot.querySelector('.empty-state-hint').textContent.trim()).toBe('chat.empty.hintTabs');

        el.state = 'maximized';
        await el.updateComplete;
        expect(el.shadowRoot.querySelector('.empty-state-hint').textContent.trim()).toBe('chat.empty.hint');
    });
});
