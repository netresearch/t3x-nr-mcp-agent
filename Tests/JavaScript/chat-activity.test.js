/**
 * The activity list of the current turn (NEXT-172).
 */

import {describe, test, expect, jest, beforeEach} from '@jest/globals';
import {ChatCoreController} from '../../Resources/Public/JavaScript/chat-core.js';
import {activityEntries, latestStepAnnouncement} from '../../Resources/Public/JavaScript/chat-activity.js';

function controller(state = {}) {
    const host = {addController() {}, requestUpdate: jest.fn(), onScrollToBottom() {}, onFocusInput() {}, onResetInput() {}, isConnected: true};
    const chat = new ChatCoreController(host);
    Object.assign(chat, {activeUid: 7, status: 'processing', conversations: [{uid: 7}], ...state});
    return chat;
}

describe('activityEntries', () => {
    test('shows model rounds, tool calls and decisions in order', () => {
        const chat = controller({
            status: 'idle',
            activity: [
                {kind: 'llm', round: 1, ms: 1250},
                {kind: 'tool', round: 1, ms: 40, tool: 'read_records', error: false},
                {kind: 'tool', round: 2, ms: 3, tool: 'create_page', error: true},
                {kind: 'approval', approved: true},
            ],
        });

        const entries = activityEntries(chat);

        expect(entries.map(e => e.label)).toEqual([
            'activity.modelRound',
            'activity.tool',
            'activity.tool',
            'activity.approved',
        ]);
        expect(entries[0].meta).toBe('1.3 s');
        expect(entries[1].meta).toBe('40 ms');
        expect(entries[2].tone).toBe('error');
        expect(entries[2].meta).toBe('activity.failed');
    });

    test('a turn that waits for an approval ends with what it waits for', () => {
        const chat = controller({
            status: 'awaiting_approval',
            activity: [{kind: 'llm', round: 1, ms: 5}],
            pendingApproval: {calls: [{name: 'create_page'}, {name: 'update_record'}]},
        });

        const last = activityEntries(chat).at(-1);

        expect(last.label).toBe('activity.waiting');
        expect(last.meta).toBe('create_page, update_record');
    });

    test('a running turn ends with a working entry', () => {
        expect(activityEntries(controller({activity: []})).at(-1).label).toBe('activity.working');
    });

    test('a settled turn adds nothing', () => {
        expect(activityEntries(controller({status: 'idle', activity: []}))).toEqual([]);
    });
});

describe('announcements', () => {
    test('only the newest step is announced', () => {
        const chat = controller({activity: [
            {kind: 'llm', round: 1, ms: 5},
            {kind: 'tool', round: 1, ms: 40, tool: 'read_records', error: false},
        ]});

        expect(latestStepAnnouncement(chat)).toBe('activity.tool, 40 ms');
    });

    test('nothing is announced before the first step', () => {
        expect(latestStepAnnouncement(controller({activity: []}))).toBe('');
    });
});

describe('the poll', () => {
    test('takes the activity even when no message and no status changed', async () => {
        const chat = controller({activity: [], _knownMessageCount: 1});
        chat._api = {
            getMessages: jest.fn().mockResolvedValue({
                status: 'processing',
                messages: [],
                totalCount: 1,
                activity: [{kind: 'tool', round: 1, ms: 4, tool: 'read_records', error: false}],
            }),
        };

        await chat.pollMessages();
        chat.stopPolling();

        expect(chat.activity).toEqual([{kind: 'tool', round: 1, ms: 4, tool: 'read_records', error: false}]);
        expect(chat.host.requestUpdate).toHaveBeenCalled();
    });
});

describe.each([
    {name: 'ai-chat-panel expanded', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', state: 'expanded', placement: 'strip'},
    {name: 'ai-chat-panel maximized', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', state: 'maximized', placement: 'sidebar'},
    {name: 'chat-app', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', state: null, placement: 'sidebar'},
])('$name', ({module: modulePath, tag, state, placement}) => {
    beforeEach(() => localStorage.clear());

    test('the activity button shows the list of the current turn', async () => {
        await import(modulePath);
        document.body.replaceChildren();
        const el = document.createElement(tag);
        document.body.append(el);
        await new Promise(r => setTimeout(r, 0));
        Object.assign(el.chat, {
            available: true,
            loading: false,
            issues: [],
            status: 'idle',
            activeUid: 7,
            conversations: [{uid: 7, title: 'x', status: 'idle', tstamp: 1}],
            messages: [{role: 'user', content: 'hi'}],
            activity: [{kind: 'tool', round: 1, ms: 4, tool: 'read_records', error: false}],
        });
        if (state) el.state = state;
        el.requestUpdate();
        await el.updateComplete;

        expect(el.shadowRoot.querySelector('.activity')).toBeNull();

        el.shadowRoot.querySelector('[data-action="activity"]').click();
        await el.updateComplete;

        const list = el.shadowRoot.querySelector(`.activity-${placement}`);
        expect(list).not.toBeNull();
        expect(list.querySelectorAll('li')).toHaveLength(1);
        expect(list.textContent).toContain('activity.tool');
        // One small live region, not the whole list.
        expect(list.querySelector('ol').hasAttribute('aria-live')).toBe(false);
        expect(list.querySelector('[role="status"]').textContent.trim()).toBe('activity.tool, 4 ms');
    });
});
