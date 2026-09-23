/**
 * Which conversations the panel's tab row shows, and which go into the
 * "more" list.
 *
 * The tab row used to render every conversation and wrap into as many rows as
 * it took; with thirty conversations the rows filled the panel and the chat
 * itself was pushed out of sight (NEXT-172). The row now holds a fixed number
 * of tabs and never wraps.
 *
 * Order: pinned conversations first, then by last activity (`tstamp`, newest
 * first). `tstamp` is the row's modification time — every write touches it,
 * a rename or a pin included — which is the closest thing to "last activity"
 * the list endpoint carries.
 *
 * The active conversation is always in the row. When the order would push it
 * into the list, it takes the last visible place instead, so the tab the chat
 * area belongs to is never hidden behind a menu.
 */

/** Tabs shown in the row, the active one included. */
export const VISIBLE_TAB_COUNT = 4;

/**
 * @param {Array<{uid: number, pinned?: boolean, tstamp?: number}>} conversations
 * @returns {Array} a new array, pinned first, then newest first
 */
export function orderConversations(conversations) {
    return [...conversations].sort((a, b) => {
        const pinned = Number(Boolean(b.pinned)) - Number(Boolean(a.pinned));
        if (pinned !== 0) {
            return pinned;
        }
        return (b.tstamp || 0) - (a.tstamp || 0);
    });
}

/**
 * @param {Array<{uid: number, pinned?: boolean, tstamp?: number}>} conversations
 * @param {number|null} activeUid
 * @param {number} [limit]
 * @returns {{visible: Array, overflow: Array}}
 */
export function splitConversationTabs(conversations, activeUid, limit = VISIBLE_TAB_COUNT) {
    const ordered = orderConversations(conversations);
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
 * Case-insensitive title filter for the "more" list.
 *
 * @param {Array<{title?: string}>} conversations
 * @param {string} query
 * @param {string} untitled label used for a conversation without a title
 * @returns {Array}
 */
export function filterConversations(conversations, query, untitled = '') {
    const needle = query.trim().toLocaleLowerCase();
    if (needle === '') {
        return conversations;
    }
    return conversations.filter(c => (c.title || untitled).toLocaleLowerCase().includes(needle));
}
