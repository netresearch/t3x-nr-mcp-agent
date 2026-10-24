// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The chat as a column beside the page tree (ADR-024).
 *
 * TYPO3 has no API for a column, so the content area is found by its shape,
 * which differs between 13.4 and 14.3. CI's E2E run covers 13.4 only; the 14.3
 * shape is pinned here. The splitter follows the WAI-ARIA Window Splitter
 * pattern, and the width and the layout are stored in the backend user's
 * settings (Persistent, stubbed in __mocks__).
 */

import {describe, test, expect, beforeAll, beforeEach, afterEach, jest} from '@jest/globals';
import Persistent from '@typo3/backend/storage/persistent.js';
import {ModuleStateStorage} from '@typo3/backend/storage/module-state-storage.js';
import {
    COLUMN_DEFAULT_WIDTH,
    COLUMN_KEY_STEP,
    COLUMN_MIN_WIDTH,
    LAYOUT,
    MOUNT_ATTRIBUTE,
    PREFERENCE_COLUMN_WIDTH,
    PREFERENCE_LAYOUT,
    attachPanel,
    clampColumnWidth,
    columnMaxWidth,
    findColumnMount,
    readColumnWidth,
    readLayoutPreference,
    saveColumnWidth,
    saveLayoutPreference,
    splitterKeyWidth,
} from '../../Resources/Public/JavaScript/chat-column.js';

/** Let pending promise callbacks and timers of zero delay run. */
function flushPromises() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/** An element with attributes and children. */
function el(tag, attributes = {}, children = []) {
    const element = document.createElement(tag);
    for (const [name, value] of Object.entries(attributes)) {
        element.setAttribute(name, value);
    }
    element.append(...children);

    return element;
}

/** What the content area holds: the router, or the infobox core shows without module access. */
function routerOrInfobox(router) {
    return router ? el('typo3-backend-module-router') : el('div', {class: 'm-4'}, ['No access']);
}

/** The backend scaffold of TYPO3 14.3 (Main.fluid.html). */
function scaffold143({router = true} = {}) {
    const content = el('div', {slot: 'content'}, [routerOrInfobox(router)]);
    document.body.replaceChildren(el('div', {class: 'scaffold-content t3js-scaffold-content'}, [
        el('typo3-backend-content-navigation', {identifier: 'backend'}, [el('div', {slot: 'navigation'}), content]),
    ]));

    return content;
}

/** The backend scaffold of TYPO3 13.4 (Main.html). */
function scaffold134({router = true} = {}) {
    const content = el('div', {class: 'scaffold-content-module t3js-scaffold-content-module'}, router ? [routerOrInfobox(true)] : []);
    document.body.replaceChildren(el('div', {class: 'scaffold-content t3js-scaffold-content'}, [
        el('div', {class: 'scaffold-content-navigation t3js-scaffold-content-navigation'}),
        el('typo3-backend-navigation-switcher'),
        content,
    ]));

    return content;
}

describe('finding the content area', () => {
    test('14.3: the content slot of the backend content navigation', () => {
        const area = scaffold143();
        const mount = findColumnMount(document);

        expect(mount.element).toBe(area);
        expect(mount.router.localName).toBe('typo3-backend-module-router');
    });

    test('13.4: the scaffold content module', () => {
        const area = scaffold134();

        expect(findColumnMount(document).element).toBe(area);
    });

    test('no router there (workspace denied, no module access): no column', () => {
        scaffold143({router: false});
        expect(findColumnMount(document)).toBeNull();

        scaffold134({router: false});
        expect(findColumnMount(document)).toBeNull();
    });

    test('a document without a backend scaffold: no column', () => {
        document.body.replaceChildren(el('typo3-backend-module-router'));

        expect(findColumnMount(document)).toBeNull();
        expect(findColumnMount(null)).toBeNull();
    });
});

