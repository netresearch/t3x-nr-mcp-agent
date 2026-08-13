/**
 * Rendering tests for the pending-approval notice.
 *
 * The notice already said where the approval is granted. Naming a module is
 * not the same as reaching it: the approvals inbox lists every run the user
 * may act on, so a reader still has to find theirs. This covers the link that
 * points at the run itself.
 *
 * Both surfaces are asserted. The notice exists twice, once in the full-page
 * module and once in the popup, so a test that saw only one of them would pass
 * with half the feature missing.
 */

import {describe, test, expect, beforeAll} from '@jest/globals';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

/**
 * Render one surface with a conversation parked on an approval.
 *
 * @param {string} modulePath
 * @param {string} tag
 * @param {boolean} open
 * @param {string} approvalUrl
 */
async function renderAwaitingApproval(modulePath, tag, open, approvalUrl) {
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
        approvalUrl,
    });

    if (open) {
        el.state = 'OPEN';
    }

    el.requestUpdate();
    await el.updateComplete;

    return el;
}

describe.each(SURFACES)('$name pending approval', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('links to the run that is waiting, not just to the module', async () => {
        const el = await renderAwaitingApproval(modulePath, tag, open, '/typo3/module/web/nrllm-aitasks?runUuid=abc');
        const link = el.shadowRoot.querySelector('.message.system a');

        expect(link).not.toBeNull();
        expect(link.getAttribute('href')).toBe('/typo3/module/web/nrllm-aitasks?runUuid=abc');
        expect(link.textContent).toContain('chat.approvalOpen');
    });

    test('offers no link when there is no run to link to', async () => {
        // The uuid is empty when the run could not be persisted. A link to
        // nowhere is worse than the sentence alone.
        const el = await renderAwaitingApproval(modulePath, tag, open, '');

        expect(el.shadowRoot.querySelector('.message.system a')).toBeNull();
        expect(el.shadowRoot.querySelector('.message.system').textContent)
            .toContain('chat.approvalPending');
    });

    test('shows no retry button, because retrying would bypass the pending decision', async () => {
        const el = await renderAwaitingApproval(modulePath, tag, open, '/typo3/module/web/nrllm-aitasks?runUuid=abc');
        const notice = el.shadowRoot.querySelector('.message.system');

        expect(notice.textContent).not.toContain('chat.retry');
    });
});
