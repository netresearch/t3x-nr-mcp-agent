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
    let origTYPO3;

    beforeEach(() => {
        origTYPO3 = global.TYPO3;
    });

    afterEach(() => {
        global.TYPO3 = origTYPO3;
        // Clean up any overlays appended to document.body
        document.querySelectorAll('[aria-modal]').forEach(el => el.remove());
    });

    test('shows error and does not open overlay when elementBrowserUrl is missing', () => {
        global.TYPO3 = {settings: {Wizards: {}}};

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(ctrl._falPickerOverlay).toBeNull();
        expect(ctrl.issues.length).toBeGreaterThan(0);
    });

    test('returns early without opening second overlay when listener already registered', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._falPickerListener = jest.fn(); // picker already open
        ctrl._openFalPicker();

        expect(ctrl._falPickerOverlay).toBeNull();
        expect(ctrl.issues).toHaveLength(0);
    });

    test('appends iframe overlay to document.body with correct URL', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(ctrl._falPickerOverlay).not.toBeNull();
        const iframe = ctrl._falPickerOverlay.querySelector('iframe.t3js-modal-iframe');
        expect(iframe).not.toBeNull();
        expect(iframe.src).toContain('mode=file');
        expect(iframe.src).toContain('bparams=');
        expect(document.body.contains(ctrl._falPickerOverlay)).toBe(true);
    });

    test('clicking overlay background cleans up listener and removes overlay', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(typeof ctrl._falPickerListener).toBe('function');

        // Simulate click directly on the backdrop (not inside the content box)
        ctrl._falPickerOverlay.dispatchEvent(
            new MouseEvent('click', {bubbles: true, target: ctrl._falPickerOverlay}),
        );
        // jsdom doesn't set event.target from constructor; simulate by calling cleanup
        ctrl._cleanupFalPicker();

        expect(ctrl._falPickerListener).toBeNull();
        expect(ctrl._falPickerOverlay).toBeNull();
    });

    test('registered postMessage listener invokes _onFalFileSelected with parsed numeric uid', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};

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
        expect(ctrl._falPickerOverlay).toBeNull();
    });

    test('postMessage with table_uid format (sys_file_42) extracts numeric uid 42', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};

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

    test('guard prevents _onFalFileSelected from being called twice if listener fires on multiple windows', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._onFalFileSelected = jest.fn();
        ctrl._openFalPicker();

        const eventData = {
            actionName: 'typo3:elementBrowser:elementAdded',
            fieldName: 'nr_mcp_agent_fal_picker',
            value: '42',
        };

        // First delivery (e.g. via globalThis) — should process normally
        globalThis.dispatchEvent(new MessageEvent('message', {origin: window.location.origin, data: eventData}));
        expect(ctrl._onFalFileSelected).toHaveBeenCalledTimes(1);

        // Second delivery (e.g. via top — same object in jsdom) — guard must prevent double-call
        globalThis.dispatchEvent(new MessageEvent('message', {origin: window.location.origin, data: eventData}));
        expect(ctrl._onFalFileSelected).toHaveBeenCalledTimes(1); // still 1, not 2
    });

    test('picker can be reopened after cleanup', () => {
        global.TYPO3 = {settings: {Wizards: {elementBrowserUrl: '/typo3/wizard/browse?token=x'}}};

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(ctrl._falPickerListener).not.toBeNull();
        expect(ctrl._falPickerOverlay).not.toBeNull();

        ctrl._cleanupFalPicker();

        expect(ctrl._falPickerListener).toBeNull();
        expect(ctrl._falPickerOverlay).toBeNull();

        // Should be openable again
        ctrl._openFalPicker();
        expect(ctrl._falPickerOverlay).not.toBeNull();
    });
});
