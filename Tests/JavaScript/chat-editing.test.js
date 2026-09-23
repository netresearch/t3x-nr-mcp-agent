/**
 * Editing a sent message, the conversation's instructions, and the backend
 * context sent with a turn (NEXT-172).
 */

import {describe, test, expect, jest, beforeEach} from '@jest/globals';
import {ChatCoreController, currentBackendContext} from '../../Resources/Public/JavaScript/chat-core.js';

function fakeBackendWindow({module = 'web_layout', search = '?id=12&token=abc'} = {}) {
    const doc = document.implementation.createHTMLDocument('backend');
    const router = doc.createElement('typo3-backend-module-router');
    router.module = module;
    doc.body.append(router);
    return {document: doc, frames: {list_frame: {location: {search}}}};
}

function controller(state = {}) {
    const host = {addController() {}, requestUpdate() {}, onScrollToBottom() {}, onFocusInput() {}, onResetInput() {}, isConnected: true};
    const chat = new ChatCoreController(host);
    chat._api = {
        editMessage: jest.fn().mockResolvedValue({status: 'processing'}),
        sendMessage: jest.fn().mockResolvedValue({status: 'processing'}),
        updateSystemPrompt: jest.fn(async (uid, prompt) => ({systemPrompt: prompt})),
        getMessages: jest.fn().mockResolvedValue({status: 'processing', messages: [], totalCount: 1}),
    };
    Object.assign(chat, {
        activeUid: 7,
        available: true,
        status: 'idle',
        conversations: [{uid: 7, title: 'x', status: 'idle'}],
        messages: [
            {role: 'user', content: 'first'},
            {role: 'assistant', content: 'answer'},
            {role: 'user', content: [{type: 'text', text: 'structured'}]},
        ],
        ...state,
    });
    return chat;
}

describe('currentBackendContext', () => {
    test('reads the open module and the page of the module frame', () => {
        expect(currentBackendContext(fakeBackendWindow())).toEqual({pageId: 12, module: 'web_layout'});
    });

    test('a module frame without a page id sends no page', () => {
        expect(currentBackendContext(fakeBackendWindow({module: 'tools_toolsmaintenance', search: '?token=abc'})))
            .toEqual({pageId: 0, module: 'tools_toolsmaintenance'});
    });

    test('anything that is not an identifier or a number is dropped', () => {
        expect(currentBackendContext(fakeBackendWindow({module: '../x', search: '?id=12abc'})))
            .toEqual({pageId: 0, module: ''});
    });

    test('an unreadable window degrades to no context', () => {
        const hostile = {get document() { throw new Error('cross-origin'); }};
        expect(currentBackendContext(hostile)).toEqual({pageId: 0, module: ''});
    });
});

describe('editing a sent message', () => {
    test('only a plain-text user message outside a running turn is editable', () => {
        const chat = controller();
        expect(chat.canEditMessage(0)).toBe(true);
        expect(chat.canEditMessage(1)).toBe(false);
        expect(chat.canEditMessage(2)).toBe(false);
        expect(chat.canEditMessage(9)).toBe(false);

        chat.status = 'processing';
        expect(chat.canEditMessage(0)).toBe(false);
    });

    test('submitting sends the index and the trimmed text, then reloads the transcript', async () => {
        const chat = controller();
        chat.startEdit(0);
        expect(chat.editDraft).toBe('first');
        chat.editDraft = '  better  ';

        await chat.submitEdit();

        expect(chat._api.editMessage).toHaveBeenCalledWith(7, 0, 'better', expect.objectContaining({pageId: expect.any(Number), module: expect.any(String)}));
        expect(chat._api.getMessages).toHaveBeenCalledWith(7, 0);
        expect(chat.editingIndex).toBe(-1);
        chat.stopPolling();
    });

    test('a refused edit keeps the editor open with the reason', async () => {
        const chat = controller();
        chat._api.editMessage.mockRejectedValue(new Error('Conversation is already processing'));
        chat.startEdit(0);

        await chat.submitEdit();

        expect(chat.editingIndex).toBe(0);
        expect(chat.errorMessage).toBe('Conversation is already processing');
    });
});

