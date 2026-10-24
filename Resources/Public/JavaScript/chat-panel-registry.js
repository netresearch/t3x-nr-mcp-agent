// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Where the dashboard widgets find the chat panel.
 *
 * The widgets run in the module frame, the panel belongs to the backend frame
 * around it (ADR-011). Looking it up in that frame's document fails once the
 * user popped it out: the element then lives in the picture-in-picture
 * window's document. So the panel registers itself on the backend window,
 * the window its module runs in wherever the element is, and the widgets ask
 * there first; the document lookup stays for a panel that has not registered.
 */

/** The property on the backend window that holds the panel. */
export const PANEL_PROPERTY = 'nrMcpAgentChatPanel';

/**
 * @param {Window} win the backend window
 * @param {HTMLElement} panel
 */
export function registerPanel(win, panel) {
    win[PANEL_PROPERTY] = panel;
}

/**
 * Forget the panel, if it is still the registered one.
 *
 * @param {Window} win the backend window
 * @param {HTMLElement} panel
 */
export function unregisterPanel(win, panel) {
    if (win[PANEL_PROPERTY] === panel) {
        delete win[PANEL_PROPERTY];
    }
}

/**
 * The chat panel of the backend, wherever the user put it, or null.
 *
 * @param {Window} [win] the backend window: the top frame
 * @returns {HTMLElement|null}
 */
export function findPanel(win = globalThis.top ?? globalThis) {
    try {
        const registered = win[PANEL_PROPERTY];
        if (registered?.isConnected) {
            return registered;
        }

        return win.document.querySelector('ai-chat-panel');
    } catch {
        return null;
    }
}
