// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The approval card is a system step, not a chat message (ADR-023, amendment
 * of 2026-10-23): the LLM orders a write, the system pauses because the
 * backend requires approval, and the frame says so. These tests pin what makes
 * it read that way, on both surfaces: it sits outside every message element,
 * carries a header band, spans the column and is not styled like the italic
 * status lines. They also pin who sees the technical details, the heading of a
 * one-field change and the "This page" row.
 */

import {describe, test, expect, beforeAll} from '@jest/globals';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {affectedText} from '../../Resources/Public/JavaScript/chat-proposal.js';

const SURFACES = [
    {name: 'chat-app (full page)', file: 'chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', file: 'ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const CALL = {
    name: 'update_page_metadata',
    actionLabel: 'Seiten-Metadaten ändern',
    heading: 'Seiten-Metadaten ändern',
    toolStillRegistered: true,
    previewLines: ['Seite: „Home“'],
    technicalDetails: 'Seite 10002, Feld description',
    previewFailed: false,
    argumentsJson: '{"pageUid":10002}',
};

const PENDING = {
    runUuid: 'run-1', turnDigest: 'digest', configLabel: 'Demo', unreadableReason: null, answers: 'process', calls: [CALL],
};

async function render(file, tag, open, {pending = PENDING, showTechnicalDetails = true} = {}) {
    await import(`../../Resources/Public/JavaScript/${file}`);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1, available: true, loading: false, issues: [],
        conversations: [{uid: 1, title: 'A chat', status: 'awaiting_approval', messageCount: 2, pinned: false}],
        messages: [{role: 'user', content: 'Setze die Beschreibung'}, {role: 'assistant', content: 'Ich schlage vor ...'}],
        status: 'awaiting_approval', errorMessage: '', approvalUrl: '', pendingApproval: pending, showTechnicalDetails,
    });
    if (open) {
        el.state = 'OPEN';
    }
    el.requestUpdate();
    await el.updateComplete;

    return el;
}

const source = (file) => readFileSync(fileURLToPath(new URL(`../../Resources/Public/JavaScript/${file}`, import.meta.url)), 'utf8');
const ruleBody = (file, selector) => [...source(file).replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)]
    .find(([, sel]) => sel.split(',').map((s) => s.trim()).includes(selector))?.[2].trim();

