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

    /**
     * NEXT-156. The click used to be answered with a red "Error: ... waiting for
     * your approval": the notice the pause had written stayed in the field the
     * chat renders as an error, and the conversation moved to Processing, which
     * is resumable — so a Retry button appeared beside it. Pressing that started
     * a second run over the same transcript and created the record twice.
     *
     * The server clears the notice when it records the decision; this asserts
     * the chat is right even if it did not, because the confirmation is what the
     * reader sees either way.
     */
    test('deciding answers with a confirmation, never an error or a retry', async () => {
        const el = await renderPending(modulePath, tag, open);
        el.chat._api.decideApproval = jest.fn().mockResolvedValue({status: 'processing'});
        // What the poll returned before the fix: the stale notice on a Processing
        // conversation. loadMessages is the only thing that writes it, so it is
        // stubbed to put exactly that on screen.
        el.chat.loadMessages = jest.fn().mockImplementation(async () => {
            el.chat.status = 'processing';
            el.chat.errorMessage = 'This step writes data and needs an approval before it runs.';
            el.requestUpdate();
        });

        el.shadowRoot.querySelectorAll('.approval-actions button')[0].click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await el.updateComplete;

        const notice = el.shadowRoot.querySelector('.message.system');
        expect(notice.textContent).toContain('chat.approvalGranted');
        expect(notice.textContent).not.toContain('Error:');
        expect(notice.textContent).not.toContain('chat.retry');
        expect(el.shadowRoot.querySelector('.approval-actions')).toBeNull();
    });

    test('denying says the step was not carried out', async () => {
        const el = await renderPending(modulePath, tag, open);
        el.chat._api.decideApproval = jest.fn().mockResolvedValue({status: 'processing'});
        el.chat.loadMessages = jest.fn().mockResolvedValue(undefined);

        el.shadowRoot.querySelectorAll('.approval-actions button')[1].click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await el.updateComplete;

        expect(el.shadowRoot.querySelector('.message.system').textContent)
            .toContain('chat.approvalDenied');
    });

    /**
     * A refusal hands the run back: what is on screen is a question again, so the
     * confirmation must give way to the card rather than sit above it.
     *
     * loadMessages() is the real one here, over a stubbed transport. Stubbing
     * loadMessages itself — and having the stub clear the confirmation — would
     * pass even if the controller stopped clearing it, which is the only thing
     * this case is about.
     */
    test('a card handed back after a refusal replaces the confirmation', async () => {
        const el = await renderPending(modulePath, tag, open);
        el.chat._api.decideApproval = jest.fn().mockResolvedValue({status: 'processing'});
        el.chat._api.getMessages = jest.fn().mockResolvedValue({
            status: 'awaiting_approval',
            messages: [{role: 'user', content: 'Set the meta description'}],
            totalCount: 1,
            errorMessage: 'The turn moved on — decide again.',
            approvalUrl: '/typo3/module/web/nrllm-aitasks?runUuid=run-uuid-1234',
            pendingApproval: PENDING,
        });

        el.shadowRoot.querySelectorAll('.approval-actions button')[0].click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await el.updateComplete;

        expect(el.chat._api.getMessages).toHaveBeenCalled();
        expect(el.chat.approvalDecisionTaken).toBeNull();
        expect(el.shadowRoot.querySelector('.approval-actions')).not.toBeNull();
    });

    /**
     * The reader can switch conversations while the decision is in flight. The
     * answer belongs to the conversation the click happened in — a confirmation
     * appearing over someone else's transcript is a claim about work that was
     * never done there.
     */
    test('a decision that lands after a conversation switch is discarded', async () => {
        const el = await renderPending(modulePath, tag, open);
        el.chat._api.decideApproval = jest.fn().mockImplementation(async () => {
            // The switch happens while the request is in flight.
            el.chat.activeUid = 2;
            return {status: 'processing'};
        });
        el.chat.loadMessages = jest.fn().mockResolvedValue(undefined);

        el.shadowRoot.querySelectorAll('.approval-actions button')[0].click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await el.updateComplete;

        expect(el.chat.approvalDecisionTaken).toBeNull();
        expect(el.chat.loadMessages).not.toHaveBeenCalled();
        expect(el.chat._api.decideApproval).toHaveBeenCalledWith(1, true, 'digest-abc');
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
