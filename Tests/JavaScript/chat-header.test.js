// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The header of a guided process (ADR-023): page, language by name and the
 * point, from the conversation rather than the model; short of space the
 * page name gives way, the count never.
 */

import {describe, test, expect, beforeAll} from '@jest/globals';
import {chatGuidedStyles} from '../../Resources/Public/JavaScript/chat-guided.js';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

async function render(modulePath, tag, open, {tour, progress}) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'SEO', status: 'idle', messageCount: 0, pinned: false}],
        messages: [],
        status: 'idle',
        errorMessage: '',
        tour,
        guided: {progress, highlight: null},
    });
    if (open) {
        el.state = 'OPEN';
    }
    el.requestUpdate();
    await el.updateComplete;

    return el.shadowRoot.querySelector('.guided-progress');
}

const TOUR = {pageTitle: 'Über uns – Unternehmen und Geschichte', languageName: 'Deutsch'};
const POINT = {label: 'vom Modell', current: 2, total: 5, completed: false};

describe('header layout', () => {
    test('the page name shrinks, language and count do not', () => {
        const rules = chatGuidedStyles.cssText.replace(/\s+/g, ' ');

        expect(rules).toMatch(/\.guided-page \{[^}]*flex: 0 1 auto;[^}]*text-overflow: ellipsis;/);
        expect(rules).toMatch(/\.guided-language, \.guided-count \{ flex: none; \}/);
    });
});

describe.each(SURFACES)('$name header', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('page · language · point, from the conversation, not the model\'s label', async () => {
        const header = await render(modulePath, tag, open, {tour: TOUR, progress: POINT});

        expect(header.getAttribute('role')).toBe('status');
        expect(header.querySelector('.guided-page').textContent).toBe(TOUR.pageTitle);
        expect(header.querySelector('.guided-language').textContent.trim()).toBe('· Deutsch');
        expect(header.querySelector('.guided-count').textContent.trim()).toBe('· guided.progress');
        expect(header.textContent).not.toContain('vom Modell');
    });

    test('the tour arrives with the conversation from the server', async () => {
        const header = await render(modulePath, tag, open, {tour: null, progress: null});
        expect(header).toBeNull();
        const el = header?.getRootNode()?.host ?? document.body.lastElementChild;
        el.chat._api.getMessages = async () => ({
            messages: [], totalCount: 0, status: 'idle', errorMessage: '',
            guided: {progress: null, highlight: null}, tour: TOUR,
        });

        await el.chat.loadMessages();
        el.requestUpdate();
        await el.updateComplete;

        expect(el.shadowRoot.querySelector('.guided-page').textContent).toBe(TOUR.pageTitle);
    });

    test('before the first report the process is analysing', async () => {
        const header = await render(modulePath, tag, open, {tour: TOUR, progress: null});

        expect(header.querySelector('.guided-count').textContent.trim()).toBe('· guided.analysing');
    });

    test('a page outside every site shows no language', async () => {
        const header = await render(modulePath, tag, open, {tour: {...TOUR, languageName: ''}, progress: POINT});

        expect(header.querySelector('.guided-language')).toBeNull();
    });

    test('without a tour the process\'s own label stays', async () => {
        const header = await render(modulePath, tag, open, {tour: null, progress: POINT});

        expect(header.querySelector('.guided-page')).toBeNull();
        expect(header.textContent).toContain('vom Modell');
    });

    test('without a tour or progress there is no header line', async () => {
        expect(await render(modulePath, tag, open, {tour: null, progress: null})).toBeNull();
    });
});
