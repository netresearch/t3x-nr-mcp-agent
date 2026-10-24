// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * A conversation about a page that has been deleted is read-only: the server
 * says so with `pageGone`, the chat shows a notice where the input was, offers
 * no edit, no decision and no resume, and sends nothing.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app'},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const PROPOSAL = {
    name: 'update_page_metadata',
    actionLabel: 'Seiten-Metadaten ändern',
    heading: 'Seiten-Metadaten ändern',
    toolStillRegistered: true,
    previewLines: ['Seite: „Home“'],
    technicalDetails: '',
    previewFailed: false,
    argumentsJson: '{"pageUid":10002}',
};

const MESSAGES = [
    {role: 'user', content: 'Hero-Text kürzen'},
    {role: 'assistant', content: 'Erledigt.'},
];

async function render(modulePath, tag, open, overrides = {}) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'Hero-Text kürzen', status: 'idle', messageCount: 2, pinned: false}],
        messages: MESSAGES,
        status: 'idle',
        errorMessage: '',
        ...overrides,
    });
    el.chat.syncPageWatch = jest.fn();
    el.chat._api.sendMessage = jest.fn().mockResolvedValue({status: 'processing'});
    el.chat.startPollingIfNeeded = jest.fn();
    if (open) el.state = 'expanded';
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

describe.each(SURFACES)('$name, conversation about a deleted page', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('the notice takes the place of the input', async () => {
        const el = await render(modulePath, tag, open, {pageGone: true});

        const notice = el.shadowRoot.querySelector('.read-only-notice');
        expect(notice).not.toBeNull();
        expect(notice.getAttribute('role')).toBe('note');
        expect(el.shadowRoot.querySelector('textarea')).toBeNull();
        // The transcript is still there to read.
        expect(el.shadowRoot.textContent).toContain('Erledigt.');
        el.remove();
    });

    test('a conversation about an existing page keeps its input', async () => {
        const el = await render(modulePath, tag, open, {pageGone: false});

        expect(el.shadowRoot.querySelector('.read-only-notice')).toBeNull();
        expect(el.shadowRoot.querySelector('textarea')).not.toBeNull();
        el.remove();
    });

    test.each([
        [true, 0],
        [false, 1],
    ])('pageGone %s: an earlier message can be edited, a waiting proposal decided %i time(s)', async (pageGone, shown) => {
        const el = await render(modulePath, tag, open, {
            pageGone,
            status: 'awaiting_approval',
            mayDecideApproval: true,
            pendingApproval: {runUuid: 'run-1', turnDigest: 'digest', configLabel: 'Demo', unreadableReason: null, answers: 'process', calls: [PROPOSAL]},
        });

        expect(el.shadowRoot.querySelectorAll('.msg-edit')).toHaveLength(shown);
        expect(el.shadowRoot.querySelectorAll('.approval-frame')).toHaveLength(shown);
        el.remove();
    });

    // A tour whose page was deleted has no `tour` from the server; ending it
    // is its way out, and afterwards the conversation can be continued.
    test('a tour can be ended, and the chat is open again', async () => {
        const el = await render(modulePath, tag, open, {pageGone: true, skill: {identifier: 'seo-optimieren', name: 'SEO'}, tour: null});
        el.chat._api.endTour = jest.fn().mockResolvedValue({status: 'idle', skill: null});
        el.chat.host.onFocusInput = jest.fn();

        const end = el.shadowRoot.querySelector('[data-action="end-tour"]');
        expect(end).not.toBeNull();
        end.click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await el.updateComplete;

        expect(el.chat._api.endTour).toHaveBeenCalledWith(1);
        expect(el.chat.isReadOnly()).toBe(false);
        expect(el.shadowRoot.querySelector('.read-only-notice')).toBeNull();
        expect(el.shadowRoot.querySelector('textarea')).not.toBeNull();
        el.remove();
    });

    test('without a skill there is no tour to end', async () => {
        const el = await render(modulePath, tag, open, {pageGone: true, skill: null, tour: null});

        expect(el.shadowRoot.querySelector('[data-action="end-tour"]')).toBeNull();
        expect(el.shadowRoot.querySelector('[data-action="end-tour-notice"]')).toBeNull();
        el.remove();
    });

    test('the notice offers "End tour" by name, which ends it', async () => {
        const el = await render(modulePath, tag, open, {pageGone: true, skill: {identifier: 'seo-optimieren', name: 'SEO'}, tour: null});
        el.chat._api.endTour = jest.fn().mockResolvedValue({status: 'idle', skill: null});
        el.chat.host.onFocusInput = jest.fn();

        const button = el.shadowRoot.querySelector('.read-only-notice [data-action="end-tour-notice"]');
        expect(button).not.toBeNull();
        expect(button.textContent.trim()).toBe('guided.endTour');
        button.click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await el.updateComplete;

        expect(el.chat._api.endTour).toHaveBeenCalledWith(1);
        expect(el.shadowRoot.querySelector('.read-only-notice')).toBeNull();
        el.remove();
    });

    // The status notice, where errors usually go, is not shown while the
    // chat is read-only: the notice says it, as an alert, with no Retry.
    test('a failed end is said in the notice', async () => {
        const el = await render(modulePath, tag, open, {pageGone: true, skill: {identifier: 'seo-optimieren', name: 'SEO'}, tour: null});
        el.chat._api.endTour = jest.fn().mockRejectedValue(new Error('Gerade beschäftigt'));

        el.shadowRoot.querySelector('[data-action="end-tour-notice"]').click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await el.updateComplete;

        const error = el.shadowRoot.querySelector('.read-only-notice [role="alert"]');
        expect(error).not.toBeNull();
        expect(error.textContent).toContain('Gerade beschäftigt');
        expect([...el.shadowRoot.querySelectorAll('.read-only-notice button')].map((b) => b.dataset.action)).toEqual(['end-tour-notice']);
        expect(el.chat.isReadOnly()).toBe(true);
        el.remove();
    });

    test('nothing is sent', async () => {
        const el = await render(modulePath, tag, open, {pageGone: true, inputValue: 'Weiter', hasInput: true});

        await el.chat.handleSend();

        expect(el.chat._api.sendMessage).not.toHaveBeenCalled();
        el.remove();
    });
});

