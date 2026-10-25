// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Labels with values (text-format.js). TYPO3's lll() fills `%s` and `%d` in
 * order only and leaves a positional `%1$s` in the text, which then reached
 * the reader raw ("im Element %1$s auf der Seite %2$s"). The guard below
 * reads the extension's scripts: a label filled through lll() with values
 * must not use positional placeholders, in any language; one that needs them
 * goes through format().
 */

import {describe, test, expect} from '@jest/globals';
import {readFileSync, readdirSync} from 'node:fs';
import {join} from 'node:path';
import {format} from '../../Resources/Public/JavaScript/text-format.js';

const ROOT = join(import.meta.dirname, '../..');

describe('format()', () => {
    test('fills positional placeholders, also out of order', () => {
        expect(format('im Element %1$s auf der Seite %2$s', 'Text', 'Start')).toBe('im Element Text auf der Seite Start');
        expect(format('%2$s – %1$s', 'a', 'b')).toBe('b – a');
    });

    test('fills %s and %d in order, and a number as a number', () => {
        expect(format('Tool: %s', 'search')).toBe('Tool: search');
        expect(format('%d of %d', '3', 7)).toBe('3 of 7');
        expect(format('Seite %1$d von %2$d', '2.9', 'x')).toBe('Seite 2 von ');
    });

    test('a missing value is an empty text, not "undefined"', () => {
        expect(format('%1$s · %2$s', 'only')).toBe('only · ');
    });
});

describe('labels filled through lll()', () => {
    /** Every trans-unit of every language file: id → [source, target, …]. */
    function labels() {
        const dir = join(ROOT, 'Resources/Private/Language');
        const texts = new Map();
        for (const file of readdirSync(dir).filter((name) => name.endsWith('.xlf'))) {
            const xml = readFileSync(join(dir, file), 'utf8');
            for (const unit of xml.matchAll(/<trans-unit id="([^"]+)"[^>]*>([\s\S]*?)<\/trans-unit>/g)) {
                const list = texts.get(unit[1]) ?? [];
                list.push(...[...unit[2].matchAll(/<(?:source|target)>([\s\S]*?)<\/(?:source|target)>/g)].map((m) => m[1]));
                texts.set(unit[1], list);
            }
        }
        return texts;
    }

    test('no label lll() fills with values has a positional placeholder', () => {
        const texts = labels();
        const dir = join(ROOT, 'Resources/Public/JavaScript');
        const offending = [];
        let calls = 0;
        for (const file of readdirSync(dir).filter((name) => name.endsWith('.js'))) {
            const source = readFileSync(join(dir, file), 'utf8');
            for (const call of source.matchAll(/lll\('([^']+)',/g)) {
                calls++;
                if ((texts.get(call[1]) ?? []).some((text) => /%\d+\$/.test(text))) {
                    offending.push(`${file}: ${call[1]}`);
                }
            }
        }

        expect(calls).toBeGreaterThan(0);
        expect(offending).toEqual([]);
    });
});
