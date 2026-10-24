// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Stub for @typo3/backend/storage/module-state-storage.js, behaving like core
 * 13.4/14.3: the state lives in sessionStorage under `t3-module-state-<module>`,
 * and every change is dispatched on the (top) document as
 * `typo3:module-state-storage:<update|update-with-tree-identifier>:<module>`.
 */
const PREFIX = 't3-module-state-';

export class ModuleStateStorage {
    static update(module, identifier) {
        const current = ModuleStateStorage.current(module);
        const id = String(identifier ?? 0);
        const state = {identifier: id, treeIdentifier: id === current.identifier ? current.treeIdentifier : null};
        ModuleStateStorage.commit(module, 'update', state);
        return state;
    }

    static updateWithTreeIdentifier(module, identifier, treeIdentifier) {
        const state = {identifier: String(identifier), treeIdentifier: String(treeIdentifier)};
        ModuleStateStorage.commit(module, 'update-with-tree-identifier', state);
        return state;
    }

    static current(module) {
        const raw = sessionStorage.getItem(PREFIX + module);
        return {identifier: '', treeIdentifier: null, ...(raw === null ? {} : JSON.parse(raw))};
    }

    static purge() {
        Object.keys(sessionStorage).filter((key) => key.startsWith(PREFIX)).forEach((key) => sessionStorage.removeItem(key));
    }

    static commit(module, type, state) {
        const oldState = ModuleStateStorage.current(module);
        sessionStorage.setItem(PREFIX + module, JSON.stringify(state));
        document.dispatchEvent(new CustomEvent(`typo3:module-state-storage:${type}:${module}`, {detail: {state, oldState}}));
    }
}
