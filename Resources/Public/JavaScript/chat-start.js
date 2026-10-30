// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * How a dashboard widget opens the chat beside the page module.
 *
 * The dashboard runs inside the module frame; the chat panel belongs to the
 * backend frame around it (ADR-011) and is found wherever the user put it,
 * its own window included (chat-panel-registry.js). Two ways in share this
 * module:
 *
 * - "Recent chats" (dashboard-widget.js) opens a conversation and shows its
 *   page in the page module (openPanel(), showPage()).
 * - The assistant widgets start a new conversation from a link that carries
 *   `data-nr-chat-start` (startFromLink()):
 *   - `data-nr-chat-page` (and `data-nr-chat-language`): the page module shows
 *     that page and the conversation is about it;
 *   - `data-nr-chat-skill`: the conversation starts with that skill; with a
 *     page, a guided tour starts on it, without one, it asks for a page;
 *   - `data-nr-chat-page-module="1"`: the page module opens even without a page,
 *     so its page tree is where the editor chooses one;
 *   - `data-nr-chat-prefill`: words put into the input, not sent. A link
 *     never sends a message on the user's behalf.
 *   An action that concerns a page puts the chat in the column beside the
 *   page module for this opening (ADR-024); the panel refuses that in its own
 *   window and in an area too narrow, and stays where the user has it.
 *
 * Ctrl, Cmd, Shift and Alt clicks and the middle button follow the link the
 * way the browser does, as does any click where the panel is not available:
 * the link's own target is the chat module, which reads the same start.
 */

import {findPanel} from '@netresearch/nr-mcp-agent/chat-panel-registry.js';

/** The panel's layout beside the page module (chat-column.js, LAYOUT.COLUMN). */
const COLUMN = 'column';

function backendTop() {
    try {
        return globalThis.top ?? globalThis;
    } catch {
        return null;
    }
}

/** Open a hidden or collapsed panel; an open one stays as it is. */
export function openPanel(panel) {
    if (panel.state === 'hidden' || panel.state === 'collapsed') {
        panel.toggle();
    }
}

/**
 * Show the page module, on the page in the language when they are known
 * (a page uid of 0 or less: the page the page tree has selected). Nothing
 * happens where core's module menu is not reachable: the chat opens all the
 * same.
 *
 * @param {number} pageUid
 * @param {number} languageUid -1 when unknown
 */
export function showPage(pageUid, languageUid = -1) {
    let moduleMenu = null;
    try {
        moduleMenu = backendTop()?.TYPO3?.ModuleMenu?.App ?? null;
    } catch {
        return;
    }
    if (typeof moduleMenu?.showModule !== 'function') {
        return;
    }
    if (pageUid <= 0) {
        moduleMenu.showModule('web_layout');
        return;
    }
    const params = new URLSearchParams({id: String(pageUid)});
    if (languageUid >= 0) {
        // The page module reads `language` in TYPO3 13 and `languages[]` in
        // 14; each ignores the other's.
        params.set('language', String(languageUid));
        params.set('languages[0]', String(languageUid));
    }
    moduleMenu.showModule('web_layout', params.toString());
}

/** A non-negative integer from a data attribute, or -1 when it holds none. */
export function dataInt(value) {
    return /^\d+$/.test(value ?? '') ? Number.parseInt(value, 10) : -1;
}

/** Whether a click is the plain one the panel takes over. */
export function isPlainClick(event) {
    return event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
}

/**
 * What a start link asks for, read from its data attributes.
 *
 * @param {HTMLElement} link
 * @returns {{start: {skill?: string, pageUid?: number, languageUid?: number}, pageModule: boolean, prefill: string}}
 */
export function startOf(link) {
    const start = {};
    const skill = (link.dataset.nrChatSkill ?? '').trim();
    const page = dataInt(link.dataset.nrChatPage);
    const language = dataInt(link.dataset.nrChatLanguage);
    if (skill !== '') start.skill = skill;
    if (page > 0) {
        start.pageUid = page;
        if (language >= 0) start.languageUid = language;
    }

    return {
        start,
        pageModule: page > 0 || link.dataset.nrChatPageModule === '1',
        prefill: (link.dataset.nrChatPrefill ?? '').trim(),
    };
}

// A second click while the first start runs would create a second
// conversation.
let starting = false;

/**
 * Start what the link asks for in the panel.
 *
 * Docking reconnects the panel, and a reconnect aborts the controller's
 * requests in flight; so the panel is placed first, and the conversation is
 * created from the connection it ends up with (as startConversation() in
 * ai-chat-panel.js does). The page module opens last: it replaces the
 * dashboard, and with it the document this script runs in, so nothing here
 * would run after it.
 *
 * @param {object} panel the chat panel
 * @param {HTMLElement} link
 * @returns {Promise<boolean>} whether a conversation was started
 */
export async function startFromLink(panel, link) {
    if (starting) {
        return false;
    }
    starting = true;
    try {
        const {start, pageModule, prefill} = startOf(link);
        if (pageModule && panel.layout !== COLUMN && typeof panel.setLayout === 'function') {
            panel.setLayout(COLUMN, {remember: false});
        }
        if (typeof panel.expand === 'function' && (panel.state === 'hidden' || panel.state === 'collapsed')) {
            panel.expand();
        } else {
            openPanel(panel);
        }
        await panel.chat.ready;
        await panel.updateComplete;

        const uid = await panel.chat.handleNewConversation(Object.keys(start).length > 0 ? start : null);
        if (pageModule) {
            showPage(start.pageUid ?? 0, start.languageUid ?? -1);
        }
        if (!uid) {
            return false;
        }
        if (prefill !== '') {
            panel.chat.inputValue = prefill;
            panel.chat.hasInput = true;
            panel.requestUpdate?.();
            await panel.updateComplete;
            panel.onFocusInput?.();
        }

        return true;
    } finally {
        starting = false;
    }
}

document.addEventListener('click', (event) => {
    const link = event.composedPath().find((el) => el instanceof HTMLElement && 'nrChatStart' in el.dataset);
    if (!link || !isPlainClick(event)) {
        return;
    }
    const panel = findPanel();
    if (!panel?.chat) {
        return; // follow the link to the module
    }
    event.preventDefault();
    startFromLink(panel, link).catch(() => {});
});
