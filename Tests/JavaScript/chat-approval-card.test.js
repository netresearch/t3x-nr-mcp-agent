/**
 * Rendering and behaviour tests for the in-chat approval card.
 *
 * The card is the whole point of deciding in the chat rather than in the AI
 * Tasks module, so the assertions are about what a reader can see and press:
 * what the call would do, and two buttons that reach the API with the digest
 * the card carried. A card that renders but is wired to nothing looks identical
 * in a source grep.
 *
 * Both surfaces are covered — the notice exists twice, in the full-page module
 * and in the popup.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const PENDING = {
    runUuid: 'run-uuid-1234',
    turnDigest: 'digest-abc',
    configLabel: 'Demo agent',
    unreadableReason: null,
    calls: [{
        name: 'update_page_metadata',
        toolStillRegistered: true,
        previewLines: ['Page [10002] description: (empty) → TYPO3 is a free and open source enterprise CMS'],
        previewFailed: false,
        argumentsJson: '{"pageUid":10002,"description":"TYPO3 is a free and open source enterprise CMS"}',
    }],
};

/**
 * @param {string} modulePath
 * @param {string} tag
 * @param {boolean} open
 * @param {object|null} pendingApproval
 */
async function renderPending(modulePath, tag, open, pendingApproval = PENDING) {
    await import(modulePath);

    const el = document.createElement(tag);
    document.body.append(el);

    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'A chat', status: 'awaiting_approval', messageCount: 1, pinned: false}],
        messages: [{role: 'user', content: 'Set the meta description'}],
        status: 'awaiting_approval',
        errorMessage: 'This step writes data, so it is waiting for your approval.',
        approvalUrl: '/typo3/module/web/nrllm-aitasks?runUuid=run-uuid-1234',
        pendingApproval,
    });

    if (open) {
        el.state = 'OPEN';
    }

    el.requestUpdate();
    await el.updateComplete;

    return el;
}

describe.each(SURFACES)('$name approval card', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('shows what the call would do, not just that one is pending', async () => {
        const el = await renderPending(modulePath, tag, open);
        const card = el.shadowRoot.querySelector('.approval-card');

        expect(card).not.toBeNull();
        expect(card.textContent).toContain('update_page_metadata');
        expect(card.textContent).toContain('TYPO3 is a free and open source enterprise CMS');
    });

    test('approving reaches the API with the digest the card carried', async () => {
        const el = await renderPending(modulePath, tag, open);
        // 202: recorded, not carried out. The outcome arrives through the poll.
        const decide = jest.fn().mockResolvedValue({status: 'processing'});
        // Through the real chain: the button calls the controller, which calls
        // the API client. Stubbing the controller method would assert nothing.
        el.chat._api.decideApproval = decide;
        el.chat.loadMessages = jest.fn().mockResolvedValue(undefined);

        const buttons = [...el.shadowRoot.querySelectorAll('.approval-actions button')];
        expect(buttons).toHaveLength(2);
        buttons[0].click();
        await el.updateComplete;

        expect(decide).toHaveBeenCalledWith(1, true, 'digest-abc');
    });

    test('denying sends the opposite decision, not a second approval', async () => {
        const el = await renderPending(modulePath, tag, open);
        const decide = jest.fn().mockResolvedValue({status: 'processing'});
        // Through the real chain: the button calls the controller, which calls
        // the API client. Stubbing the controller method would assert nothing.
        el.chat._api.decideApproval = decide;
        el.chat.loadMessages = jest.fn().mockResolvedValue(undefined);

        [...el.shadowRoot.querySelectorAll('.approval-actions button')][1].click();
        await el.updateComplete;

        expect(decide).toHaveBeenCalledWith(1, false, 'digest-abc');
    });

    test('after deciding, the conversation is followed rather than awaited', async () => {
        // The endpoint answers 202 and a worker carries the decision out, so the
        // outcome arrives the way a sent message's answer arrives: by polling.
        const el = await renderPending(modulePath, tag, open);
        el.chat._api.decideApproval = jest.fn().mockResolvedValue({status: 'processing'});
        el.chat.loadMessages = jest.fn().mockResolvedValue(undefined);
        const poll = jest.spyOn(el.chat, 'startPollingIfNeeded');

        el.shadowRoot.querySelectorAll('.approval-actions button')[0].click();
        // The click handler is async and updateComplete does not await its
        // chain; let the pending promises settle first.
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(poll).toHaveBeenCalled();
    });

    test('a run whose state cannot be read says so instead of offering a decision', async () => {
        const el = await renderPending(modulePath, tag, open, {...PENDING, unreadableReason: 'state-unreadable', calls: []});

        expect(el.shadowRoot.querySelector('.approval-actions')).toBeNull();
        expect(el.shadowRoot.querySelector('.approval-card').textContent)
            .toContain('chat.approvalUnreadable');
    });

    test('without a pending call the notice keeps the link and shows no card', async () => {
        // The uuid can be present while the run detail is not readable from
        // here — then the module link is all the notice can offer.
        const el = await renderPending(modulePath, tag, open, null);

        expect(el.shadowRoot.querySelector('.approval-card')).toBeNull();
        expect(el.shadowRoot.querySelector('.message.system a')).not.toBeNull();
    });
});
