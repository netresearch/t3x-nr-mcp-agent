// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The proposal of a guided process (ADR-023) on both chat surfaces: the
 * approval card of a write in a process run, as a quoted block with what is
 * affected and what changes, and the three answers directly below it.
 *
 * Two forms, both from the server's card and never from the model's text:
 * with nr-llm's structured preview, "Aktuell", "Vorschlag" and the length;
 * without it, nr-llm's preview lines as they are.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';
import {affectedText, isProposal} from '../../Resources/Public/JavaScript/chat-proposal.js';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const CALL = {
    name: 'update_page_metadata',
    actionLabel: 'Meta-Description fehlt',
    toolStillRegistered: true,
    previewLines: ['Seite: „Home“', 'Beschreibung: (leer) → „Neu“'],
    technicalDetails: 'Seite 10002, Feld description',
    previewFailed: false,
    previewStale: false,
    argumentsJson: '{"pageUid":10002}',
    affected: {table: 'pages', uid: 10002, fields: ['description'], tableLabel: 'Seite', fieldLabels: ['Beschreibung']},
    structured: null,
};

const PROPOSAL = {runUuid: 'run-1', turnDigest: 'digest-abc', configLabel: 'Demo', unreadableReason: null, answers: 'process', calls: [CALL]};

async function render(modulePath, tag, open, pendingApproval) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'SEO', status: 'awaiting_approval', messageCount: 1, pinned: false}],
        messages: [{role: 'user', content: 'Seite prüfen'}],
        status: 'awaiting_approval',
        errorMessage: '',
        approvalUrl: '',
        pendingApproval,
    });
    if (open) {
        el.state = 'OPEN';
    }
    el.requestUpdate();
    await el.updateComplete;

    return el;
}

/** The description list as [term, description] pairs. */
function facts(el) {
    const items = [...el.shadowRoot.querySelectorAll('.proposal-facts > *')];
    const pairs = [];
    for (let i = 0; i < items.length; i += 2) {
        pairs.push([items[i].textContent.trim(), items[i + 1]?.textContent.replace(/\s+/g, ' ').trim()]);
    }

    return pairs;
}

describe('which card is a proposal', () => {
    test('a write in a process run with one call', () => {
        expect(isProposal(PROPOSAL)).toBe(true);
        expect(isProposal({...PROPOSAL, answers: 'plain'})).toBe(false);
        expect(isProposal({...PROPOSAL, calls: [CALL, CALL]})).toBe(false);
        expect(isProposal(null)).toBe(false);
    });

    test('"Betroffen" names the record and the fields by their labels', () => {
        expect(affectedText(CALL.affected)).toBe('Seite 10002 · Beschreibung');
        expect(affectedText({...CALL.affected, fields: [], fieldLabels: []})).toBe('Seite 10002');
        expect(affectedText(null)).toBe('');
    });
});

