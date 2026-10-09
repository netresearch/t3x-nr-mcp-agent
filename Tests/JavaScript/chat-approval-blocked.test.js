// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * A card the reader may decide but not approve (ADR-021): four-eyes, or an
 * editor deciding by the chat's own permission on a change without a preview.
 * The approve button is gone, the reason says who can approve, and the
 * denial still reaches the API.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const PENDING = {
    runUuid: 'run-uuid-1',
    turnDigest: 'digest-1',
    configLabel: 'Demo',
    unreadableReason: null,
    approveBlocked: '',
    calls: [{
        name: 'update_page_metadata',
        actionLabel: 'Meta Description speichern',
        toolStillRegistered: true,
        previewLines: ['Seite: „Home“'],
        previewFailed: false,
        argumentsJson: '{}',
    }],
};

async function render(modulePath, tag, open, pending) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'Meta', status: 'awaiting_approval', messageCount: 1, pinned: false}],
        messages: [{role: 'user', content: 'Setze die Meta Description'}],
        status: 'awaiting_approval',
        errorMessage: '',
        approvalUrl: '/typo3/module/web/nrllm-aitasks?runUuid=run-uuid-1',
        mayDecideApproval: true,
        pendingApproval: pending,
    });
    if (open) el.state = 'OPEN';
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

describe.each(SURFACES)('$name card without an approve button', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test.each([
        ['secondApprover', 'chat.approvalSecondApprover'],
        ['preview', 'chat.approvalOwnNeedsPreview'],
    ])('%s: only the denial remains, and the card says who approves', async (reason, label) => {
        const el = await render(modulePath, tag, open, {...PENDING, approveBlocked: reason});
        const card = el.shadowRoot.querySelector('.approval-card');
        const buttons = [...card.querySelectorAll('.approval-actions button')];

        expect(buttons.map((b) => b.textContent.trim())).toEqual(['chat.approvalCancel']);
        expect(card.querySelector('.approval-blocked').textContent.trim()).toBe(label);
        expect(card.querySelector('a').getAttribute('href')).toBe('/typo3/module/web/nrllm-aitasks?runUuid=run-uuid-1');

        el.chat._api.decideApproval = jest.fn().mockResolvedValue({status: 'processing'});
        el.chat.loadMessages = jest.fn().mockResolvedValue(undefined);
        buttons[0].click();
        await el.updateComplete;
        expect(el.chat._api.decideApproval).toHaveBeenCalledWith(1, false, 'digest-1');
    });

    test('an approvable card keeps its approve button and says nothing extra', async () => {
        const el = await render(modulePath, tag, open, PENDING);

        expect(el.shadowRoot.querySelectorAll('.approval-actions button')).toHaveLength(2);
        expect(el.shadowRoot.querySelector('.approval-blocked')).toBeNull();
    });
});