describe('placing a new panel', () => {
    beforeEach(() => Persistent.reset());

    test('the column, when the user chose it and the backend has a content area', () => {
        const area = scaffold143();
        Persistent.data = {nrMcpAgent: {chatPanel: {layout: 'column'}}};
        const panel = document.createElement('div');

        attachPanel(panel, document);

        expect(panel.layout).toBe(LAYOUT.COLUMN);
        expect(panel.parentElement).toBe(area);
        expect(panel.nextElementSibling.localName).toBe('typo3-backend-module-router');
    });

    test('in the body, keeping the column as the choice, when the area measures too narrow at load', () => {
        const area = scaffold143();
        area.getBoundingClientRect = () => ({width: 2 * COLUMN_MIN_WIDTH - 1, height: 600, top: 0, left: 0, right: 0, bottom: 0});
        Persistent.data = {nrMcpAgent: {chatPanel: {layout: 'column'}}};
        const panel = document.createElement('div');

        attachPanel(panel, document);

        expect(panel.layout).toBe(LAYOUT.COLUMN);
        expect(panel.parentElement).toBe(document.body);
        expect(Persistent.writes).toEqual([]);
    });

    test('floating in the body when the backend has no content area, keeping the choice', () => {
        document.body.replaceChildren();
        Persistent.data = {nrMcpAgent: {chatPanel: {layout: 'column'}}};
        const panel = document.createElement('div');

        attachPanel(panel, document);

        expect(panel.layout).toBe(LAYOUT.FLOATING);
        expect(panel.parentElement).toBe(document.body);
        expect(Persistent.writes).toEqual([]);
    });

    test('floating in the body when the user did not choose the column', () => {
        scaffold134();
        const panel = document.createElement('div');

        attachPanel(panel, document);

        expect(panel.layout).toBe(LAYOUT.FLOATING);
        expect(panel.parentElement).toBe(document.body);
    });
});

describe('column width limits', () => {
    test('at least the minimum, at most half the content area', () => {
        expect(clampColumnWidth(100, 1400)).toBe(COLUMN_MIN_WIDTH);
        expect(clampColumnWidth(500, 1400)).toBe(500);
        expect(clampColumnWidth(900, 1400)).toBe(700);
        expect(columnMaxWidth(1401)).toBe(700);
    });

    test('an area narrower than twice the minimum keeps the minimum', () => {
        expect(columnMaxWidth(500)).toBe(COLUMN_MIN_WIDTH);
        expect(clampColumnWidth(600, 500)).toBe(COLUMN_MIN_WIDTH);
    });

    test('a width that is no number becomes the default', () => {
        expect(clampColumnWidth(Number.NaN, 1400)).toBe(COLUMN_DEFAULT_WIDTH);
    });
});

describe('splitter keys (WAI-ARIA Window Splitter)', () => {
    test('the arrows step, right widens the column on its left', () => {
        expect(splitterKeyWidth('ArrowRight', 400, 1400)).toBe(400 + COLUMN_KEY_STEP);
        expect(splitterKeyWidth('ArrowLeft', 400, 1400)).toBe(400 - COLUMN_KEY_STEP);
    });

    test('Home and End jump to the limits', () => {
        expect(splitterKeyWidth('Home', 500, 1400)).toBe(COLUMN_MIN_WIDTH);
        expect(splitterKeyWidth('End', 500, 1400)).toBe(700);
    });

    test('a step never leaves the limits', () => {
        expect(splitterKeyWidth('ArrowLeft', COLUMN_MIN_WIDTH, 1400)).toBe(COLUMN_MIN_WIDTH);
        expect(splitterKeyWidth('ArrowRight', 700, 1400)).toBe(700);
    });

    test('right-to-left: the column is on the right, so the arrows swap', () => {
        expect(splitterKeyWidth('ArrowLeft', 400, 1400, true)).toBe(400 + COLUMN_KEY_STEP);
        expect(splitterKeyWidth('ArrowRight', 400, 1400, true)).toBe(400 - COLUMN_KEY_STEP);
    });

    test('other keys are not the splitter\'s', () => {
        expect(splitterKeyWidth('Enter', 400, 1400)).toBeNull();
        expect(splitterKeyWidth('ArrowUp', 400, 1400)).toBeNull();
    });
});