describe('a new message carries the backend context', () => {
    test('handleSend passes the context to the API', async () => {
        const chat = controller();
        chat.inputValue = 'summarise this page';

        await chat.handleSend();

        const args = chat._api.sendMessage.mock.calls[0];
        expect(args[0]).toBe(7);
        expect(args[1]).toBe('summarise this page');
        expect(args[3]).toEqual(expect.objectContaining({pageId: expect.any(Number), module: expect.any(String)}));
        chat.stopPolling();
    });
});

describe('conversation instructions', () => {
    test('saving stores the trimmed text and closes the editor', async () => {
        const chat = controller();
        chat.openSystemPrompt();
        chat.systemPromptDraft = '  Answer briefly. ';

        await chat.saveSystemPrompt();

        expect(chat._api.updateSystemPrompt).toHaveBeenCalledWith(7, 'Answer briefly.');
        expect(chat.systemPrompt).toBe('Answer briefly.');
        expect(chat.systemPromptOpen).toBe(false);
    });

    test('a refused save keeps the editor open', async () => {
        const chat = controller();
        chat._api.updateSystemPrompt.mockRejectedValue(new Error('busy'));
        chat.openSystemPrompt();

        await chat.saveSystemPrompt();

        expect(chat.systemPromptOpen).toBe(true);
        expect(chat.errorMessage).toBe('busy');
    });
});

describe.each([
    {name: 'ai-chat-panel', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', state: 'expanded'},
    {name: 'chat-app', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', state: null},
])('$name editing controls', ({module: modulePath, tag, state}) => {
    async function mount(chatState = {}) {
        await import(modulePath);
        document.body.replaceChildren();
        const el = document.createElement(tag);
        document.body.append(el);
        await new Promise(r => setTimeout(r, 0));
        Object.assign(el.chat, {
            available: true,
            loading: false,
            issues: [],
            status: 'idle',
            activeUid: 7,
            conversations: [{uid: 7, title: 'x', status: 'idle', tstamp: 1}],
            messages: [
                {role: 'user', content: 'first'},
                {role: 'assistant', content: 'answer'},
            ],
            ...chatState,
        });
        if (state) el.state = state;
        el.requestUpdate();
        await el.updateComplete;
        return el;
    }

    beforeEach(() => localStorage.clear());

    test('offers editing on the user message only', async () => {
        const el = await mount();
        const buttons = el.shadowRoot.querySelectorAll('[data-action="edit-message"]');
        expect(buttons).toHaveLength(1);
        expect(buttons[0].closest('.message-row').classList.contains('user')).toBe(true);
    });

    test('no editing while a turn is running', async () => {
        const el = await mount({status: 'processing'});
        expect(el.shadowRoot.querySelectorAll('[data-action="edit-message"]')).toHaveLength(0);
    });

    test('the editor replaces the bubble and Escape leaves it', async () => {
        const el = await mount();
        el.shadowRoot.querySelector('[data-action="edit-message"]').click();
        await el.updateComplete;

        const textarea = el.shadowRoot.querySelector('.message-editor textarea');
        expect(textarea.value).toBe('first');
        expect(el.shadowRoot.querySelector('.message-editor').textContent).toContain('chat.editHint');

        textarea.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, composed: true}));
        await el.updateComplete;
        expect(el.shadowRoot.querySelector('.message-editor')).toBeNull();
    });

    test('the instructions button opens the editor and marks a conversation that has instructions', async () => {
        const el = await mount({systemPrompt: 'Answer briefly.'});
        const button = el.shadowRoot.querySelector('[data-action="instructions"]');
        expect(button.classList.contains('has-instructions')).toBe(true);

        button.click();
        await el.updateComplete;
        expect(el.shadowRoot.querySelector('.instructions-editor textarea').value).toBe('Answer briefly.');
    });
});
