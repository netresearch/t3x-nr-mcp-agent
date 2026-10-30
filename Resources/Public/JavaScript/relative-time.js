// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * When a conversation's last message was written, as every list says it
 * (ADR-027): "Heute, 10:42" and "Gestern, 16:05" for today and yesterday,
 * the day and month for anything older, with the year once it is more than a
 * year back; the full date and time for the tooltip.
 *
 * Worded here, in the reader's browser, from the ISO time the server sends:
 * the calendar day is the reader's, and a list left open past midnight says
 * "Gestern" at its next render instead of keeping the server's "Heute".
 */

function sameDay(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

/** A locale Intl accepts, or undefined for the browser's own. */
function safeLocale(locale) {
    try {
        return locale && Intl.DateTimeFormat.supportedLocalesOf([locale]).length > 0 ? locale : undefined;
    } catch {
        return undefined;
    }
}

/**
 * @param {string} iso the time, ISO 8601
 * @param {{locale?: string, now?: Date, today?: (time: string) => string, yesterday?: (time: string) => string}} [options]
 *   `today` and `yesterday` word the time of day ("Heute, %s")
 * @returns {{kind: 'today'|'yesterday'|'date', label: string, absolute: string, datetime: string}|null}
 */
export function describeTime(iso, options = {}) {
    const at = new Date(iso ?? '');
    if (Number.isNaN(at.getTime())) {
        return null;
    }

    const locale = safeLocale(options.locale);
    const now = options.now ?? new Date();
    // The calendar day before, not 24 hours before: a day with a clock change is 23 or 25 hours long.
    const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
    const time = new Intl.DateTimeFormat(locale, {timeStyle: 'short'}).format(at);
    const absolute = new Intl.DateTimeFormat(locale, {dateStyle: 'long', timeStyle: 'short'}).format(at);

    if (sameDay(at, now)) {
        return {kind: 'today', label: options.today ? options.today(time) : time, absolute, datetime: iso};
    }
    if (sameDay(at, yesterday)) {
        return {kind: 'yesterday', label: options.yesterday ? options.yesterday(time) : time, absolute, datetime: iso};
    }

    const yearAgo = new Date(now);
    yearAgo.setFullYear(now.getFullYear() - 1);
    const date = new Intl.DateTimeFormat(locale, at < yearAgo
        ? {day: 'numeric', month: 'short', year: 'numeric'}
        : {day: 'numeric', month: 'short'}).format(at);

    return {kind: 'date', label: date, absolute, datetime: iso};
}
