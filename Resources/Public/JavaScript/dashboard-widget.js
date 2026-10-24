// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Clicks in the "Recent chats" dashboard widget.
 *
 * The dashboard runs inside the module frame; the chat panel lives in the
 * backend frame around it (ADR-011), floating or as a column, as the user
 * left it (ADR-024). A conversation or "New chat" clicked in the widget opens
 * there, next to the dashboard. A conversation about a page the user may see
 * (`data-nr-chat-page`) also opens that page in the page module, through
 * core's module menu, so the chat and the page sit side by side. Where the
 * panel is not available — a user without the toolbar item, or a frame that
 * is not the backend's — the link is followed and the chat module opens
 * instead.
 */

function backendTop() {
    try {
        return globalThis.top ?? globalThis;
    } catch {
        return null;
    }
}

function findPanel() {
    try {
        return backendTop()?.document.querySelector('ai-chat-panel') ?? null;
    } catch {
        return null;
    }
}

function openPanel(panel) {
    if (panel.state === 'hidden' || panel.state === 'collapsed') {
        panel.toggle();
    }
}

/**
 * Show the page in the page module, in the language the conversation was
 * about when it is known. Nothing happens where core's module menu is not
 * reachable: the chat opens all the same.
 */
function showPage(pageUid, languageUid) {
    let moduleMenu = null;
    try {
        moduleMenu = backendTop()?.TYPO3?.ModuleMenu?.App ?? null;
    } catch {
        return;
    }
    if (typeof moduleMenu?.showModule !== 'function') {
        return;
    }
    const params = new URLSearchParams({id: String(pageUid)});
    if (languageUid >= 0) {
        params.set('language', String(languageUid));
    }
    moduleMenu.showModule('web_layout', params.toString());
}

/** A non-negative integer from a data attribute, or -1 when it holds none. */
function dataInt(value) {
    return /^\d+$/.test(value ?? '') ? Number.parseInt(value, 10) : -1;
}

document.addEventListener('click', (event) => {
    const link = event.composedPath().find(
        (el) => el instanceof HTMLElement && ('nrChatConversation' in el.dataset || 'nrChatNew' in el.dataset),
    );
    if (!link) {
        return;
    }
    // Ctrl, Cmd, Shift and Alt clicks and the middle button open the module
    // link the way the browser does; only a plain click opens the panel.
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return;
    }

    const panel = findPanel();
    if (!panel?.chat) {
        return; // follow the link to the module
    }

    event.preventDefault();
    openPanel(panel);

    const uid = dataInt(link.dataset.nrChatConversation);
    if (uid <= 0) {
        panel.chat.handleNewConversation();
        return;
    }

    panel.chat.selectConversation(uid);
    const pageUid = dataInt(link.dataset.nrChatPage);
    if (pageUid > 0) {
        showPage(pageUid, dataInt(link.dataset.nrChatLanguage));
    }
});
