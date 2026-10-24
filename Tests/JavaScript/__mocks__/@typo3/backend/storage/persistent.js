// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Stub for @typo3/backend/storage/persistent.js: the backend user's uc, kept
 * in memory. Dotted keys are nested like core does. `writes` records every
 * set() so tests can count the requests core would send.
 */
const Persistent = {
    data: {},
    writes: [],
    get(key) {
        return key.split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), this.data);
    },
    set(key, value) {
        const parts = key.split('.');
        const last = parts.pop();
        let node = this.data;
        for (const part of parts) {
            node[part] = node[part] && typeof node[part] === 'object' ? node[part] : {};
            node = node[part];
        }
        node[last] = value;
        this.writes.push([key, value]);
        return Promise.resolve(this.data);
    },
    reset() {
        this.data = {};
        this.writes = [];
    },
};

export default Persistent;