describe('preferences in the backend user\'s settings', () => {
    beforeEach(() => Persistent.reset());

    test('floating unless the column was chosen', () => {
        expect(readLayoutPreference()).toBe(LAYOUT.FLOATING);
        Persistent.data = {nrMcpAgent: {chatPanel: {layout: 'something else'}}};
        expect(readLayoutPreference()).toBe(LAYOUT.FLOATING);
    });

    test('the layout and the width are written under the extension\'s keys', () => {
        saveLayoutPreference(LAYOUT.COLUMN);
        saveColumnWidth(412.4);

        expect(Persistent.writes).toEqual([[PREFERENCE_LAYOUT, 'column'], [PREFERENCE_COLUMN_WIDTH, '412']]);
        expect(readLayoutPreference()).toBe(LAYOUT.COLUMN);
        expect(readColumnWidth()).toBe(412);
    });

    test('a stored width below the minimum or unreadable gives the default', () => {
        Persistent.data = {nrMcpAgent: {chatPanel: {columnWidth: '12'}}};
        expect(readColumnWidth()).toBe(COLUMN_DEFAULT_WIDTH);
        Persistent.data = {nrMcpAgent: {chatPanel: {columnWidth: 'wide'}}};
        expect(readColumnWidth()).toBe(COLUMN_DEFAULT_WIDTH);
    });

    test('a failing settings request reaches neither the reader nor the writer', async () => {
        const failing = {get: () => { throw new Error('500'); }, set: () => Promise.reject(new Error('500'))};

        expect(readLayoutPreference(failing)).toBe(LAYOUT.FLOATING);
        expect(readColumnWidth(failing)).toBe(COLUMN_DEFAULT_WIDTH);
        expect(() => saveColumnWidth(400, failing)).not.toThrow();
        await Promise.resolve();
    });
});

