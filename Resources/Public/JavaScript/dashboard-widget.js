// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Clicks in the "Recent chats" dashboard widget.
 *
 * The dashboard runs inside the module frame; the chat panel lives in the
 * backend frame around it (ADR-011), floating or as a column, as the user
 * left it (ADR-024), or in its own window when the user popped it out; it
 * is found there through chat-panel-registry.js. A conversation or "New chat" clicked in the widget opens
 * there, next to the dashboard. A guided process bound to a page the user may
 * see (`data-nr-chat-page`) also opens that page in the page module, through
 * core's module menu, so the chat and the page sit side by side. Where the
 * panel is not available — a user without the toolbar item, or a frame that
 * is not the backend's — the link is followed and the chat module opens
 * instead. Opening the panel and showing the page are shared with the
 * assistant widgets' start links (chat-start.js).
 */

import {findPanel} from '@netresearch/nr-mcp-agent/chat-panel-registry.js';
import {dataInt, isPlainClick, openPanel, showPage} from '@netresearch/nr-mcp-agent/chat-start.js';
import {describeTime} from '@netresearch/nr-mcp-agent/relative-time.js';

document.addEventListener('click', (event) => {
    const link = event.composedPath().find(
        (el) => el instanceof HTMLElement && ('nrChatConversation' in el.dataset || 'nrChatNew' in el.dataset),
    );
    if (!link) {
        return;
    }
    // Ctrl, Cmd, Shift and Alt clicks and the middle button open the module
    // link the way the browser does; only a plain click opens the panel.
    if (!isPlainClick(event)) {
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

/**
 * The times of the list, worded in the reader's time zone (ADR-027). The
 * server renders them in its own, which is what the list shows without
 * JavaScript; here each `<time>` is worded again from its `datetime`, with
 * the labels and the locale the list carries. The dashboard renders a
 * widget's content after this module has loaded, and again when the widget
 * is refreshed, so new lists are picked up as they appear.
 *
 * @param {ParentNode} root
 * @param {Date} [now]
 */
export function relabelTimes(root, now = new Date()) {
    for (const list of root.querySelectorAll('.nr-recent-chats[data-nr-chat-locale]')) {
        const {nrChatLocale: locale, nrChatToday: today = '%s', nrChatYesterday: yesterday = '%s'} = list.dataset;
        for (const element of list.querySelectorAll('time[datetime]')) {
            const time = describeTime(element.getAttribute('datetime'), {
                locale,
                now,
                today: (text) => today.replace('%s', text),
                yesterday: (text) => yesterday.replace('%s', text),
            });
            if (time) {
                element.textContent = time.label;
                element.title = time.absolute;
            }
        }
    }
}

relabelTimes(document);
new MutationObserver((records) => {
    if (records.some((record) => [...record.addedNodes].some((node) => node instanceof Element && (node.matches('.nr-recent-chats') || node.querySelector('.nr-recent-chats'))))) {
        relabelTimes(document);
    }
}).observe(document.documentElement, {childList: true, subtree: true});
