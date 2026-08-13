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
    // Constructed stylesheets belong to the document that made them, so the
    // stub needs its own CSSStyleSheet — that binding is the whole defect the
    // adoption tests below are about.
    const adopted = [];
    class PipStyleSheet {
        constructor() {
            this.cssText = '';
            adopted.push(this);
        }

        replaceSync(text) {
            this.cssText = text;
        }
    }

    const pipWindow = {
        document: document.implementation.createHTMLDocument('pip'),
        addEventListener: jest.fn(),
        CSSStyleSheet: PipStyleSheet,
        constructedSheets: adopted,
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

    test('fills its own window instead of keeping main-window coordinates', async () => {
        installPictureInPictureStub();
        const {panel} = await mountPanel();

        // The panel positions itself with position:fixed and left/top computed
        // against the MAIN window. Carried into a 480px-wide detached window,
        // a left of ~1200px puts it outside — the window opens empty and the
        // feature looks broken while every DOM assertion still passes.
        panel._posX = 1200;
        panel._posY = 700;
        await panel.updateComplete;

        await panel.popOut();
        await panel.updateComplete;

        expect(panel.style.left).toBe('0px');
        expect(panel.style.top).toBe('0px');
        expect(panel.style.width).toBe('100%');
        expect(panel.style.height).toBe('100%');
    });

    test('takes its old position back when it returns', async () => {
        const {pipWindow} = installPictureInPictureStub();
        const {panel} = await mountPanel();

        panel._posX = 300;
        panel._posY = 200;
        await panel.updateComplete;

        await panel.popOut();
        const [, handler] = pipWindow.addEventListener.mock.calls.find(([name]) => name === 'pagehide');
        handler();
        await panel.updateComplete;

        expect(panel.style.left).toBe('300px');
        expect(panel.style.top).toBe('200px');
    });

    /**
     * The defect this guards: the panel arrived in its own window rendering as
     * bare serif HTML — no layout, no colours, plain buttons. Lit applies
     * `static styles` through `adoptedStyleSheets`, and a constructed sheet
     * belongs to the document that made it, so the move left the shadow root
     * holding sheets the new document ignores. The docblock claimed the styles
     * travel with the element; they do not.
     */
    test('rebuilds its styles for the window it moves into', async () => {
        const {pipWindow} = installPictureInPictureStub();
        const {panel} = await mountPanel();

        await panel.popOut();

        const sheets = panel.shadowRoot.adoptedStyleSheets;
        expect(sheets.length).toBeGreaterThan(0);
        // Built by the target document, not carried over from this one.
        expect(sheets.every((sheet) => sheet instanceof pipWindow.CSSStyleSheet)).toBe(true);
        expect(sheets.some((sheet) => sheet.cssText.includes(':host'))).toBe(true);
    });

    test('rebuilds them again when it comes home', async () => {
        const {pipWindow} = installPictureInPictureStub();
        // jsdom has no constructible CSSStyleSheet, so the main window needs the
        // same stand-in the detached one has — otherwise the return path cannot
        // rebuild anything and the test would be measuring jsdom, not the panel.
        class HomeStyleSheet {
            constructor() {
                this.cssText = '';
            }

            replaceSync(text) {
                this.cssText = text;
            }
        }
        const previous = window.CSSStyleSheet;
        window.CSSStyleSheet = HomeStyleSheet;

        try {
            const {panel} = await mountPanel();
            await panel.popOut();

            const [, handler] = pipWindow.addEventListener.mock.calls.find(([type]) => type === 'pagehide');
            handler();

            const sheets = panel.shadowRoot.adoptedStyleSheets;
            expect(sheets.length).toBeGreaterThan(0);
            expect(sheets.every((sheet) => sheet instanceof HomeStyleSheet)).toBe(true);
            expect(sheets.some((sheet) => sheet instanceof pipWindow.CSSStyleSheet)).toBe(false);
        } finally {
            window.CSSStyleSheet = previous;
        }
    });

    /**
     * A picture-in-picture document starts empty: no reset, no background. The
     * panel is position: fixed and sized to fill, so without this the default
     * body margin shows as a white frame around it.
     */
    test('gives the detached window a reset and a background', async () => {
        const {pipWindow} = installPictureInPictureStub();
        document.documentElement.style.setProperty('--typo3-component-bg', 'rgb(1, 2, 3)');
        const {panel} = await mountPanel();

        await panel.popOut();

        const style = pipWindow.document.head.querySelector('style');
        expect(style).not.toBeNull();
        expect(style.textContent).toContain('margin:0');
        // Resolved, not a var() reference the new document cannot look up.
        expect(pipWindow.document.documentElement.style.background).toBe('rgb(1, 2, 3)');
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
