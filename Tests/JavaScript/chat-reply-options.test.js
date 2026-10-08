// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The answer to a question a run asks, above the input (ADR-018).
 *
 * The assertions are about what a reader can see and press, on both surfaces,
 * and about what reaches the API: a button that renders but sends nothing, or
 * free text that silently goes out as a new message, looks identical in a
 * source grep.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const CHOICE = {
    runUuid: 'run-uuid-1234',
    turnDigest: 'digest-abc',
    kind: 'choice',
    question: 'Soll die neue Meta Description übernommen werden?',
    options: [
        {value: 'accept', label: 'Übernehmen'},
        {value: 'variant', label: 'Andere Variante'},
        {value: 'skip', label: 'Überspringen'},
    ],
    freeText: true,
    fields: [],
};

const FORM = {
    runUuid: 'run-uuid-1234',
    turnDigest: 'digest-abc',
    kind: 'form',
    question: 'Angaben zur neuen Seite',
    options: [],
    freeText: false,
    fields: [
        {name: 'title', label: 'Seitentitel', type: 'text', required: true, options: [], description: ''},
        {name: 'position', label: 'Position', type: 'integer', required: false, options: [], description: ''},
        {name: 'hidden', label: 'Verborgen', type: 'boolean', required: false, options: [], description: ''},
        {name: 'layout', label: 'Layout', type: 'select', required: false, options: [{value: 1, label: 'Breit'}, {value: 2, label: 'Schmal'}], description: ''},
    ],
};

async function render(modulePath, tag, open, overrides = {}) {
    await import(modulePath);

    const el = document.createElement(tag);
    document.body.append(el);

    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'Seite prüfen', status: 'awaiting_input', messageCount: 2, pinned: false}],
        messages: [
            {role: 'user', content: 'Prüfe die Seite'},
            {role: 'assistant', content: CHOICE.question},
        ],
        status: 'awaiting_input',
        errorMessage: '',
        approvalUrl: '',
        pendingApproval: null,
        pendingInput: CHOICE,
        ...overrides,
    });

    if (open) {
        el.state = 'OPEN';
    }

    el.requestUpdate();
    await el.updateComplete;

    return el;
}

function textarea(el) {
    return el.shadowRoot.querySelector('textarea');
}

