// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The page the chat is about follows the page tree (ADR-024).
 *
 * A click in the tree updates ModuleStateStorage at once, while the module
 * frame's URL still names the previous page until the new one has loaded. The
 * tree's selection outlives the module, so it counts only where the module is
 * about a page (its URL carries one); the Dashboard still sends no page.
 */

import {describe, test, expect} from '@jest/globals';
import {ModuleStateStorage} from '@typo3/backend/storage/module-state-storage.js';
import {currentBackendContext} from '../../Resources/Public/JavaScript/chat-core.js';

describe('the page the chat is about', () => {
    function backendWindow(search) {
        return {
            document: {querySelector: () => ({module: 'web_layout'})},
            frames: {list_frame: {location: {search}}},
        };
    }

    test('the page tree\'s selection wins over the module URL, which lags behind a click', () => {
        const context = currentBackendContext(backendWindow('?id=12&language=1'), () => ({identifier: '7'}));

        expect(context.pageId).toBe(7);
        // The language in the URL belongs to page 12, not to the selection.
        expect(context.languageId).toBeUndefined();
    });

    test('the same page in both keeps the URL\'s language', () => {
        expect(currentBackendContext(backendWindow('?id=7&language=1'), () => ({identifier: '7'})))
            .toEqual({pageId: 7, module: 'web_layout', languageId: 1});
    });

    test('no selection in the tree: the URL\'s page', () => {
        expect(currentBackendContext(backendWindow('?id=12'), () => ({identifier: ''})).pageId).toBe(12);
        expect(currentBackendContext(backendWindow('?id=12'), () => { throw new Error('no storage'); }).pageId).toBe(12);
    });

    test('a module that is not about a page sends none, whatever the tree holds', () => {
        expect(currentBackendContext(backendWindow('?token=x'), () => ({identifier: '7'})).pageId).toBe(0);
    });

    test('by default the selection comes from ModuleStateStorage', () => {
        sessionStorage.clear();
        ModuleStateStorage.updateWithTreeIdentifier('web', '9', '0_9');

        expect(currentBackendContext(backendWindow('?id=12')).pageId).toBe(9);
        sessionStorage.clear();
    });
});
