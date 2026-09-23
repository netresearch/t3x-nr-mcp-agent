/**
 * Clicks in the "AI Chat" dashboard widget (NEXT-172): open the conversation
 * in the floating panel when there is one, follow the link otherwise.
 */

import {describe, test, expect, jest, beforeAll, beforeEach} from '@jest/globals';

function link(attrs) {
    const a = document.createElement('a');
    a.href = '/typo3/module/nr/mcp/agent/chat';
    for (const [k, v] of Object.entries(attrs)) a.setAttribute(k, v);
    a.textContent = 'x';
    document.body.append(a);
    return a;
}

function click(el) {
    const event = new MouseEvent('click', {bubbles: true, cancelable: true, composed: true});
    el.dispatchEvent(event);
    return event;
}

describe('dashboard widget', () => {
    beforeAll(async () => {
        await import('../../Resources/Public/JavaScript/dashboard-widget.js');
    });

    beforeEach(() => document.body.replaceChildren());

    function fakePanel(state = 'hidden') {
        const panel = document.createElement('ai-chat-panel-fake');
        panel.state = state;
        panel.toggle = jest.fn(() => { panel.state = 'expanded'; });
        panel.chat = {selectConversation: jest.fn(), handleNewConversation: jest.fn()};
        // The script looks the panel up by its tag name.
        jest.spyOn(document, 'querySelector').mockImplementation((sel) => (sel === 'ai-chat-panel' ? panel : null));
        return panel;
    }

    test('a conversation opens in the panel', () => {
        const panel = fakePanel();
        const event = click(link({'data-nr-chat-conversation': '12'}));

        expect(event.defaultPrevented).toBe(true);
        expect(panel.toggle).toHaveBeenCalled();
        expect(panel.chat.selectConversation).toHaveBeenCalledWith(12);
        document.querySelector.mockRestore();
    });

    test('"New chat" starts one in the panel without toggling an open panel shut', () => {
        const panel = fakePanel('expanded');
        click(link({'data-nr-chat-new': '1'}));

        expect(panel.toggle).not.toHaveBeenCalled();
        expect(panel.chat.handleNewConversation).toHaveBeenCalled();
        document.querySelector.mockRestore();
    });

    test('without a panel the link to the module is followed', () => {
        const event = click(link({'data-nr-chat-conversation': '12'}));

        expect(event.defaultPrevented).toBe(false);
    });

    test('other links are left alone', () => {
        const panel = fakePanel();
        const event = click(link({}));

        expect(event.defaultPrevented).toBe(false);
        expect(panel.chat.selectConversation).not.toHaveBeenCalled();
        document.querySelector.mockRestore();
    });
});
