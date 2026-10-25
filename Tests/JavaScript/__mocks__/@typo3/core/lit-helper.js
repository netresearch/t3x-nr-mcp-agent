// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Stub for @typo3/core/lit-helper.js.
 *
 * Without a label in `TYPO3.lang` it returns the key unchanged, which most
 * tests assert on. With one it does what core's lll() does (TYPO3 13.4 and
 * 14.3, lit-helper.js): every plain `%s`, `%d` and `%f` is filled in order,
 * a missing value as the text "undefined", and a positional placeholder such
 * as `%1$s` is left as it is. A test that sets real labels therefore sees
 * what the reader sees.
 */
export const lll = (key, ...values) => {
    const labels = globalThis.TYPO3?.lang;
    if (!labels || typeof labels[key] !== 'string') {
        return key;
    }

    let index = 0;
    return labels[key].replace(/%[sdf]/g, (match) => {
        const value = values[index++];
        switch (match) {
            case '%s':
                return String(value);
            case '%d':
                return String(parseInt(value, 10));
            case '%f':
                return String(parseFloat(value).toFixed(2));
            default:
                return match;
        }
    });
};
