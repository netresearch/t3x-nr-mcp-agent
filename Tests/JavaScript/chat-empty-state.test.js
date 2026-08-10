/**
 * Rendering tests for the chat empty state.
 *
 * The suite next door notes that Lit components "resolve 'lit' via the TYPO3
 * backend importmap, which is not available under Jest" and falls back to
 * static source analysis for that reason. That is a resolution problem, not a
 * property of the components: `lit` is a plain npm package, so mapping the bare
 * specifier at it makes the components render under jsdom like any other custom
 * element. Once they render, behaviour can be asserted instead of grepped —
 * a source grep cannot tell whether a button is reachable or what it does when
 * clicked, which is precisely what the empty state was getting wrong.
 *
 * Both surfaces are covered: the sentence being fixed was identical in the
 * full-page module and in the popup panel, so a test that only saw one of them
 * would pass while half the defect remained.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';

// The components pull the backend's translation helper; the project mock returns
// the key, which is what the assertions below match on.
const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

/**
 * Render one surface with no active conversation — the empty state.
 *
 * @param {string} modulePath
 * @param {string} tag
 * @param {{available?: boolean}} chatState
 */
async function renderEmptyState(modulePath, tag, chatState = {}, open = false) {
    await import(modulePath);

    const el = document.createElement(tag);
    document.body.append(el);

    // The controller owns the state the render branches on. Overriding the
    // instance the component created keeps the component's own wiring intact.
    // `loading` gates the whole render: while it is true the component shows a
    // spinner and nothing below it exists yet.
    Object.assign(el.chat, {
        activeUid: null,
        available: true,
        loading: false,
        issues: [],
        conversations: [],
        ...chatState,
    });

    // The popup starts hidden and renders nothing until it is opened.
    if (open) {
        el.state = 'OPEN';
    }

    el.requestUpdate();
    await el.updateComplete;

    return el;
}

describe.each(SURFACES)('$name empty state', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.innerHTML = '';
    });

    test('offers the primary action instead of only naming it', async () => {
        const el = await renderEmptyState(modulePath, tag, {}, open);
        const button = el.shadowRoot.querySelector('.empty-state-guidance button');

        // The defect: the only way to start a chat was an icon button in the
        // sidebar header. The empty state must carry one itself.
        expect(button).not.toBeNull();
        expect(button.disabled).toBe(false);
    });

    test('starting a chat from the empty state calls the controller', async () => {
        const el = await renderEmptyState(modulePath, tag, {}, open);
        const handler = jest.fn();
        el.chat.handleNewConversation = handler;

        el.shadowRoot.querySelector('.empty-state-guidance button').click();

        // A button that renders but is wired to nothing looks identical in a
        // source grep, which is why this asserts the click and not the markup.
        expect(handler).toHaveBeenCalledTimes(1);
    });

    test('explains what the assistant is for, not just that a chat can start', async () => {
        const el = await renderEmptyState(modulePath, tag, {}, open);
        const text = el.shadowRoot.querySelector('.empty-state-guidance').textContent;

        expect(text).toContain('chat.empty.title');
        expect(text).toContain('chat.empty.body');
    });

    test('an unavailable chat still says so and offers nothing to press', async () => {
        const el = await renderEmptyState(modulePath, tag, {available: false}, open);
        // chat-app renders a second .empty-state in the sidebar ("no conversations
        // yet") and it comes first in document order, so a comma selector picks
        // the wrong one — querySelector returns the first match in the document,
        // it does not prefer the earlier branch of the selector list.
        const emptyState = el.shadowRoot.querySelector('.main .empty-state')
            ?? el.shadowRoot.querySelector('.empty-state');

        expect(emptyState.textContent).toContain('chat.notAvailable');
        expect(emptyState.querySelector('button')).toBeNull();
    });

    test('the guidance disappears once a conversation is active', async () => {
        const el = await renderEmptyState(modulePath, tag, {activeUid: 42}, open);

        expect(el.shadowRoot.querySelector('.empty-state-guidance')).toBeNull();
    });
});