describe.each(SURFACES)('$name reply options', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('each answer is a real button in a labelled group, above the input', async () => {
        const el = await render(modulePath, tag, open);
        const group = el.shadowRoot.querySelector('.reply-options [role="group"]');

        expect(group).not.toBeNull();
        expect(group.getAttribute('aria-label')).toBe('input.groupLabel');
        const buttons = [...group.querySelectorAll('button')];
        expect(buttons.map((b) => b.localName)).toEqual(['button', 'button', 'button']);
        expect(buttons.map((b) => b.getAttribute('type'))).toEqual(['button', 'button', 'button']);
        expect(buttons.map((b) => b.textContent.trim())).toEqual(['Übernehmen', 'Andere Variante', 'Überspringen']);

        // Above the input: the options come before the textarea in the document.
        const position = group.compareDocumentPosition(textarea(el));
        expect(position & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    test('a button sends its value with the digest the question came with', async () => {
        const el = await render(modulePath, tag, open);
        const submit = jest.fn().mockResolvedValue({status: 'processing'});
        el.chat._api.submitInput = submit;
        el.chat.loadMessages = jest.fn().mockResolvedValue(undefined);

        el.shadowRoot.querySelectorAll('.reply-options button')[0].click();
        await el.updateComplete;

        expect(submit).toHaveBeenCalledTimes(1);
        expect(submit).toHaveBeenCalledWith(1, 'digest-abc', {choice: 'accept'});
    });

    test('the buttons are disabled while an answer is on its way', async () => {
        const el = await render(modulePath, tag, open, {inputBusy: true});

        expect([...el.shadowRoot.querySelectorAll('.reply-options button')].every((b) => b.disabled)).toBe(true);
    });

    test('a second click while the first answer travels sends nothing', async () => {
        const el = await render(modulePath, tag, open);
        let release;
        const submit = jest.fn().mockReturnValue(new Promise((resolve) => { release = resolve; }));
        el.chat._api.submitInput = submit;
        el.chat.loadMessages = jest.fn().mockResolvedValue(undefined);

        const first = el.chat.submitInput({choice: 'accept'});
        await el.chat.submitInput({choice: 'skip'});
        release({status: 'processing'});
        await first;

        expect(submit).toHaveBeenCalledTimes(1);
    });

    test('with a free-text answer, typed text answers the question instead of starting a new turn', async () => {
        const el = await render(modulePath, tag, open);
        const submit = jest.fn().mockResolvedValue({status: 'processing'});
        const send = jest.fn().mockResolvedValue({status: 'processing'});
        el.chat._api.submitInput = submit;
        el.chat._api.sendMessage = send;
        el.chat.loadMessages = jest.fn().mockResolvedValue(undefined);

        expect(textarea(el).getAttribute('placeholder')).toBe('input.freeTextPlaceholder');

        el.chat.inputValue = 'Bitte kürzer formulieren';
        el.chat.hasInput = true;
        await el.chat.handleSend();

        expect(submit).toHaveBeenCalledWith(1, 'digest-abc', {freeText: 'Bitte kürzer formulieren'});
        expect(send).not.toHaveBeenCalled();
        expect(el.chat.inputValue).toBe('');
    });

    test('without a free-text answer, typed text is a message of its own, and the options say so', async () => {
        const el = await render(modulePath, tag, open, {pendingInput: {...CHOICE, freeText: false}});
        const submit = jest.fn();
        const send = jest.fn().mockResolvedValue({status: 'processing'});
        el.chat._api.submitInput = submit;
        el.chat._api.sendMessage = send;

        expect(textarea(el).getAttribute('placeholder')).toBe('chat.placeholder');
        expect(el.shadowRoot.querySelector('.reply-options').textContent).toContain('input.messageEndsQuestion');

        el.chat.inputValue = 'Mach etwas anderes';
        el.chat.hasInput = true;
        await el.chat.handleSend();

        expect(send).toHaveBeenCalled();
        expect(submit).not.toHaveBeenCalled();
    });

    test('a question without text of its own gets a heading over the buttons', async () => {
        const el = await render(modulePath, tag, open, {pendingInput: {...CHOICE, question: ''}});

        expect(el.shadowRoot.querySelector('.reply-options-question').textContent.trim()).toBe('input.chooseAnswer');
    });

    test('a refusal that brought the question back is shown with the options, without a Retry', async () => {
        const el = await render(modulePath, tag, open, {errorMessage: 'Die Rückfrage hat sich geändert.'});

        const reason = el.shadowRoot.querySelector('.reply-options-reason');
        expect(reason.textContent.trim()).toBe('Die Rückfrage hat sich geändert.');
        expect(reason.getAttribute('role')).toBe('alert');
        expect(el.shadowRoot.querySelector('.status-notice')).toBeNull();
        expect(el.shadowRoot.textContent).not.toContain('chat.retry');
    });

    test('a form sends its fields, typed by the schema on the server', async () => {
        const el = await render(modulePath, tag, open, {pendingInput: FORM});
        const submit = jest.fn().mockResolvedValue({status: 'processing'});
        el.chat._api.submitInput = submit;
        el.chat.loadMessages = jest.fn().mockResolvedValue(undefined);

        const form = el.shadowRoot.querySelector('.reply-form');
        expect([...form.querySelectorAll('label')].map((l) => l.textContent.trim().split('\n')[0].trim()))
            .toEqual(['Seitentitel', 'Position', 'Verborgen', 'Layout']);
        expect(form.querySelector('#reply-field-title').required).toBe(true);

        form.querySelector('#reply-field-title').value = 'Über uns';
        form.querySelector('#reply-field-position').value = '2';
        form.querySelector('#reply-field-hidden').checked = true;
        form.querySelector('#reply-field-layout').value = '1';
        form.dispatchEvent(new Event('submit', {cancelable: true}));
        await el.updateComplete;

        expect(submit).toHaveBeenCalledWith(1, 'digest-abc', {fields: {title: 'Über uns', position: '2', hidden: true, layout: 2}});
    });

    test('a question the chat cannot show says so and how to go on', async () => {
        const el = await render(modulePath, tag, open, {
            pendingInput: {...CHOICE, kind: 'unsupported', options: []},
            approvalUrl: '/typo3/module/web/nrllm-aitasks?runUuid=run-uuid-1234',
        });
        const options = el.shadowRoot.querySelector('.reply-options');

        expect(options.textContent).toContain('input.unsupported');
        expect(options.textContent).toContain('input.messageEndsQuestion');
        expect(options.querySelector('button')).toBeNull();
        expect(options.querySelector('a').getAttribute('href')).toBe('/typo3/module/web/nrllm-aitasks?runUuid=run-uuid-1234');
    });

    test('nothing is offered while the conversation does not wait for an answer', async () => {
        const el = await render(modulePath, tag, open, {status: 'idle', pendingInput: null});

        expect(el.shadowRoot.querySelector('.reply-options')).toBeNull();
        expect(textarea(el).getAttribute('placeholder')).toBe('chat.placeholder');
    });
});

describe('the waiting question in the activity list', () => {
    test('reads as waiting for an answer, not for an approval', async () => {
        const {activityEntries} = await import('../../Resources/Public/JavaScript/chat-activity.js');
        const entries = activityEntries({activity: [], status: 'awaiting_input', isProcessing: () => false});

        expect(entries.map((e) => e.label)).toEqual(['activity.waitingInput']);
    });
});
