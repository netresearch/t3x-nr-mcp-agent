// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * A run stopped at its round cap (ADR-023, amendment of 2026-10-22): the
 * transcript says so in the reader's language, and while a process runs and
 * nothing was sent since, "Weiter" sends a turn that continues it.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const TOUR = {pageUid: 10005, languageUid: 0, pageTitle: 'Zwilling Airfryer', languageName: 'Deutsch'};
const STOPPED = {role: 'assistant', content: '[The run stopped at its round limit before it finished.]', notice: 'roundLimit'};
const MESSAGES = [
    {role: 'user', content: 'Diese Seite prüfen'},
    {role: 'assistant', content: '**Probleme**\n\n1. Meta Description fehlt'},
    STOPPED,
];

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
        conversations: [{uid: 1, title: 'SEO', status: 'idle', messageCount: 3, pinned: false}],
        messages: MESSAGES,
        status: 'idle',
        errorMessage: '',
        skill: {identifier: '3:seo-optimieren', name: 'SEO optimieren'},
        tour: TOUR,
        tourStart: null,
        guided: {progress: {label: 'Zwilling Airfryer · Deutsch', current: 0, total: 2, completed: false}, highlight: null},
        backendPage: {pageId: 10005},
        ...overrides,
    });
    el.chat.syncPageWatch = jest.fn();
    el.chat._api.sendMessage = jest.fn().mockResolvedValue({status: 'processing'});
    el.chat.startPollingIfNeeded = jest.fn();
    if (open) el.state = 'OPEN';
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

function continueButton(el) {
    return el.shadowRoot.querySelector('[data-action="continue-tour"]');
}

describe.each(SURFACES)('$name run stopped at its round cap', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('the transcript shows the line in the reader\'s language, not the stored one', async () => {
        const el = await render(modulePath, tag, open);
        const text = el.shadowRoot.textContent;

        expect(text).toContain('chat.roundLimit');
        expect(text).not.toContain('[The run stopped at its round limit');
    });

    test('"Weiter" sends its own words as the next turn', async () => {
        const el = await render(modulePath, tag, open);
        const button = continueButton(el);

        expect(button.tagName).toBe('BUTTON');
        expect(button.getAttribute('type')).toBe('button');
        expect(button.textContent.trim()).toBe('guided.continue');
        button.click();
        await settle(el);

        expect(el.chat._api.sendMessage).toHaveBeenCalledWith(1, 'guided.continue', null, expect.any(Object));
    });

    test('without a running process there is no "Weiter"', async () => {
        const el = await render(modulePath, tag, open, {tour: null});

        expect(continueButton(el)).toBeNull();
    });

    test('a finished process offers no "Weiter"', async () => {
        const el = await render(modulePath, tag, open, {guided: {progress: {label: 'x', current: 2, total: 2, completed: true}, highlight: null}});

        expect(continueButton(el)).toBeNull();
    });

    test('once something was sent after the line, "Weiter" is gone', async () => {
        const el = await render(modulePath, tag, open, {messages: [...MESSAGES, {role: 'user', content: 'Kürzer bitte'}]});

        expect(continueButton(el)).toBeNull();
    });

    test('a draft in the input hides "Weiter" and stays as it is', async () => {
        const el = await render(modulePath, tag, open, {inputValue: 'Kürzer bitte', hasInput: true});

        expect(continueButton(el)).toBeNull();
        await el.chat.continueTour();
        expect(el.chat._api.sendMessage).not.toHaveBeenCalled();
        expect(el.chat.inputValue).toBe('Kürzer bitte');
    });

    test('an attachment waiting to be sent hides "Weiter"', async () => {
        const el = await render(modulePath, tag, open, {pendingFile: {fileUid: 9, name: 'a.pdf', mimeType: 'application/pdf'}});

        expect(continueButton(el)).toBeNull();
        await el.chat.continueTour();
        expect(el.chat._api.sendMessage).not.toHaveBeenCalled();
        expect(el.chat.pendingFile).not.toBeNull();
    });

    test('while a turn runs there is no "Weiter"', async () => {
        const el = await render(modulePath, tag, open, {status: 'processing'});

        expect(continueButton(el)).toBeNull();
    });
});

/**
 * A step a process run ended without a choice, a proposal or the end of the
 * process (ADR-023, amendment of 2026-10-23): the same line and "Weiter".
 */
const STEP_OPEN = {role: 'assistant', content: '[The run ended its step without a choice, a proposal or the end of the process.]', notice: 'stepOpen'};

describe.each(SURFACES)('$name step ended without its next action', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('the transcript shows the line in the reader\'s language, not the stored one', async () => {
        const el = await render(modulePath, tag, open, {messages: [...MESSAGES.slice(0, 2), STEP_OPEN]});
        const text = el.shadowRoot.textContent;

        expect(text).toContain('chat.stepOpen');
        expect(text).not.toContain('[The run ended its step');
    });

    test('"Weiter" sends its own words as the next turn', async () => {
        const el = await render(modulePath, tag, open, {messages: [...MESSAGES.slice(0, 2), STEP_OPEN]});
        const button = continueButton(el);

        expect(button.tagName).toBe('BUTTON');
        button.click();
        await settle(el);

        expect(el.chat._api.sendMessage).toHaveBeenCalledWith(1, 'guided.continue', null, expect.any(Object));
    });

    test('a finished process offers no "Weiter"', async () => {
        const el = await render(modulePath, tag, open, {
            messages: [...MESSAGES.slice(0, 2), STEP_OPEN],
            guided: {progress: {label: 'x', current: 2, total: 2, completed: true}, highlight: null},
        });

        expect(continueButton(el)).toBeNull();
    });
});