describe.each(SURFACES)('$name approval frame', ({file, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('the card is not inside a message bubble, a message row or a system line', async () => {
        const el = await render(file, tag, open);
        const frame = el.shadowRoot.querySelector('.approval-frame');

        expect(frame).not.toBeNull();
        expect(frame.querySelector('.approval-card')).not.toBeNull();
        expect(frame.closest('.message, .message-row, .message-bubble, .system')).toBeNull();
        expect(frame.querySelector('.avatar, .message, .message-bubble')).toBeNull();
        // The assistant's own message is still a bubble beside it.
        expect(el.shadowRoot.querySelector('.message.assistant')).not.toBeNull();
    });

    test('a header band names the step before the pending line, which is the first text below it', async () => {
        const el = await render(file, tag, open);
        const frame = el.shadowRoot.querySelector('.approval-frame');
        const header = frame.querySelector('.approval-frame-header');

        expect(header.textContent.trim()).toBe('chat.approvalHeader');
        expect(header.querySelector('svg').getAttribute('aria-hidden')).toBe('true');
        expect(frame.firstElementChild).toBe(header);
        expect(frame.querySelector('.approval-frame-body').firstElementChild.textContent.trim()).toBe('chat.approvalPending');
    });

    test('focus handling of the status notice stays on the frame', async () => {
        const el = await render(file, tag, open);
        const frame = el.shadowRoot.querySelector('.status-notice');

        expect(frame.classList.contains('approval-frame')).toBe(true);
        expect(frame.getAttribute('tabindex')).toBe('-1');
    });

    test('the frame spans the column in normal text, unlike the italic status lines', () => {
        const frame = ruleBody(file, '.approval-frame');

        expect(frame).toContain('align-self: stretch;');
        expect(frame).toContain('font-style: normal;');
        expect(frame).toContain('color: var(--nr-chat-text);');
        expect(frame).toContain('border: 1px solid');
        expect(ruleBody(file, '.message.system')).toContain('font-style: italic;');
    });

    test('message text is 14px and the answer buttons are 14px with 6px 12px', async () => {
        expect(ruleBody(file, '.message')).toContain('font-size: 14px;');
        expect(ruleBody(file, '.btn-answer')).toBe('padding: 6px 12px;\n            font-size: 14px;');
        // The compact header buttons keep the small size.
        expect(ruleBody(file, '.btn-sm')).toContain('font-size: 12px;');

        const el = await render(file, tag, open);
        const buttons = [...el.shadowRoot.querySelectorAll('.approval-actions button')];
        expect(buttons).toHaveLength(3);
        expect(buttons.every((b) => b.classList.contains('btn-answer'))).toBe(true);
    });

    describe.each([['a proposal', 'process'], ['a plain card', 'plain']])('technical details of %s', (_label, answers) => {
        test('are absent for a reader who is not an administrator', async () => {
            const el = await render(file, tag, open, {pending: {...PENDING, answers}, showTechnicalDetails: false});
            const frame = el.shadowRoot.querySelector('.approval-frame');

            expect(frame.querySelector('details')).toBeNull();
            expect(frame.querySelector('.approval-technical')).toBeNull();
            expect(frame.textContent).not.toContain('chat.approvalTechnicalDetails');
        });

        test('are present for an administrator', async () => {
            const el = await render(file, tag, open, {pending: {...PENDING, answers}, showTechnicalDetails: true});
            const details = el.shadowRoot.querySelector('.approval-frame details.approval-technical');

            expect(details).not.toBeNull();
            expect(details.textContent).toContain('update_page_metadata');
        });
    });

    test('the flag comes from the response and survives a response without it', async () => {
        const el = await render(file, tag, open, {showTechnicalDetails: false});
        el.chat._setApprovalRight({mayDecideApproval: true, showTechnicalDetails: true});
        expect(el.chat.showTechnicalDetails).toBe(true);
        el.chat._setApprovalRight({});
        expect(el.chat.showTechnicalDetails).toBe(true);
        el.chat._setApprovalRight({showTechnicalDetails: false});
        expect(el.chat.showTechnicalDetails).toBe(false);
    });

    test('the heading names the field, while the plain card keeps its action as the button', async () => {
        const call = {...CALL, heading: '„Meta Description“ ändern'};
        const plain = await render(file, tag, open, {pending: {...PENDING, answers: 'plain', calls: [call]}});
        expect(plain.shadowRoot.querySelector('.approval-title').textContent.trim()).toBe('„Meta Description“ ändern');
        expect(plain.shadowRoot.querySelector('.approval-actions button').textContent.trim()).toBe('Seiten-Metadaten ändern');

        const proposal = await render(file, tag, open, {pending: {...PENDING, calls: [call]}});
        expect(proposal.shadowRoot.querySelector('.approval-title').textContent.trim()).toBe('„Meta Description“ ändern');
    });

    test('a call without a heading falls back to the action label', async () => {
        const {heading: _omitted, ...withoutHeading} = CALL;
        const el = await render(file, tag, open, {pending: {...PENDING, calls: [withoutHeading]}});

        expect(el.shadowRoot.querySelector('.approval-title').textContent.trim()).toBe('Seiten-Metadaten ändern');
    });
});

describe('the "Betroffen" row', () => {
    const affected = {table: 'pages', uid: 10002, fields: ['description'], tableLabel: 'Seite', fieldLabels: ['Beschreibung']};

    test('names the page the conversation is about as this page, without table and uid', () => {
        expect(affectedText({...affected, thisPage: true})).toBe('chat.proposalThisPage · Beschreibung');
        expect(affectedText({...affected, thisPage: true, fields: [], fieldLabels: []})).toBe('chat.proposalThisPage');
    });

    test('keeps table and uid for any other record', () => {
        expect(affectedText({...affected, thisPage: false})).toBe('Seite 10002 · Beschreibung');
        expect(affectedText(affected)).toBe('Seite 10002 · Beschreibung');
        expect(affectedText({...affected, table: 'tt_content', tableLabel: 'Inhaltselement', uid: 7, thisPage: false}))
            .toBe('Inhaltselement 7 · Beschreibung');
    });
});
