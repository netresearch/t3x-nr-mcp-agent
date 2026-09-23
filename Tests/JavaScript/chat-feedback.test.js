/**
 * Rendering tests for the two feedback surfaces NEXT-167 added (ADR-017):
 *
 * - an assistant message that reads like a finished change while the run
 *   wrote nothing carries the "nothing was saved" notice;
 * - a configuration failure shown to an administrator carries a link to where
 *   it is fixed, and only while that failure is the message on screen.
 *
 * Both surfaces are asserted, as in chat-approval-link.test.js: the message
 * list and the error notice exist once in the full-page module and once in the
 * popup.
 */

import {describe, test, expect, beforeEach} from '@jest/globals';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

/**
 * The answer from demo conversation 92 (U29): "Erledigt" with record uids the
 * model made up, in a run that called no tool.
 */
const FALSE_SUCCESS = 'Erledigt:\n\n- Seite bearbeitet: `pages:10041`\n'
    + '- Seitentitel in Englisch geändert auf: `Corporate Partnerships and Projects`\n'
    + '- Genau ein neues Text-Element als Entwurf erstellt:\n  - Inhaltselement: `tt_content:10162`';

async function render(modulePath, tag, open, chatState) {
    await import(modulePath);

    const el = document.createElement(tag);
    document.body.append(el);

    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'A chat', status: 'idle', messageCount: 2, pinned: false}],
        status: 'idle',
        errorMessage: '',
        approvalUrl: '',
        ...chatState,
    });

    if (open) {
        el.state = 'OPEN';
    }

    el.requestUpdate();
    await el.updateComplete;

    return el;
}

describe.each(SURFACES)('$name feedback', ({module: modulePath, tag, open}) => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    test('an answer the run did not back with a write says that nothing was saved', async () => {
        const el = await render(modulePath, tag, open, {
            messages: [
                {role: 'user', content: '10041'},
                {role: 'assistant', content: FALSE_SUCCESS, notice: 'nothingSaved'},
            ],
        });

        const notice = el.shadowRoot.querySelector('.message-row.assistant .message-notice');
        expect(notice).not.toBeNull();
        expect(notice.textContent).toContain('chat.nothingSaved');
    });

    test('an answer without the notice carries none', async () => {
        const el = await render(modulePath, tag, open, {
            messages: [
                {role: 'user', content: '10041'},
                {role: 'assistant', content: FALSE_SUCCESS},
            ],
        });

        expect(el.shadowRoot.querySelector('.message-notice')).toBeNull();
    });

    test('a configuration failure links an administrator to where it is fixed', async () => {
        const el = await render(modulePath, tag, open, {
            messages: [{role: 'user', content: 'what is the last LLM error about?'}],
            status: 'failed',
            errorMessage: 'API key identifier is required for provider OpenAI',
            errorLink: '/typo3/module/nrllm/providers',
            errorLinkLabel: 'error.openProviders',
            errorLinkMessage: 'API key identifier is required for provider OpenAI',
        });

        const link = el.shadowRoot.querySelector('.message.system a');
        expect(link).not.toBeNull();
        expect(link.getAttribute('href')).toBe('/typo3/module/nrllm/providers');
        expect(link.textContent).toContain('error.openProviders');
    });

    test('a local error that replaced the failure takes no link along', async () => {
        const el = await render(modulePath, tag, open, {
            messages: [{role: 'user', content: 'hello'}],
            status: 'failed',
            errorMessage: 'chat.connectionLost',
            errorLink: '/typo3/module/nrllm/providers',
            errorLinkLabel: 'error.openProviders',
            errorLinkMessage: 'API key identifier is required for provider OpenAI',
        });

        expect(el.shadowRoot.querySelector('.message.system a')).toBeNull();
    });
});