describe.each(SURFACES)('$name proposal', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('the reduced form: heading, "Betroffen" and nr-llm\'s lines as given', async () => {
        const el = await render(modulePath, tag, open, PROPOSAL);
        const block = el.shadowRoot.querySelector('.proposal');

        expect(block.querySelector('.approval-title').textContent.trim()).toBe('Meta-Description fehlt');
        expect(facts(el)).toEqual([['chat.proposalAffected', 'Seite 10002 · Beschreibung']]);
        expect([...block.querySelectorAll('.approval-preview li')].map((li) => li.textContent)).toEqual(CALL.previewLines);
        expect(block.textContent).not.toContain('chat.proposalCurrent');
    });

    test('without a target there is nothing to call "Betroffen", only the lines', async () => {
        const el = await render(modulePath, tag, open, {...PROPOSAL, calls: [{...CALL, affected: null}]});

        expect(el.shadowRoot.querySelector('.proposal-facts')).toBeNull();
        expect(el.shadowRoot.querySelectorAll('.proposal .approval-preview li')).toHaveLength(2);
    });

    test('with nr-llm\'s structured preview: current, proposed and the length in range', async () => {
        const structured = [{field: 'description', current: '', proposed: 'Neu und gut', measure: {count: 152, min: 140, max: 160}}];
        const el = await render(modulePath, tag, open, {...PROPOSAL, calls: [{...CALL, structured}]});

        expect(facts(el)).toEqual([
            ['chat.proposalAffected', 'Seite 10002 · Beschreibung'],
            ['chat.proposalCurrent', 'chat.proposalEmpty'],
            ['chat.proposalProposed', 'Neu und gut'],
            ['chat.proposalLength', '152 chat.proposalCharacters (chat.proposalTarget 140–160)'],
        ]);
        expect(el.shadowRoot.querySelector('.proposal-measure').dataset.within).toBe('true');
        // The structured values replace the lines, which would say the same.
        expect(el.shadowRoot.querySelector('.proposal .approval-preview')).toBeNull();
    });

    test('a length outside the range says so in words', async () => {
        const structured = [{field: 'description', current: 'Alt', proposed: 'Zu lang', measure: {count: 171, min: 140, max: 160}}];
        const el = await render(modulePath, tag, open, {...PROPOSAL, calls: [{...CALL, structured}]});
        const measure = el.shadowRoot.querySelector('.proposal-measure');

        expect(measure.dataset.within).toBe('false');
        expect(measure.textContent).toContain('chat.proposalOutOfRange');
    });

    test('with several fields each term names its field', async () => {
        const call = {
            ...CALL,
            affected: {...CALL.affected, fields: ['description', 'title'], fieldLabels: ['Beschreibung', 'Titel']},
            structured: [
                {field: 'description', current: 'a', proposed: 'b', measure: null},
                {field: 'title', current: 'c', proposed: 'd', measure: null},
            ],
        };
        const el = await render(modulePath, tag, open, {...PROPOSAL, calls: [call]});

        expect(facts(el).map(([term]) => term)).toEqual([
            'chat.proposalAffected',
            'chat.proposalCurrent · Beschreibung', 'chat.proposalProposed · Beschreibung',
            'chat.proposalCurrent · Titel', 'chat.proposalProposed · Titel',
        ]);
    });

    test('the three answers sit directly under the block, the details after them', async () => {
        const el = await render(modulePath, tag, open, PROPOSAL);
        const card = el.shadowRoot.querySelector('.proposal-card');
        const order = [...card.children].map((child) => child.className || child.localName);

        expect(order.indexOf('proposal')).toBe(order.indexOf('approval-actions') - 1);
        expect(order.indexOf('approval-actions')).toBeLessThan(order.indexOf('approval-technical'));
        expect([...card.querySelectorAll('.approval-actions button')].map((b) => b.textContent.trim()))
            .toEqual(['chat.approvalApply', 'chat.approvalVariant', 'chat.approvalSkip']);
        expect(card.querySelector('details').hasAttribute('open')).toBe(false);
    });

    test.each([
        [0, true, ''],
        [1, false, 'variant'],
        [2, false, 'skip'],
    ])('answer %i sends approve=%s with reason "%s"', async (index, approve, reason) => {
        const el = await render(modulePath, tag, open, PROPOSAL);
        el.chat._api.decideApproval = jest.fn().mockResolvedValue({status: 'processing'});
        el.chat.loadMessages = jest.fn().mockResolvedValue(undefined);

        el.shadowRoot.querySelectorAll('.approval-actions button')[index].click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(el.chat._api.decideApproval).toHaveBeenCalledWith(1, approve, 'digest-abc', reason);
        el.chat.stopPolling();
    });

    test('a plain card and a card with several calls keep the ordinary card', async () => {
        const plain = await render(modulePath, tag, open, {...PROPOSAL, answers: 'plain'});
        expect(plain.shadowRoot.querySelector('.proposal')).toBeNull();

        const several = await render(modulePath, tag, open, {...PROPOSAL, calls: [CALL, CALL]});
        expect(several.shadowRoot.querySelector('.proposal')).toBeNull();
    });
});
