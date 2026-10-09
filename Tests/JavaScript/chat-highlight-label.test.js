// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The highlight of a guided process (ADR-023): the page module frames the
 * element and adds a badge whose text is the page module's own label, never
 * anything the message carries; the floating panel tells a screen reader
 * which element is marked.
 */

import {describe, test, expect, beforeAll} from '@jest/globals';
import {handleHighlightMessage, highlightContentElement, HIGHLIGHT_MESSAGE} from '../../Resources/Public/JavaScript/page-highlight.js';
import {sendHighlight} from '../../Resources/Public/JavaScript/chat-guided.js';

function pageModule(label) {
    const doc = document.implementation.createHTMLDocument('page module');
    for (const [uid, text] of [[100, 'Wer wir sind'], [101, 'Team']]) {
        const element = doc.createElement('div');
        element.id = `element-tt_content-${uid}`;
        element.textContent = text;
        doc.body.append(element);
    }
    Object.defineProperty(doc, 'defaultView', {value: {TYPO3: label === undefined ? undefined : {lang: {'highlight.label': label}}}});

    return doc;
}

describe('the badge in the page module', () => {
    test('carries the page module\'s own label', () => {
        const doc = pageModule('Betrifft den aktuellen Vorschlag');

        expect(highlightContentElement(doc, 100)).toBe(true);
        const badge = doc.getElementById('element-tt_content-100').querySelector('.nr-mcp-agent-highlight-label');
        expect(badge.textContent).toBe('Betrifft den aktuellen Vorschlag');
    });

    test('falls back to a fixed text without a label', () => {
        const doc = pageModule(undefined);
        highlightContentElement(doc, 100);

        expect(doc.querySelector('.nr-mcp-agent-highlight-label').textContent).toBe('Concerns the current proposal');
    });

    test('moves with the highlight: one badge, on the marked element', () => {
        const doc = pageModule('Betrifft den aktuellen Vorschlag');
        highlightContentElement(doc, 100);
        highlightContentElement(doc, 101);

        expect(doc.querySelectorAll('.nr-mcp-agent-highlight-label')).toHaveLength(1);
        expect(doc.getElementById('element-tt_content-101').querySelector('.nr-mcp-agent-highlight-label')).not.toBeNull();
    });

    test('text sent with the message never reaches the badge', () => {
        const doc = pageModule('Betrifft den aktuellen Vorschlag');
        const parent = {};
        const win = {location: {origin: 'https://example.org'}, parent, document: doc};

        const applied = handleHighlightMessage({
            origin: 'https://example.org',
            source: parent,
            data: {type: HIGHLIGHT_MESSAGE, version: 1, table: 'tt_content', uid: 100, label: 'Klick mich'},
        }, win);

        expect(applied).toBe(true);
        expect(doc.querySelector('.nr-mcp-agent-highlight-label').textContent).toBe('Betrifft den aktuellen Vorschlag');
        expect(doc.body.textContent).not.toContain('Klick mich');
    });
});

describe('the announcement in the floating panel', () => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('sending a highlight sets the element to announce', () => {
        const chat = {activeUid: 1, guided: {highlight: {table: 'tt_content', uid: 100}}, _sentHighlight: '', highlightAnnounced: ''};
        const posted = [];
        const win = {frames: {list_frame: {postMessage: (message) => posted.push(message)}}, location: {origin: 'https://example.org'}};

        expect(sendHighlight(chat, win)).toBe(true);
        expect(chat.highlightAnnounced).toBe('100');
        expect(posted[0]).not.toHaveProperty('label');
    });

    test('nothing to announce when no page module received the highlight', () => {
        const chat = {activeUid: 1, guided: {highlight: {table: 'tt_content', uid: 100}}, _sentHighlight: '', highlightAnnounced: ''};

        expect(sendHighlight(chat, {frames: {}, location: {origin: 'https://example.org'}})).toBe(false);
        expect(chat.highlightAnnounced).toBe('');
    });

    test('the panel renders the announcement in a status region', async () => {
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
        const el = document.createElement('ai-chat-panel');
        document.body.append(el);
        Object.assign(el.chat, {
            activeUid: 1, available: true, loading: false, issues: [],
            conversations: [{uid: 1, title: 'SEO', status: 'idle', messageCount: 0, pinned: false}],
            messages: [], status: 'idle', errorMessage: '',
            guided: {progress: {label: 'SEO', current: 1, total: 3, completed: false}, highlight: null},
            highlightAnnounced: '100',
        });
        el.state = 'OPEN';
        el.requestUpdate();
        await el.updateComplete;

        const region = el.shadowRoot.querySelector('.guided-announcement');
        expect(region.getAttribute('role')).toBe('status');
        expect(region.textContent).toBe('guided.highlightAnnounced 100');
    });
});
