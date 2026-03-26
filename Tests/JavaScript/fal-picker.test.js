/**
 * Tests for the FAL file picker logic in ChatCoreController.
 *
 * Covers _onFalFileSelected and _openFalPicker guard/error paths.
 * Window globals are manipulated directly on the global object.
 *
 * @jest-environment jest-environment-jsdom
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
    let origTYPO3;

    beforeEach(() => {
        origOpen = global.open;
        origTYPO3 = global.TYPO3;
    });

    afterEach(() => {
        global.open = origOpen;
        global.TYPO3 = origTYPO3;
    });

    test('shows error and does not open popup when elementBrowserUrl is missing', () => {
        global.TYPO3 = {settings: {Wizards: {}}};
        global.open = jest.fn();

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(global.open).not.toHaveBeenCalled();
        expect(ctrl.issues.length).toBeGreaterThan(0);
    });

    test('returns early without opening second popup when listener already registered', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};
        global.open = jest.fn();

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._falPickerListener = jest.fn(); // picker already open
        ctrl._openFalPicker();

        expect(global.open).not.toHaveBeenCalled();
        expect(ctrl.issues).toHaveLength(0);
    });

    test('shows error and cleans up listener when window.open returns null (popup blocked)', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};
        global.open = jest.fn().mockReturnValue(null);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(ctrl.issues.length).toBeGreaterThan(0);
        expect(ctrl._falPickerListener).toBeNull();
    });

    test('opens popup with correct URL including mode=file and bparams when elementBrowserUrl available', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};
        const popup = {closed: false};
        global.open = jest.fn().mockReturnValue(popup);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(global.open).toHaveBeenCalledWith(
            expect.stringContaining('mode=file'),
            'typo3FileBrowser',
            expect.any(String),
        );
        expect(global.open).toHaveBeenCalledWith(
            expect.stringContaining('bparams='),
            'typo3FileBrowser',
            expect.any(String),
        );
        expect(typeof ctrl._falPickerListener).toBe('function');
    });

    test('registered postMessage listener invokes _onFalFileSelected with parsed numeric uid', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};
        const popup = {closed: false};
        global.open = jest.fn().mockReturnValue(popup);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._onFalFileSelected = jest.fn();
        ctrl._openFalPicker();

        // Simulate TYPO3 element browser sending postMessage after user selects a file
        const event = new MessageEvent('message', {
            origin: window.location.origin,
            data: {
                actionName: 'typo3:elementBrowser:elementAdded',
                fieldName: 'nr_mcp_agent_fal_picker',
                value: '42',
                label: 'doc.pdf',
            },
        });
        globalThis.dispatchEvent(event);

        expect(ctrl._onFalFileSelected).toHaveBeenCalledWith(42);
        // Listener should be cleaned up after a successful selection
        expect(ctrl._falPickerListener).toBeNull();
    });

    test('postMessage with table_uid format (sys_file_42) extracts numeric uid 42', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};
        const popup = {closed: false};
        global.open = jest.fn().mockReturnValue(popup);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._onFalFileSelected = jest.fn();
        ctrl._openFalPicker();

        // Simulate TYPO3 sending value in table_uid format (sys_file_42)
        const event = new MessageEvent('message', {
            origin: window.location.origin,
            data: {
                actionName: 'typo3:elementBrowser:elementAdded',
                fieldName: 'nr_mcp_agent_fal_picker',
                value: 'sys_file_42',
                label: 'doc.pdf',
            },
        });
        globalThis.dispatchEvent(event);

        expect(ctrl._onFalFileSelected).toHaveBeenCalledWith(42);
        expect(ctrl._falPickerListener).toBeNull();
    });

    test('postMessage with non-zero uid does not call _onFalFileSelected for wrong fieldName', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};
        const popup = {closed: false};
        global.open = jest.fn().mockReturnValue(popup);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._onFalFileSelected = jest.fn();
        ctrl._openFalPicker();

        // Message for a different field — should be ignored
        const event = new MessageEvent('message', {
            origin: window.location.origin,
            data: {
                actionName: 'typo3:elementBrowser:elementAdded',
                fieldName: 'some_other_field',
                value: '42',
            },
        });
        globalThis.dispatchEvent(event);

        expect(ctrl._onFalFileSelected).not.toHaveBeenCalled();
    });

    test('postMessage with value 0 does not call _onFalFileSelected (user cancelled)', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};
        const popup = {closed: false};
        global.open = jest.fn().mockReturnValue(popup);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        ctrl._onFalFileSelected = jest.fn();
        // Simulate cancel — value is empty string or 0
        const event = new MessageEvent('message', {
            origin: window.location.origin,
            data: {
                actionName: 'typo3:elementBrowser:elementAdded',
                fieldName: 'nr_mcp_agent_fal_picker',
                value: '',
            },
        });
        globalThis.dispatchEvent(event);

        expect(ctrl._onFalFileSelected).not.toHaveBeenCalled();
    });

    test('popup closed without selection cleans up listener so picker can be reopened', () => {
        jest.useFakeTimers();
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};
        const popup = {closed: false};
        global.open = jest.fn().mockReturnValue(popup);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(typeof ctrl._falPickerListener).toBe('function');

        // User closes popup without selecting
        popup.closed = true;
        jest.advanceTimersByTime(600); // trigger the 500ms interval

        expect(ctrl._falPickerListener).toBeNull();
        expect(ctrl._falPickerPollTimer).toBeNull();

        // Picker should now be openable again
        global.open.mockReturnValue({closed: false});
        ctrl._openFalPicker();
        expect(global.open).toHaveBeenCalledTimes(2);

        jest.useRealTimers();
    });
});