describe('<ai-chat-panel> as a column', () => {
    let panel;
    let area;

    beforeAll(async () => {
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
    });

    beforeEach(async () => {
        Persistent.reset();
        localStorage.clear();
        area = scaffold143();
        panel = document.createElement('ai-chat-panel');
        document.body.append(panel);
        panel.state = 'expanded';
        Object.assign(panel.chat, {loading: false, issues: [], conversations: [], available: true, activeUid: null});
        await panel.updateComplete;
        // jsdom lays nothing out; give the column a content area to share.
        panel._areaWidth = 1400;
        await panel.updateComplete;
    });

    afterEach(() => {
        jest.useRealTimers();
        panel.remove();
        document.body.replaceChildren();
    });

    async function dock() {
        expect(panel.setLayout('column')).toBe(true);
        Object.assign(panel.chat, {loading: false});
        panel._areaWidth = 1400;
        await panel.updateComplete;
    }

    const splitter = () => panel.shadowRoot.querySelector('.column-splitter');
    const press = (key) => splitter().dispatchEvent(new KeyboardEvent('keydown', {key, bubbles: true, cancelable: true}));

    test('docks before the module router, marks the area and stores the choice', async () => {
        await dock();

        expect(panel.parentElement).toBe(area);
        expect(panel.nextElementSibling.localName).toBe('typo3-backend-module-router');
        expect(panel.getAttribute('layout')).toBe('column');
        expect(area.getAttribute(MOUNT_ATTRIBUTE)).toBe('open');
        expect(Persistent.writes).toContainEqual([PREFERENCE_LAYOUT, 'column']);
    });

    test('the floating header offers the column, the column header leaves it', async () => {
        const toggle = () => panel.shadowRoot.querySelector('[data-action="layout"]');
        expect(toggle().getAttribute('aria-pressed')).toBe('false');

        toggle().click();
        Object.assign(panel.chat, {loading: false});
        await panel.updateComplete;
        expect(panel.layout).toBe('column');
        expect(toggle().getAttribute('aria-pressed')).toBe('true');

        toggle().click();
        await panel.updateComplete;
        expect(panel.layout).toBe('floating');
        expect(panel.parentElement).toBe(document.body);
        expect(area.hasAttribute(MOUNT_ATTRIBUTE)).toBe(false);
    });

    test('no content area: the layout stays floating and no toggle is offered', async () => {
        document.body.replaceChildren(panel);
        await panel.updateComplete;

        expect(panel.setLayout('column')).toBe(false);
        expect(panel.layout).toBe('floating');
        panel.requestUpdate();
        await panel.updateComplete;
        expect(panel.shadowRoot.querySelector('[data-action="layout"]')).toBeNull();
    });

    test('the conversation, the draft and an attachment survive the move', async () => {
        const file = {uid: 3, name: 'brief.pdf', mimeType: 'application/pdf'};
        Object.assign(panel.chat, {activeUid: 42, inputValue: 'half a sentence', hasInput: true, pendingFile: file});

        await dock();
        panel.setLayout('floating');
        // The move re-runs the controller's init(); let it settle before
        // looking, or a reset inside it would go unseen.
        await flushPromises();
        await panel.updateComplete;

        expect(panel.chat.activeUid).toBe(42);
        expect(panel.chat.inputValue).toBe('half a sentence');
        expect(panel.chat.pendingFile).toBe(file);
    });

    test('the splitter: a focusable vertical separator with its value in px', async () => {
        await dock();
        const handle = splitter();

        expect(handle.getAttribute('role')).toBe('separator');
        expect(handle.getAttribute('aria-orientation')).toBe('vertical');
        expect(handle.getAttribute('tabindex')).toBe('0');
        expect(handle.getAttribute('aria-label')).toBe('panel.columnResize');
        expect(handle.getAttribute('aria-valuemin')).toBe(String(COLUMN_MIN_WIDTH));
        expect(handle.getAttribute('aria-valuemax')).toBe('700');
        expect(handle.getAttribute('aria-valuenow')).toBe(String(COLUMN_DEFAULT_WIDTH));
        expect(panel.shadowRoot.getElementById(handle.getAttribute('aria-controls'))).not.toBeNull();
        expect(panel.style.width).toBe(`${COLUMN_DEFAULT_WIDTH}px`);
    });

    test('the arrow keys step the width, Home and End jump to the limits', async () => {
        await dock();

        press('ArrowRight');
        press('ArrowRight');
        await panel.updateComplete;
        expect(splitter().getAttribute('aria-valuenow')).toBe(String(COLUMN_DEFAULT_WIDTH + 2 * COLUMN_KEY_STEP));
        expect(panel.style.width).toBe(`${COLUMN_DEFAULT_WIDTH + 2 * COLUMN_KEY_STEP}px`);

        press('ArrowLeft');
        await panel.updateComplete;
        expect(splitter().getAttribute('aria-valuenow')).toBe(String(COLUMN_DEFAULT_WIDTH + COLUMN_KEY_STEP));

        press('Home');
        await panel.updateComplete;
        expect(splitter().getAttribute('aria-valuenow')).toBe(String(COLUMN_MIN_WIDTH));

        press('End');
        await panel.updateComplete;
        expect(splitter().getAttribute('aria-valuenow')).toBe('700');
    });

    test('the width is stored once, after the last key press', async () => {
        await dock();
        jest.useFakeTimers();
        Persistent.writes = [];

        press('ArrowRight');
        press('ArrowRight');
        press('ArrowRight');
        expect(Persistent.writes).toEqual([]);

        jest.runAllTimers();
        expect(Persistent.writes).toEqual([[PREFERENCE_COLUMN_WIDTH, String(COLUMN_DEFAULT_WIDTH + 3 * COLUMN_KEY_STEP)]]);
    });

    test('a key the splitter does not handle is left alone', async () => {
        await dock();
        const event = new KeyboardEvent('keydown', {key: 'Tab', bubbles: true, cancelable: true});

        splitter().dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(panel._columnWidth).toBe(COLUMN_DEFAULT_WIDTH);
    });

    test('a stored width is shown, kept inside a narrower area without being rewritten', async () => {
        Persistent.data = {nrMcpAgent: {chatPanel: {columnWidth: '650'}}};
        await dock();
        expect(splitter().getAttribute('aria-valuenow')).toBe('650');

        panel._areaWidth = 1000;
        await panel.updateComplete;
        expect(splitter().getAttribute('aria-valuenow')).toBe('500');
        expect(panel._columnWidth).toBe(650);
        expect(Persistent.writes.filter(([key]) => key === PREFERENCE_COLUMN_WIDTH)).toEqual([]);
    });

    test('a pointer drag resizes and stores the width once, at the end', async () => {
        await dock();
        Persistent.writes = [];
        const handle = splitter();
        handle.setPointerCapture = () => {};
        const pointer = (type, clientX) => {
            const event = new MouseEvent(type, {bubbles: true, cancelable: true, clientX, button: 0});
            Object.defineProperty(event, 'isPrimary', {value: true});
            Object.defineProperty(event, 'pointerId', {value: 1});
            handle.dispatchEvent(event);
        };

        pointer('pointerdown', 500);
        pointer('pointermove', 560);
        pointer('pointermove', 600);
        await panel.updateComplete;
        expect(splitter().getAttribute('aria-valuenow')).toBe(String(COLUMN_DEFAULT_WIDTH + 100));
        expect(Persistent.writes).toEqual([]);

        pointer('pointerup', 600);
        expect(Persistent.writes).toEqual([[PREFERENCE_COLUMN_WIDTH, String(COLUMN_DEFAULT_WIDTH + 100)]]);
    });

    test('minimized: a bar with the state, the area gets its width back', async () => {
        await dock();
        Object.assign(panel.chat, {activeUid: 5, status: 'awaiting_approval', conversations: [{uid: 5, title: 'Improve content', status: 'awaiting_approval'}]});

        panel.collapse();
        await panel.updateComplete;

        expect(area.hasAttribute(MOUNT_ATTRIBUTE)).toBe(false);
        expect(panel.parentElement).toBe(area);
        const bar = panel.shadowRoot.querySelector('.minimized-bar');
        expect(bar.querySelector('.minimized-title').textContent).toBe('Improve content');
        expect(bar.querySelector('.minimized-status').textContent.trim()).toBe('status.awaitingApproval');
        expect(bar.querySelector('.status-dot')).not.toBeNull();
        expect(panel.shadowRoot.querySelector('.column-splitter')).toBeNull();
        expect(panel.style.width).toBe('');

        bar.querySelector('[data-action="reopen"]').click();
        await panel.updateComplete;
        expect(panel.state).toBe('expanded');
        expect(area.getAttribute(MOUNT_ATTRIBUTE)).toBe('open');
    });

    test('closed: the area gets its width back', async () => {
        await dock();

        panel.shadowRoot.querySelector('[data-action="close"]').click();
        await panel.updateComplete;

        expect(panel.state).toBe('hidden');
        expect(area.hasAttribute(MOUNT_ATTRIBUTE)).toBe(false);
    });

    test('no maximized state in the column', async () => {
        await dock();

        panel.maximize();

        expect(panel.state).toBe('expanded');
    });

    test('Escape outside the column belongs to the page tree, not to the chat', async () => {
        await dock();
        const outside = document.createElement('input');
        document.body.append(outside);
        outside.focus();

        document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));

        expect(panel.state).toBe('expanded');
    });

    test('a tree click, a module state update and a loaded module refresh the chat\'s page, once each after a move', async () => {
        await dock();
        const refresh = jest.spyOn(panel.chat, 'refreshBackendPage').mockImplementation(() => {});

        ModuleStateStorage.updateWithTreeIdentifier('web', '4', '0_4');
        ModuleStateStorage.update('web', '5');
        document.dispatchEvent(new CustomEvent('typo3-module-loaded', {detail: {}}));

        expect(refresh).toHaveBeenCalledTimes(3);
        refresh.mockRestore();
        sessionStorage.clear();
    });

    test('asking for the column from the collapsed floating panel opens the column, with focus on its toggle', async () => {
        panel.collapse();
        await panel.updateComplete;
        expect(panel.state).toBe('collapsed');

        panel.shadowRoot.querySelector('[data-action="layout"]').click();
        Object.assign(panel.chat, {loading: false});
        await panel.updateComplete;
        await panel.updateComplete;

        expect(panel.state).toBe('expanded');
        expect(area.getAttribute(MOUNT_ATTRIBUTE)).toBe('open');
        expect(panel.shadowRoot.querySelector('.minimized-bar')).toBeNull();
        expect(panel.shadowRoot.activeElement?.dataset.action).toBe('layout');
    });

    /** Let an update, the placement after it and the next update run. */
    async function settle() {
        await panel.updateComplete;
        await flushPromises();
        await panel.updateComplete;
    }

    test('a content area narrower than twice the minimum lets the chat float from the body, without changing the choice', async () => {
        await dock();
        const layoutWrites = () => Persistent.writes.filter(([key]) => key === PREFERENCE_LAYOUT).length;
        const before = layoutWrites();

        panel._areaWidth = 2 * COLUMN_MIN_WIDTH - 1;
        await settle();

        expect(panel.hasAttribute('narrow')).toBe(true);
        expect(panel.layout).toBe('column');
        // In 14.3 the content area is a stacking layer under the backend
        // header once it is narrow; the floating panel belongs in the body.
        expect(panel.parentElement).toBe(document.body);
        expect(area.hasAttribute(MOUNT_ATTRIBUTE)).toBe(false);
        expect(splitter()).toBeNull();
        // The floating header, without the toggle: there is no column to dock into.
        expect(panel.shadowRoot.querySelector('.column-badge')).toBeNull();
        expect(panel.shadowRoot.querySelector('[data-action="layout"]')).toBeNull();
        expect(layoutWrites()).toBe(before);

        // Docks again only some way above the threshold (dead band).
        panel._areaWidth = 2 * COLUMN_MIN_WIDTH + 24;
        await settle();

        expect(panel.hasAttribute('narrow')).toBe(false);
        expect(panel.parentElement).toBe(area);
        expect(panel.nextElementSibling.localName).toBe('typo3-backend-module-router');
        expect(area.getAttribute(MOUNT_ATTRIBUTE)).toBe('open');
        expect(splitter()).not.toBeNull();
        expect(layoutWrites()).toBe(before);
    });

    test('the floating panel offers no column in a narrow area, and refuses one without moving or storing', async () => {
        panel._areaWidth = 2 * COLUMN_MIN_WIDTH - 1;
        await settle();
        expect(panel.shadowRoot.querySelector('[data-action="layout"]')).toBeNull();

        const writes = Persistent.writes.length;
        expect(panel.setLayout('column')).toBe(false);
        expect(panel.layout).toBe('floating');
        expect(panel.parentElement).toBe(document.body);
        expect(Persistent.writes.length).toBe(writes);

        // The area grows (a wider window): the offer comes back without any
        // other change to the panel.
        panel._areaWidth = 2 * COLUMN_MIN_WIDTH;
        await settle();
        expect(panel.shadowRoot.querySelector('[data-action="layout"]')).not.toBeNull();
    });

    test('a column asked for is refused when the area measures too narrow, even if the last observation said otherwise', async () => {
        area.getBoundingClientRect = () => ({width: 2 * COLUMN_MIN_WIDTH - 1, height: 600, top: 0, left: 0, right: 0, bottom: 0});
        panel._areaWidth = 1400;

        expect(panel.setLayout('column')).toBe(false);
        expect(panel.parentElement).toBe(document.body);
        expect(Persistent.writes).toEqual([]);
    });

    test('a narrow area keeps the panel where it is while a request is in flight, and moves it after', async () => {
        await dock();
        panel.chat.approvalBusy = true;

        panel._areaWidth = 2 * COLUMN_MIN_WIDTH - 1;
        await settle();
        // Drawing and parent change together: still drawn as the column it is.
        expect(panel.hasAttribute('narrow')).toBe(false);
        expect(panel.parentElement).toBe(area);
        expect(splitter()).not.toBeNull();

        panel.chat.approvalBusy = false;
        panel.requestUpdate();
        await settle();
        expect(panel.hasAttribute('narrow')).toBe(true);
        expect(panel.parentElement).toBe(document.body);

        // And the other way: floating from the body while busy, the area
        // widens; it stays drawn floating until it may move.
        panel.chat.approvalBusy = true;
        panel._areaWidth = 1400;
        await settle();
        expect(panel.hasAttribute('narrow')).toBe(true);
        expect(panel.parentElement).toBe(document.body);
        expect(splitter()).toBeNull();

        panel.chat.approvalBusy = false;
        panel.requestUpdate();
        await settle();
        expect(panel.hasAttribute('narrow')).toBe(false);
        expect(panel.parentElement).toBe(area);
    });

    test('an area moving around the threshold does not move the panel back and forth (dead band)', async () => {
        await dock();
        panel._areaWidth = 2 * COLUMN_MIN_WIDTH - 1;
        await settle();
        expect(panel.parentElement).toBe(document.body);

        for (const width of [2 * COLUMN_MIN_WIDTH, 2 * COLUMN_MIN_WIDTH + 23, 2 * COLUMN_MIN_WIDTH + 1]) {
            panel._areaWidth = width;
            await settle();
            expect(panel.hasAttribute('narrow')).toBe(true);
            expect(panel.parentElement).toBe(document.body);
        }

        panel._areaWidth = 2 * COLUMN_MIN_WIDTH + 24;
        await settle();
        expect(panel.parentElement).toBe(area);
        // Docked, it gives way only below the threshold itself.
        panel._areaWidth = 2 * COLUMN_MIN_WIDTH;
        await settle();
        expect(panel.parentElement).toBe(area);
    });

    test('crossing the threshold keeps focus on the control that had it, not on the input', async () => {
        await dock();
        Object.assign(panel.chat, {activeUid: 5, status: 'idle', conversations: [{uid: 5, title: 'Improve content', status: 'idle'}], messages: []});
        panel.requestUpdate();
        await settle();
        expect(panel.shadowRoot.querySelector('.panel-input textarea')).not.toBeNull();
        panel.shadowRoot.querySelector('[data-action="close"]').focus();

        panel._areaWidth = 2 * COLUMN_MIN_WIDTH - 1;
        await settle();
        await flushPromises();

        expect(panel.parentElement).toBe(document.body);
        const active = panel.shadowRoot.activeElement;
        expect(active?.dataset.action).toBe('close');
        expect(active?.isConnected).toBe(true);
    });

    test.each(['sending', 'inputBusy', 'approvalBusy', 'endingTour', 'systemPromptSaving', 'uploading'])(
        'while %s the panel does not move',
        async (flag) => {
            await dock();
            panel.chat[flag] = true;
            panel.requestUpdate();
            await panel.updateComplete;

            expect(panel.shadowRoot.querySelector('[data-action="layout"]').disabled).toBe(true);
            expect(panel.setLayout('floating')).toBe(false);
            expect(panel.parentElement).toBe(area);
            panel.chat[flag] = false;
        },
    );

    test('crossing the narrow threshold keeps focus inside the panel', async () => {
        await dock();
        panel.shadowRoot.querySelector('[data-action="minimize"]').focus();
        expect(panel.shadowRoot.activeElement).not.toBeNull();

        panel._areaWidth = 2 * COLUMN_MIN_WIDTH - 1;
        await settle();
        await flushPromises();
        expect(panel.parentElement).toBe(document.body);
        expect(panel.shadowRoot.activeElement?.closest('.panel-header')).not.toBeNull();

        panel._areaWidth = 1400;
        await settle();
        await flushPromises();
        expect(panel.parentElement).toBe(area);
        expect(panel.shadowRoot.activeElement?.closest('.panel-header')).not.toBeNull();
    });

    test('maximized while floating in a narrow area, docked again as expanded', async () => {
        await dock();
        panel._areaWidth = 2 * COLUMN_MIN_WIDTH - 1;
        await settle();
        panel.maximize();
        await settle();
        expect(panel.state).toBe('maximized');

        panel._areaWidth = 1400;
        await settle();

        expect(panel.hasAttribute('narrow')).toBe(false);
        expect(panel.state).toBe('expanded');
        expect(panel.shadowRoot.querySelector('.panel-sidebar')).toBeNull();
    });

    test('minimizing moves focus to the bar\'s reopen button, reopening moves it back into the column', async () => {
        await dock();
        panel.shadowRoot.querySelector('[data-action="minimize"]').focus();

        panel.shadowRoot.querySelector('[data-action="minimize"]').click();
        await panel.updateComplete;
        await flushPromises();
        expect(panel.shadowRoot.activeElement?.dataset.action).toBe('reopen');

        panel.shadowRoot.querySelector('[data-action="reopen"]').click();
        await panel.updateComplete;
        await flushPromises();
        // No conversation is open, so there is no input; the minimize button it is.
        expect(panel.shadowRoot.activeElement?.dataset.action).toBe('minimize');
    });

    test('while a message is being sent the panel does not move', async () => {
        await dock();
        Object.defineProperty(window, 'documentPictureInPicture', {
            value: {requestWindow: jest.fn()},
            configurable: true,
        });
        panel.chat.sending = true;
        panel.requestUpdate();
        await panel.updateComplete;

        expect(panel.shadowRoot.querySelector('[data-action="layout"]').disabled).toBe(true);
        expect(panel.shadowRoot.querySelector('[data-action="popout"]').disabled).toBe(true);
        expect(panel.setLayout('floating')).toBe(false);
        expect(await panel.popOut()).toBe(false);
        expect(window.documentPictureInPicture.requestWindow).not.toHaveBeenCalled();
        expect(panel.parentElement).toBe(area);

        panel.chat.sending = false;
        panel.requestUpdate();
        await panel.updateComplete;
        expect(panel.shadowRoot.querySelector('[data-action="layout"]').disabled).toBe(false);
        delete window.documentPictureInPicture;
    });

    test('the minimized bar holds one live region for its status, not one inside another', async () => {
        await dock();
        Object.assign(panel.chat, {
            activeUid: 5,
            status: 'awaiting_approval',
            conversations: [{uid: 5, title: 'Improve content', status: 'awaiting_approval'}],
            tour: {pageTitle: 'Home', languageName: '', pageUid: 1},
            guided: {progress: {current: 1, total: 4, completed: false}, highlight: null},
        });
        panel.collapse();
        await panel.updateComplete;

        const status = panel.shadowRoot.querySelector('.minimized-status');
        expect(status.querySelectorAll('[role="status"]')).toHaveLength(1);
        expect(status.closest('[role="status"]')).toBeNull();

        // Without a guided process the plain status is the live region.
        Object.assign(panel.chat, {tour: null, guided: {progress: null, highlight: null}});
        panel.requestUpdate();
        await panel.updateComplete;
        expect(status.querySelectorAll('[role="status"]')).toHaveLength(1);
        expect(status.querySelector('[role="status"]').textContent).toBe('status.awaitingApproval');
    });

    test('back from its own window into the column, before the router', async () => {
        await dock();
        const pipWindow = {
            document: document.implementation.createHTMLDocument('pip'),
            addEventListener: jest.fn(),
        };
        Object.defineProperty(window, 'documentPictureInPicture', {
            value: {requestWindow: jest.fn().mockResolvedValue(pipWindow)},
            configurable: true,
        });

        await panel.popOut();
        expect(panel.layout).toBe('floating');
        expect(area.hasAttribute(MOUNT_ATTRIBUTE)).toBe(false);

        panel._returnFromPopOut();
        expect(panel.layout).toBe('column');
        expect(panel.parentElement).toBe(area);
        expect(panel.nextElementSibling.localName).toBe('typo3-backend-module-router');
        expect(area.getAttribute(MOUNT_ATTRIBUTE)).toBe('open');
        delete window.documentPictureInPicture;
    });
});
