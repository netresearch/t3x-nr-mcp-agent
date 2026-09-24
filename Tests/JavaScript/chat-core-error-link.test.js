/**
 * The error link an administrator gets beside a configuration failure
 * (ADR-017) travels through the two readers of the message endpoint: the full
 * load and the poll. Both must carry it, and a poll that answers without one
 * must drop the link the load brought.
 */

import {describe, test, expect} from '@jest/globals';
import {ChatCoreController} from '../../Resources/Public/JavaScript/chat-core.js';

const PROVIDER_FAILURE = 'API key identifier is required for provider OpenAI';

function controllerAnswering(responses) {
    const host = {
        addController() {},
        requestUpdate() {},
        onScrollToBottom() {},
        isConnected: false,
    };
    const chat = new ChatCoreController(host);
    const queue = [...responses];
    chat._api = {getMessages: async () => queue.shift()};
    chat.activeUid = 1;

    return chat;
}

describe('chat-core error link', () => {
    test('the full load carries the link together with its message', async () => {
        const chat = controllerAnswering([{
            status: 'failed',
            messages: [{role: 'user', content: 'hello'}],
            totalCount: 1,
            errorMessage: PROVIDER_FAILURE,
            errorLink: '/typo3/module/nrllm/providers',
            errorLinkLabel: 'Open the LLM providers',
        }]);

        await chat.loadMessages();

        expect(chat.currentErrorLink()).toEqual({href: '/typo3/module/nrllm/providers', label: 'Open the LLM providers'});
    });

    test('a poll carries a new link and drops it when the answer has none', async () => {
        const chat = controllerAnswering([
            {status: 'failed', messages: [], totalCount: 1, errorMessage: PROVIDER_FAILURE, errorLink: '/typo3/module/nrllm/tasks', errorLinkLabel: 'Open the LLM tasks'},
            {status: 'idle', messages: [{role: 'assistant', content: 'Hallo'}], totalCount: 2, errorMessage: ''},
        ]);
        chat.status = 'processing';
        chat._knownMessageCount = 1;

        await chat.pollMessages();
        expect(chat.currentErrorLink()).toEqual({href: '/typo3/module/nrllm/tasks', label: 'Open the LLM tasks'});

        await chat.pollMessages();
        expect(chat.currentErrorLink()).toBeNull();
    });
});
