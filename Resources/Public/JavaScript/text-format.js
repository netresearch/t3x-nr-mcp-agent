// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Fill the placeholders of a label: `%s` and `%d` in order, and the
 * positional `%1$s` / `%2$d` that translations need where they reorder the
 * values. TYPO3's lll() (lit-helper.js) fills only the first kind and leaves
 * a positional placeholder in the text, so a label with one is read raw
 * through lll() and filled here.
 *
 * @param {string} pattern
 * @param {...*} values
 * @returns {string}
 */
export function format(pattern, ...values) {
    let index = 0;
    return String(pattern ?? '').replace(/%(?:(\d+)\$)?([sd])/g, (match, position, type) => {
        const value = position ? values[Number.parseInt(position, 10) - 1] : values[index++];
        if (type === 'd') {
            const number = Number.parseInt(String(value ?? ''), 10);
            return Number.isNaN(number) ? '' : String(number);
        }
        return String(value ?? '');
    });
}
