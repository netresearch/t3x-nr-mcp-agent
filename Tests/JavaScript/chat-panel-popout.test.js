/**
 * Detaching the chat panel into its own window.
 *
 * A DOM element cannot leave the browser window — that is a browser boundary,
 * not something the code can loosen. Moving the panel "next to the browser"
 * therefore means moving it into a SEPARATE window, which the operating system
 * places wherever the user drags it, second monitor included.
 *
 * The Document Picture-in-Picture API gives a chrome-less always-on-top window
 * for exactly this. It is Chromium-only, so the button has to disappear rather
 * than fail where the API is absent — the case these tests care about most,
 * because a button that throws on click is worse than no button.
 */

import {describe, test, expect, beforeEach, afterEach, jest} from '@jest/globals';

const PANEL_TAG = 'ai-chat-panel';

/** Minimal stand-in for the API: jsdom ships no picture-in-picture. */
function installPictureInPictureStub() {
    const pipWindow = {
        document: document.implementation.createHTMLDocument('pip'),
        addEventListener: jest.fn(),
    };
    const requestWindow = jest.fn().mockResolvedValue(pipWindow);

    Object.defineProperty(window, 'documentPictureInPicture', {
        value: {requestWindow},
        configurable: true,
        writable: true,
    });

    return {pipWindow, requestWindow};
}

function removePictureInPictureStub() {
    delete window.documentPictureInPicture;
}

async function mountPanel() {
    await import('../../Resources/Public/JavaScript/ai-chat-panel.js');

    const host = document.createElement('div');
    document.body.append(host);

    const panel = document.createElement(PANEL_TAG);
    host.append(panel);
    panel.state = 'expanded';
    Object.assign(panel.chat, {loading: false, issues: [], conversations: [], available: true, activeUid: null});
    await panel.updateComplete;

    return {panel, host};
}

describe('panel pop-out', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    afterEach(() => {
        removePictureInPictureStub();
    });

    test('offers no pop-out where the browser cannot do it', async () => {
        removePictureInPictureStub();
        const {panel} = await mountPanel();

        // Firefox and Safari have no Document Picture-in-Picture. A button that
        // throws on click is worse than a button that is not there.
        expect(panel.shadowRoot.querySelector('[data-action="popout"]')).toBeNull();
    });

    test('offers a pop-out where the browser can do it', async () => {
        installPictureInPictureStub();
        const {panel} = await mountPanel();

        expect(panel.shadowRoot.querySelector('[data-action="popout"]')).not.toBeNull();
    });

    test('opens a window sized like the panel and moves itself into it', async () => {
        const {pipWindow, requestWindow} = installPictureInPictureStub();
        const {panel} = await mountPanel();

        await panel.popOut();

        expect(requestWindow).toHaveBeenCalledWith(
            expect.objectContaining({width: panel._width, height: panel._height}),
        );
        // The element itself moves; re-rendering a copy would strand the
        // conversation state that lives on the controller.
        expect(pipWindow.document.body.contains(panel)).toBe(true);
    });

    test('comes back to where it was when the window closes', async () => {
        const {pipWindow} = installPictureInPictureStub();
        const {panel, host} = await mountPanel();

        await panel.popOut();
        expect(host.contains(panel)).toBe(false);

        // The component registers the return path itself; closing the window
        // must not leave the panel stranded in a document nobody can see.
        const [event, handler] = pipWindow.addEventListener.mock.calls
            .find(([name]) => name === 'pagehide');
        expect(event).toBe('pagehide');

        handler();
        expect(host.contains(panel)).toBe(true);
    });

    test('a failed request leaves the panel where it was', async () => {
        const {requestWindow} = installPictureInPictureStub();
        requestWindow.mockRejectedValue(new Error('denied'));
        const {panel, host} = await mountPanel();

        await expect(panel.popOut()).resolves.toBe(false);

        // Chromium refuses without a user gesture, and refuses a second window
        // while one is open. Neither may lose the panel.
        expect(host.contains(panel)).toBe(true);
    });
});
