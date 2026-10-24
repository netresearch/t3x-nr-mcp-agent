// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Moving the panel while the controller has a request in flight.
 *
 * A move reconnects the element: hostDisconnected() aborts every request and
 * stops the poll, hostConnected() and the panel start a new poll loop. A poll
 * whose request was in flight must then end quietly. It used to reschedule
 * itself as well, because the host was connected again by the time its
 * request failed, and from then on two loops polled side by side. An aborted
 * request was also counted as a connection failure.
 */

import {describe, test, expect, beforeAll, beforeEach, afterEach, jest} from '@jest/globals';
import Persistent from '@typo3/backend/storage/persistent.js';

function el(tag, attributes = {}, children = []) {
    const element = document.createElement(tag);
    for (const [name, value] of Object.entries(attributes)) {
        element.setAttribute(name, value);
    }
    element.append(...children);

    return element;
}

/** Let promise callbacks run while the timers are fake. */
async function microtasks(rounds = 10) {
    for (let i = 0; i < rounds; i++) {
        await Promise.resolve();
    }
}

describe('a move while a request is in flight', () => {
    let polls;
    let panel;
    let area;

    beforeAll(async () => {
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
    });

    beforeEach(async () => {
        globalThis.TYPO3 = {settings: {ajaxUrls: new Proxy({}, {get: (_, key) => '/ajax/' + String(key)})}};
        polls = [];
        // Requests never answer; an aborted one rejects like fetch does.
        globalThis.fetch = jest.fn((url, options) => new Promise((resolve, reject) => {
            if (String(url).includes('messages')) {
                polls.push(String(url));
            }
            options?.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
        }));
        Persistent.reset();
        localStorage.clear();
        area = el('div', {slot: 'content'}, [el('typo3-backend-module-router')]);
        document.body.replaceChildren(el('div', {class: 'scaffold-content t3js-scaffold-content'}, [
            el('typo3-backend-content-navigation', {identifier: 'backend'}, [el('div', {slot: 'navigation'}), area]),
        ]));
        panel = document.createElement('ai-chat-panel');
        document.body.append(panel);
        panel.state = 'expanded';
        Object.assign(panel.chat, {loading: false, issues: [], conversations: [], available: true, activeUid: null});
        await panel.updateComplete;
        panel._areaWidth = 1400;
        await panel.updateComplete;
        expect(panel.setLayout('column')).toBe(true);
        await panel.updateComplete;
    });

    afterEach(() => {
        jest.useRealTimers();
        panel.chat.stopPolling();
        panel.remove();
        delete globalThis.fetch;
        delete globalThis.TYPO3;
    });

    test('leaves one poll loop, not two', async () => {
        jest.useFakeTimers();
        Object.assign(panel.chat, {activeUid: 1, status: 'processing'});
        panel.chat.startPollingIfNeeded();
        jest.advanceTimersByTime(2000);
        expect(polls).toHaveLength(1);

        // The area gets too narrow while that request is in flight.
        panel._areaWidth = 600;
        await microtasks();
        await panel.updateComplete;
        await microtasks();
        expect(panel.parentElement).toBe(document.body);

        // One loop asks once per interval (its request then hangs, as all do
        // here); two loops asked twice.
        const before = polls.length;
        jest.advanceTimersByTime(2000);

        expect(polls.length - before).toBe(1);
    });

    test('an aborted poll is not a connection failure', async () => {
        jest.useFakeTimers();
        Object.assign(panel.chat, {activeUid: 1, status: 'processing'});
        panel.chat.startPollingIfNeeded();
        jest.advanceTimersByTime(2000);

        panel._areaWidth = 600;
        await microtasks();
        await panel.updateComplete;
        await microtasks();

        expect(panel.chat._pollFailures).toBe(0);
    });

    test('an attachment being uploaded holds the move until it is done', async () => {
        let finish;
        panel.chat._api.uploadFile = () => new Promise((resolve) => { finish = resolve; });
        Object.assign(panel.chat, {maxFileSize: 1024});

        const upload = panel.chat.handleFileUpload({size: 10, name: 'a.txt'});
        expect(panel.chat.uploading).toBe(true);
        panel._areaWidth = 600;
        await panel.updateComplete;
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(panel.parentElement).toBe(area);

        finish({fileUid: 7, name: 'a.txt'});
        await upload;
        expect(panel.chat.uploading).toBe(false);
        await panel.updateComplete;
        await new Promise((resolve) => setTimeout(resolve, 0));
        await panel.updateComplete;
        expect(panel.parentElement).toBe(document.body);
        expect(panel.chat.pendingFile).toEqual({fileUid: 7, name: 'a.txt'});
    });
});
