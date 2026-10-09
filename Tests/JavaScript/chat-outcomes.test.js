// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The chat's own record of what became of a guided process's proposals
 * (ADR-023): a status line after each approved proposal, before the model's
 * answer, and a summary in groups once the process reports that it is done.
 * Every word comes from the record and fixed labels, never from the model.
 */

import {describe, test, expect, beforeAll} from '@jest/globals';
import {outcomeLine} from '../../Resources/Public/JavaScript/chat-outcomes.js';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const MESSAGES = [
    {role: 'user', content: 'Seite prüfen'},
    {role: 'assistant', content: 'Vorschlag 1'},
    {role: 'assistant', content: 'Weiter mit Punkt 2'},
    {role: 'user', content: 'Überspringen'},
    {role: 'assistant', content: 'Fertig'},
];

const OUTCOMES = [
    {outcome: 'applied', after: 2, subject: 'Beschreibung', record: 'Seite 3'},
    {outcome: 'skipped', after: 4, subject: 'Überschrift', record: 'Inhaltselement 9'},
    {outcome: 'variant', after: 4, subject: 'Titel', record: 'Seite 3'},
];

async function render(modulePath, tag, open, {outcomes = OUTCOMES, completed = false} = {}) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'SEO', status: 'idle', messageCount: MESSAGES.length, pinned: false}],
        messages: MESSAGES,
        status: 'idle',
        errorMessage: '',
        cardOutcomes: outcomes,
        guided: {progress: {label: 'SEO', current: 2, total: 2, completed}, highlight: null},
    });
    if (open) {
        el.state = 'OPEN';
    }
    el.requestUpdate();
    await el.updateComplete;

    return el;
}

describe('the status line of an outcome', () => {
    test.each([
        ['applied', 'Beschreibung chat.outcomeApplied'],
        ['check', 'chat.outcomeCheck Beschreibung'],
        ['not_applied', 'chat.outcomeNotApplied Beschreibung'],
        ['skipped', ''],
        ['variant', ''],
    ])('%s', (outcome, line) => {
        expect(outcomeLine({outcome, subject: 'Beschreibung'})).toBe(line);
    });

    test('without a subject it names a change', () => {
        expect(outcomeLine({outcome: 'applied', subject: ''})).toBe('chat.outcomeChange chat.outcomeApplied');
    });
});

describe.each(SURFACES)('$name', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('the status line stands where the outcome happened, before the model\'s answer', async () => {
        const el = await render(modulePath, tag, open);
        const line = el.shadowRoot.querySelector('.proposal-outcome');

        expect(el.shadowRoot.querySelectorAll('.proposal-outcome')).toHaveLength(1);
        expect(line.textContent.trim()).toBe('Beschreibung chat.outcomeApplied');
        expect(line.dataset.outcome).toBe('applied');
        // The second message is the one before the decision; the third is the answer after it.
        expect(line.previousElementSibling.textContent).toContain('Vorschlag 1');
        expect(line.nextElementSibling.textContent).toContain('Weiter mit Punkt 2');
    });

    test('an outcome after the last message stands at the end', async () => {
        const el = await render(modulePath, tag, open, {outcomes: [{outcome: 'not_applied', after: 5, subject: 'Titel', record: 'Seite 3'}]});
        const line = el.shadowRoot.querySelector('.proposal-outcome');

        expect(line.textContent.trim()).toBe('chat.outcomeNotApplied Titel');
        expect(line.previousElementSibling.textContent).toContain('Fertig');
    });

    test('the record arrives with the conversation from the server', async () => {
        const el = await render(modulePath, tag, open, {outcomes: []});
        el.chat._api.getMessages = async () => ({
            messages: MESSAGES, totalCount: MESSAGES.length, status: 'idle', errorMessage: '',
            guided: {progress: null, highlight: null},
            cardOutcomes: [{outcome: 'check', after: 2, subject: 'Beschreibung', record: 'Seite 3'}],
        });

        await el.chat.loadMessages();
        el.requestUpdate();
        await el.updateComplete;

        expect(el.shadowRoot.querySelector('.proposal-outcome').textContent.trim()).toBe('chat.outcomeCheck Beschreibung');
    });

    test('no summary while the process runs', async () => {
        const el = await render(modulePath, tag, open);

        expect(el.shadowRoot.querySelector('.proposal-summary')).toBeNull();
    });

    test('once done, the summary groups the record; a variant is no group of its own', async () => {
        const el = await render(modulePath, tag, open, {completed: true});
        const summary = el.shadowRoot.querySelector('.proposal-summary');

        expect([...summary.querySelectorAll('h3')].map((h) => h.textContent.trim())).toEqual(['chat.summaryApplied', 'chat.summarySkipped']);
        expect([...summary.querySelectorAll('li')].map((li) => li.textContent.trim())).toEqual(['Beschreibung (Seite 3)', 'Überschrift (Inhaltselement 9)']);
        expect(summary.getAttribute('aria-label')).toBe('chat.summaryLabel');
    });

    test('a skipped create is listed by the change\'s name, without a record', async () => {
        const el = await render(modulePath, tag, open, {completed: true, outcomes: [{outcome: 'skipped', after: 2, subject: 'Inhaltselement anlegen', record: ''}]});

        expect(el.shadowRoot.querySelector('.proposal-summary li').textContent.trim()).toBe('Inhaltselement anlegen');
    });

    test('a done process without decided proposals shows no summary', async () => {
        const el = await render(modulePath, tag, open, {completed: true, outcomes: []});

        expect(el.shadowRoot.querySelector('.proposal-summary')).toBeNull();
    });
});
