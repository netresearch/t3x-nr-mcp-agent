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
        // What the server stores when a run parks: nothing. The notice is
        // rendered from the status, in the reader's language (NEXT-159).
        errorMessage: '',
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
            el.chat.errorMessage = 'a notice the claim did not clear';
            el.requestUpdate();
        });

        el.shadowRoot.querySelectorAll('.approval-actions button')[0].click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await el.updateComplete;

        const notice = el.shadowRoot.querySelector('.message.system');
        expect(notice.textContent).toContain('chat.approvalGranted');
        expect(notice.textContent).not.toContain('chat.errorPrefix');
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

    // NEXT-162. The link used to sit in the action row, button-shaped and
    // labelled "Grant approval", beside the two buttons that actually grant
    // it — and it opens the run's timeline, where nothing can be decided.
    test('a card with a preview offers Approve and Deny and no link at all', async () => {
        const el = await renderPending(modulePath, tag, open);
        const card = el.shadowRoot.querySelector('.approval-card');

        expect(card.querySelectorAll('.approval-actions button')).toHaveLength(2);
        expect(card.querySelector('a')).toBeNull();
    });

    test.each([
        ['a preview that failed or was withheld', {previewLines: ['The preview for this call failed.'], previewFailed: true}],
        ['a tool that offers no preview', {previewLines: [], previewFailed: false}],
    ])('with %s the run is one plain link away, outside the action row', async (_case, preview) => {
        const pending = {...PENDING, calls: [{...PENDING.calls[0], ...preview}]};
        const el = await renderPending(modulePath, tag, open, pending);
        const card = el.shadowRoot.querySelector('.approval-card');
        const links = [...card.querySelectorAll('a')];

        expect(links).toHaveLength(1);
        expect(links[0].getAttribute('href')).toBe('/typo3/module/web/nrllm-aitasks?runUuid=run-uuid-1234');
        expect(links[0].textContent).toContain('chat.approvalOpen');
        // Secondary: a second button-shaped element is what made it compete.
        expect(links[0].classList.contains('btn')).toBe(false);
        expect(card.querySelector('.approval-actions a')).toBeNull();
        expect(card.querySelectorAll('.approval-actions button')).toHaveLength(2);
    });

    test('one call without a preview is enough, even beside one that has it', async () => {
        const pending = {...PENDING, calls: [PENDING.calls[0], {...PENDING.calls[0], name: 'remote_tool', previewLines: []}]};
        const el = await renderPending(modulePath, tag, open, pending);

        expect(el.shadowRoot.querySelectorAll('.approval-card a')).toHaveLength(1);
    });

    test('a call handed back because its record changed says so on the card', async () => {
        const pending = {...PENDING, calls: [{...PENDING.calls[0], previewStale: true}]};
        const el = await renderPending(modulePath, tag, open, pending);

        expect(el.shadowRoot.querySelector('.approval-card').textContent).toContain('chat.approvalPreviewStale');
    });

    test('a call whose preview still holds carries no stale warning', async () => {
        const el = await renderPending(modulePath, tag, open);

        expect(el.shadowRoot.querySelector('.approval-card').textContent).not.toContain('chat.approvalPreviewStale');
    });

    /**
     * NEXT-159. The pause used to write "This step writes data and needs an
     * approval before it runs." into the conversation, and the chat rendered
     * that field verbatim — so the sentence stayed English after the backend
     * was switched to German, and a stored sentence is frozen in the language
     * of the moment it was written anyway. The server stores nothing now; the
     * notice is a label, resolved in the reader's language, and the state
     * alone is what shows it. The card (the fixture has status
     * awaiting_approval and an empty field) is the proof that the empty field
     * no longer hides the notice.
     */
    test('the pending notice is a label resolved on the client, not a stored sentence', async () => {
        const el = await renderPending(modulePath, tag, open);
        const notice = el.shadowRoot.querySelector('.message.system');

        expect(notice).not.toBeNull();
        expect(notice.textContent).toContain('chat.approvalPending');
        expect(notice.textContent).toContain('chat.approvalPendingDetail');
        expect(notice.textContent).not.toContain('chat.errorPrefix');
        expect(notice.querySelector('.approval-actions')).not.toBeNull();
    });

    /**
     * A decision refused by the runtime hands the run back with the reason in
     * the same field — "The turn moved on", a stale digest. That reason is the
     * detail then, not the generic sentence beside it.
     */
    test('a reason handed back with the run replaces the generic sentence', async () => {
        const el = await renderPending(modulePath, tag, open);
        el.chat.errorMessage = 'The turn moved on — decide again.';
        el.requestUpdate();
        await el.updateComplete;
        const notice = el.shadowRoot.querySelector('.message.system');

        expect(notice.textContent).toContain('chat.approvalPending');
        expect(notice.textContent).toContain('The turn moved on — decide again.');
        expect(notice.textContent).not.toContain('chat.approvalPendingDetail');
    });

    /**
     * The other direction of the same gate: an error is still announced, with
     * the prefix from the label file instead of a hardcoded "Error:", and the
     * pending sentence stays out of it.
     */
    test('a failure is announced with the translated prefix and no pending sentence', async () => {
        const el = await renderPending(modulePath, tag, open, null);
        Object.assign(el.chat, {status: 'failed', errorMessage: 'provider exploded', approvalUrl: ''});
        el.requestUpdate();
        await el.updateComplete;
        const notice = el.shadowRoot.querySelector('.message.system');

        expect(notice.textContent).toContain('chat.errorPrefix');
        expect(notice.textContent).toContain('provider exploded');
        expect(notice.textContent).not.toContain('chat.approvalPendingDetail');
        expect(notice.textContent).not.toContain('Error:');
        expect(notice.querySelector('.btn-icon')).not.toBeNull();
    });

    /**
     * The notice is derived from the status, so clearing the field would not
     * hide it — a Dismiss that does nothing is worse than none. The card it
     * carries is where the decision is taken; the error notice keeps its
     * Dismiss (asserted above).
     */
    test('the pending notice offers no dismiss', async () => {
        const el = await renderPending(modulePath, tag, open);

        expect(el.shadowRoot.querySelector('.message.system .btn-icon')).toBeNull();
    });

    test('an idle conversation without an error shows no notice', async () => {
        const el = await renderPending(modulePath, tag, open, null);
        Object.assign(el.chat, {status: 'idle', errorMessage: '', approvalUrl: ''});
        el.requestUpdate();
        await el.updateComplete;

        expect(el.shadowRoot.querySelector('.message.system')).toBeNull();
    });
});