describe('a process picked without a page', () => {
    test('takes what the server answers about starting it, so the chat asks for a page', async () => {
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
        const el = document.createElement('ai-chat-panel');
        document.body.append(el);
        el.chat.syncPageWatch = jest.fn();
        el.chat.activeUid = 1;
        el.chat._api.updateSkill = jest.fn().mockResolvedValue({
            skill: {identifier: 'seo-optimieren', name: 'SEO'},
            tourStart: {skill: 'seo-optimieren', choosePage: true},
        });

        await el.chat._setSkill('seo-optimieren');

        expect(el.chat.tourStart).toEqual({skill: 'seo-optimieren', choosePage: true});
        expect(el.chat.syncPageWatch).toHaveBeenCalled();
        el.remove();
    });
});

describe('the flag comes from the server and belongs to one conversation', () => {
    test('loading a conversation reads it, opening another one resets it', async () => {
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
        const el = document.createElement('ai-chat-panel');
        document.body.append(el);
        el.chat.startPollingIfNeeded = jest.fn();
        el.chat.syncPageWatch = jest.fn();
        el.chat._api.getMessages = jest.fn()
            .mockResolvedValueOnce({messages: MESSAGES, status: 'idle', totalCount: 2, pageGone: true})
            .mockResolvedValueOnce({messages: MESSAGES, status: 'idle', totalCount: 2});

        el.chat.activeUid = 1;
        await el.chat.loadMessages();
        expect(el.chat.isReadOnly()).toBe(true);

        const opening = el.chat.selectConversation(2);
        // Reset before the other conversation's answer is in.
        expect(el.chat.isReadOnly()).toBe(false);
        await opening;
        expect(el.chat.isReadOnly()).toBe(false);
        el.remove();
    });
});
