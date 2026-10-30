// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Which conversations the panel's tab row shows, and which go into the
 * "more" list.
 *
 * The tab row used to render every conversation and wrap into as many rows as
 * it took; with thirty conversations the rows filled the panel and the chat
 * itself was pushed out of sight (NEXT-172). The row now holds a fixed number
 * of tabs and never wraps.
 *
 * Order: pinned conversations first, then by last activity, newest first:
 * `lastActivityAt`, when the last message was written (ADR-027). A list
 * without it — a server without the list presenter — falls back to `tstamp`,
 * the row's modification time, which a rename or a pin moves too.
 *
 * A conversation without a message is not listed (ADR-027): an empty "New
 * chat" says nothing about what it is. The open one is listed all the same,
 * so the chat area always has its tab.
 *
 * The active conversation is always in the row. When the order would push it
 * into the list, it takes the last visible place instead, so the tab the chat
 * area belongs to is never hidden behind a menu.
 */

/** Tabs shown in the row, the active one included. */
export const VISIBLE_TAB_COUNT = 4;

/**
 * When the conversation last moved, in milliseconds since the epoch.
 *
 * @param {{lastActivityAt?: string, tstamp?: number}} conversation
 * @returns {number}
 */
export function lastActivity(conversation) {
    const at = Date.parse(conversation.lastActivityAt ?? '');
    return Number.isNaN(at) ? (conversation.tstamp || 0) * 1000 : at;
}

/**
 * @param {Array<{uid: number, pinned?: boolean, lastActivityAt?: string, tstamp?: number}>} conversations
 * @returns {Array} a new array, pinned first, then newest first
 */
export function orderConversations(conversations) {
    return [...conversations].sort((a, b) => {
        const pinned = Number(Boolean(b.pinned)) - Number(Boolean(a.pinned));
        if (pinned !== 0) {
            return pinned;
        }
        return lastActivity(b) - lastActivity(a);
    });
}

/**
 * The conversations a list shows, in its order: those with a message, and
 * the open one whatever it holds.
 *
 * @param {Array<{uid: number, messageCount?: number}>} conversations
 * @param {number|null} activeUid
 * @returns {Array}
 */
export function listedConversations(conversations, activeUid) {
    return orderConversations(conversations.filter((c) => (c.messageCount ?? 1) > 0 || c.uid === activeUid));
}

/**
 * The title a list shows: the server's display title (ADR-027), the stored
 * title, or the label for a conversation without one.
 *
 * @param {{displayTitle?: string, title?: string}} conversation
 * @param {string} untitled
 * @returns {string}
 */
export function conversationTitle(conversation, untitled = '') {
    return conversation.displayTitle || conversation.title || untitled;
}

/**
 * The tooltip of a conversation where the list has room for its title only
 * (the panel's tabs): the full title, the context, the status and the time,
 * one per line.
 *
 * @param {{fullTitle?: string, displayTitle?: string, title?: string, contextLabel?: string}} conversation
 * @param {string} status the status label
 * @param {string} untitled
 * @param {string} [time] when the last message was written, worded
 * @returns {string}
 */
export function conversationTooltip(conversation, status, untitled = '', time = '') {
    return [
        conversation.fullTitle || conversationTitle(conversation, untitled),
        conversation.contextLabel || '',
        status,
        time,
    ].filter((line) => line !== '').join('\n');
}

/**
 * @param {Array<{uid: number, pinned?: boolean, lastActivityAt?: string, tstamp?: number, messageCount?: number}>} conversations
 * @param {number|null} activeUid
 * @param {number} [limit]
 * @returns {{visible: Array, overflow: Array}}
 */
export function splitConversationTabs(conversations, activeUid, limit = VISIBLE_TAB_COUNT) {
    const ordered = listedConversations(conversations, activeUid);
    const visible = ordered.slice(0, limit);
    const overflow = ordered.slice(limit);

    const activeIndex = overflow.findIndex(c => c.uid === activeUid);
    if (activeIndex !== -1 && visible.length > 0) {
        const [active] = overflow.splice(activeIndex, 1);
        const displaced = visible.pop();
        visible.push(active);
        // The displaced tab keeps its place in the order: it is the most
        // recent of the conversations in the list.
        overflow.unshift(displaced);
    }

    return {visible, overflow};
}

/**
 * Case-insensitive filter for the "more" list, over what the list shows of a
 * conversation: its title, the full title behind it and the context — the
 * page, the skill (ADR-027).
 *
 * @param {Array<{title?: string, displayTitle?: string, fullTitle?: string, contextLabel?: string}>} conversations
 * @param {string} query
 * @param {string} untitled label used for a conversation without a title
 * @returns {Array}
 */
export function filterConversations(conversations, query, untitled = '') {
    const needle = query.trim().toLocaleLowerCase();
    if (needle === '') {
        return conversations;
    }
    return conversations.filter((c) => [conversationTitle(c, untitled), c.fullTitle, c.contextLabel]
        .some((text) => (text || '').toLocaleLowerCase().includes(needle)));
}
