// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The time of a conversation's last message, worded in the reader's browser
 * (ADR-027): their calendar day, and anew at every render, so a list left
 * open past midnight says "Gestern" instead of the server's "Heute".
 * Times are built in the test's own time zone, so the assertions hold in any.
 */

import {describe, test, expect} from '@jest/globals';
import {describeTime} from '../../Resources/Public/JavaScript/relative-time.js';

const at = (year, month, day, hour, minute) => new Date(year, month - 1, day, hour, minute).toISOString();
const words = {today: (t) => `Heute, ${t}`, yesterday: (t) => `Gestern, ${t}`};

describe('describeTime', () => {
    const now = new Date(2026, 9, 24, 12, 0);

    test.each([
        ['today', at(2026, 10, 24, 8, 5), 'today', 'Heute, 08:05'],
        ['just after midnight is today', at(2026, 10, 24, 0, 1), 'today', 'Heute, 00:01'],
        ['yesterday', at(2026, 10, 23, 16, 5), 'yesterday', 'Gestern, 16:05'],
        ['late yesterday', at(2026, 10, 23, 23, 59), 'yesterday', 'Gestern, 23:59'],
        ['the day before', at(2026, 10, 22, 23, 59), 'date', '22. Okt.'],
        ['just under a year', at(2025, 10, 25, 9, 0), 'date', '25. Okt.'],
        ['over a year', at(2025, 10, 23, 9, 0), 'date', '23. Okt. 2025'],
    ])('%s', (_, iso, kind, label) => {
        const time = describeTime(iso, {locale: 'de', now, ...words});

        expect(time.kind).toBe(kind);
        expect(time.label).toBe(label);
        expect(time.datetime).toBe(iso);
    });

    test('English', () => {
        expect(describeTime(at(2026, 10, 3, 9, 0), {locale: 'en', now}).label).toBe('Oct 3');
        expect(describeTime(at(2026, 10, 24, 8, 5), {locale: 'en', now, today: (t) => `Today, ${t}`}).label).toMatch(/^Today, 8:05\s?AM$/u);
    });

    test('the absolute time is the full date with the time of day', () => {
        expect(describeTime(at(2026, 10, 24, 10, 42), {locale: 'de', now}).absolute).toMatch(/^24\. Oktober 2026.*10:42$/);
    });

    /** The point of wording it on the client: the same time is "Heute" before midnight and "Gestern" after. */
    test('a list left open past midnight says yesterday at its next render', () => {
        const iso = at(2026, 10, 24, 22, 0);

        expect(describeTime(iso, {locale: 'de', now: new Date(2026, 9, 24, 23, 59), ...words}).label).toBe('Heute, 22:00');
        expect(describeTime(iso, {locale: 'de', now: new Date(2026, 9, 25, 0, 1), ...words}).label).toBe('Gestern, 22:00');
    });

    /** The server's clock a little ahead of the reader's: still today, never a date. */
    test('a time slightly in the future is now', () => {
        const ahead = describeTime(at(2026, 10, 24, 12, 2), {locale: 'de', now, ...words});

        expect(ahead.kind).toBe('today');
        expect(ahead.label).toBe('Heute, 12:00');
        expect(ahead.datetime).toBe(at(2026, 10, 24, 12, 2));

        const pastMidnight = describeTime(at(2026, 10, 25, 0, 1), {locale: 'de', now: new Date(2026, 9, 24, 23, 59), ...words});
        expect(pastMidnight.label).toBe('Heute, 23:59');
    });

    /**
     * Further ahead than any clock difference (a time written by a host with a
     * wrong clock, or a test fixture dated ahead): the date it names, as the
     * server words it, not "today".
     */
    test('a time far in the future is its date', () => {
        const later = describeTime(at(2026, 10, 24, 13, 1), {locale: 'de', now, ...words});
        expect(later.kind).toBe('today');
        expect(later.label).toBe('Heute, 13:01');

        const far = describeTime('2099-01-01T10:00:00+00:00', {locale: 'en-US', now});
        expect(far.kind).toBe('date');
        expect(far.label).toBe('Jan 1');
    });

    test('nothing for a missing or broken time, and the browser locale for an unknown one', () => {
        expect(describeTime('', {now})).toBeNull();
        expect(describeTime(undefined, {now})).toBeNull();
        expect(describeTime('not a date', {now})).toBeNull();
        expect(describeTime(at(2026, 10, 24, 8, 5), {locale: 'xx-!!', now}).kind).toBe('today');
    });
});
