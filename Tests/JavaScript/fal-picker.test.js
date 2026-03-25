/**
 * Tests for the FAL file picker logic in ChatCoreController.
 *
 * Covers _onFalFileSelected and _openFalPicker guard/error paths.
 * Window globals are manipulated directly on the global object.
 *
 * @jest-environment node
 */

import {jest, describe, test, expect, beforeEach, afterEach} from '@jest/globals';
// @typo3/core/lit-helper.js is not in node_modules; it is stubbed via moduleNameMapper
// in jest.config.js pointing to Tests/JavaScript/__mocks__/@typo3/core/lit-helper.js.
// jest.unstable_mockModule cannot be used here because the module is not resolvable
// without the mapper, and native ESM does not support jest.mock() hoisting.
import {ChatCoreController} from '../../Resources/Public/JavaScript/chat-core.js';

function makeHost() {
    return {
        addController: jest.fn(),
        requestUpdate: jest.fn(),
        addEventListener: jest.fn(),
        removeEventListener: jest.fn(),
    };
}

function makeController(host) {
    const ctrl = new ChatCoreController(host);
    ctrl._abortController = new AbortController();
    ctrl._api = {
        getStatus: jest.fn().mockResolvedValue({
            available: true, issues: [], visionSupported: false,
            maxFileSize: 0, supportedFormats: ['pdf', 'docx'],
        }),
        getFileInfo: jest.fn(),
        listConversations: jest.fn().mockResolvedValue({conversations: []}),
    };
    ctrl.supportedFormats = ['pdf', 'docx'];
    return ctrl;
}

// ── _onFalFileSelected ─────────────────────────────────────────────────────

describe('_onFalFileSelected', () => {
    test('calls getFileInfo then handleFileSelect on success', async () => {
        const host = makeHost();
        const ctrl = makeController(host);
        ctrl.handleFileSelect = jest.fn();
        ctrl._api.getFileInfo.mockResolvedValue({
            fileUid: 42, name: 'doc.pdf', mimeType: 'application/pdf', size: 1024,
        });

        await ctrl._onFalFileSelected(42);

        expect(ctrl._api.getFileInfo).toHaveBeenCalledWith(42);
        expect(ctrl.handleFileSelect).toHaveBeenCalledWith(42, 'doc.pdf', 'application/pdf');
    });

    test('calls _setError when getFileInfo rejects', async () => {
        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._api.getFileInfo.mockRejectedValue(new Error('File not found'));

        await ctrl._onFalFileSelected(99);

        expect(ctrl.issues).toContain('File not found');
    });
});

// ── _openFalPicker ─────────────────────────────────────────────────────────

describe('_openFalPicker', () => {
    let origOpen;

    beforeEach(() => {
        origOpen = global.open;
        delete global.setFormValueFromBrowseWin;
    });

    afterEach(() => {
        global.open = origOpen;
        delete global.setFormValueFromBrowseWin;
    });

    test('shows error and does not open popup when ajaxUrls file-browser key is missing', () => {
        // Simulate TYPO3 global without the file-browser key
        global.top = {TYPO3: {settings: {ajaxUrls: {}}}};
        global.open = jest.fn();

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(global.open).not.toHaveBeenCalled();
        expect(ctrl.issues.length).toBeGreaterThan(0);
    });

    test('returns early without opening second popup when callback already registered', () => {
        global.setFormValueFromBrowseWin = jest.fn(); // picker already open
        global.open = jest.fn();

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(global.open).not.toHaveBeenCalled();
        expect(ctrl.issues).toHaveLength(0);
    });

    test('shows error and cleans up callback when window.open returns null (popup blocked)', () => {
        global.top = {TYPO3: {settings: {ajaxUrls: {'file-browser': '/typo3/record/browse?mode=file'}}}};
        global.open = jest.fn().mockReturnValue(null);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(ctrl.issues.length).toBeGreaterThan(0);
        expect(global.setFormValueFromBrowseWin).toBeUndefined();
    });

    test('opens popup with correct URL including bparams when ajaxUrl available', () => {
        global.top = {TYPO3: {settings: {ajaxUrls: {'file-browser': '/typo3/record/browse?mode=file'}}}};
        const popup = {closed: false};
        global.open = jest.fn().mockReturnValue(popup);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(global.open).toHaveBeenCalledWith(
            expect.stringContaining('bparams='),
            'typo3FileBrowser',
            expect.any(String),
        );
        expect(typeof global.setFormValueFromBrowseWin).toBe('function');
    });

    test('registered callback invokes _onFalFileSelected with parsed numeric uid', () => {
        global.top = {TYPO3: {settings: {ajaxUrls: {'file-browser': '/typo3/record/browse?mode=file'}}}};
        const popup = {closed: false};
        global.open = jest.fn().mockReturnValue(popup);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._onFalFileSelected = jest.fn();
        ctrl._openFalPicker();

        // Simulate TYPO3 calling the callback after the user selected a file
        global.setFormValueFromBrowseWin('', '42', '');

        expect(ctrl._onFalFileSelected).toHaveBeenCalledWith(42);
    });

    test('callback with empty value does not call _onFalFileSelected', () => {
        global.top = {TYPO3: {settings: {ajaxUrls: {'file-browser': '/typo3/record/browse?mode=file'}}}};
        const popup = {closed: false};
        global.open = jest.fn().mockReturnValue(popup);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        ctrl._onFalFileSelected = jest.fn();
        // Simulate user cancelling — TYPO3 calls the callback with an empty value
        global.setFormValueFromBrowseWin('', '', '');

        expect(ctrl._onFalFileSelected).not.toHaveBeenCalled();
    });
});
