// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Ending a guided process (ADR-023): the × beside the header's progress ends
 * the tour on the server, which withdraws a waiting proposal; the chat drops
 * the tour's state, keeps the outcomes it showed, removes the page module's
 * mark and puts focus back into the input.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';
import {handleHighlightMessage, highlightContentElement, HIGHLIGHT_MESSAGE} from '../../Resources/Public/JavaScript/page-highlight.js';
import {clearSentHighlight} from '../../Resources/Public/JavaScript/chat-guided.js';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const TOUR = {pageTitle: 'Über uns', languageName: 'Deutsch'};
const OUTCOMES = [{outcome: 'applied', after: 2, tool: 'content_update', table: 'tt_content', uid: 7, fields: ['header'], label: 'Überschrift'}];

async function settle(el) {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await el.updateComplete;
}

async function render(modulePath, tag, open, overrides = {}) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'SEO', status: 'awaiting_approval', messageCount: 2, pinned: false}],
        messages: [],
        status: 'awaiting_approval',
        errorMessage: '',
        skill: {identifier: '3:seo/check', name: 'SEO einer Seite'},
        tour: TOUR,
        guided: {progress: {label: 'SEO', current: 2, total: 5, completed: false}, highlight: {table: 'tt_content', uid: 7}},
        pendingApproval: {turnDigest: 'd', calls: [{name: 'content_update'}]},
        cardOutcomes: OUTCOMES,
        ...overrides,
    });
    el.chat._api.endTour = jest.fn().mockResolvedValue({status: 'idle', skill: null});
    el.chat._api.updateSkill = jest.fn().mockResolvedValue({skill: null});
    if (open) el.state = 'OPEN';
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

function endButton(el) {
    return el.shadowRoot.querySelector('[data-action="end-tour"]');
}

describe.each(SURFACES)('$name ending a tour', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('the × is a named button beside the progress, in the tab order', async () => {
        const el = await render(modulePath, tag, open, {status: 'idle', pendingApproval: null});
        const button = endButton(el);

        expect(button.tagName).toBe('BUTTON');
        expect(button.getAttribute('type')).toBe('button');
        expect(button.getAttribute('aria-label')).toBe('guided.endTour');
        expect(button.hasAttribute('tabindex')).toBe(false);
        expect(button.disabled).toBe(false);
    });

    test('there is no × without a tour', async () => {
        const el = await render(modulePath, tag, open, {tour: null, status: 'idle', pendingApproval: null});

        expect(endButton(el)).toBeNull();
    });

    test('while a turn runs, the tour cannot be ended', async () => {
        const el = await render(modulePath, tag, open, {status: 'processing', pendingApproval: null});

        expect(endButton(el).disabled).toBe(true);
    });

    test('ending it drops the tour, keeps the outcomes and focuses the input', async () => {
        const el = await render(modulePath, tag, open);
        // The element the page module marks for this tour.
        el.chat._sentHighlight = '1:tt_content:7';
        el.chat.highlightAnnounced = '7';
        endButton(el).focus();
        endButton(el).click();
        await settle(el);
        await settle(el);

        expect(el.chat._api.endTour).toHaveBeenCalledWith(1);
        expect(el.chat._api.updateSkill).not.toHaveBeenCalled();
        expect(el.chat.skill).toBeNull();
        expect(el.chat.tour).toBeNull();
        expect(el.chat.pendingApproval).toBeNull();
        expect(el.chat.guided).toEqual({progress: null, highlight: null});
        expect(el.chat.status).toBe('idle');
        expect(el.chat.conversations[0].status).toBe('idle');
        expect(el.chat.cardOutcomes).toEqual(OUTCOMES);
        expect(el.chat._sentHighlight).toBe('');
        expect(el.chat.highlightAnnounced).toBe('');
        expect(endButton(el)).toBeNull();
        expect(el.shadowRoot.querySelector('.skill-chip')).toBeNull();
        expect(el.shadowRoot.activeElement?.tagName).toBe('TEXTAREA');
    });

    test('a refused end keeps the tour and says why', async () => {
        const el = await render(modulePath, tag, open);
        el.chat._api.endTour = jest.fn().mockRejectedValue(new Error('Die Unterhaltung wird gerade verarbeitet.'));
        endButton(el).click();
        await settle(el);

        expect(el.chat.tour).toEqual(TOUR);
        expect(el.chat.skill).not.toBeNull();
        expect(el.chat.pendingApproval).not.toBeNull();
        expect(el.chat.errorMessage).toBe('Die Unterhaltung wird gerade verarbeitet.');
        expect(endButton(el).disabled).toBe(false);
    });

    test('removing the skill during a tour ends the tour', async () => {
        const el = await render(modulePath, tag, open);
        el.shadowRoot.querySelector('.skill-chip button').click();
        await settle(el);

        expect(el.chat._api.endTour).toHaveBeenCalledWith(1);
        expect(el.chat._api.updateSkill).not.toHaveBeenCalled();
        expect(el.chat.pendingApproval).toBeNull();
    });

    test('without a tour, removing the skill only removes it', async () => {
        const el = await render(modulePath, tag, open, {tour: null, status: 'idle', pendingApproval: null});
        el.shadowRoot.querySelector('.skill-chip button').click();
        await settle(el);

        expect(el.chat._api.updateSkill).toHaveBeenCalledWith(1, '');
        expect(el.chat._api.endTour).not.toHaveBeenCalled();
    });
});

describe('the end request', () => {
    test('is sent once, however often the × is pressed', async () => {
        document.body.replaceChildren();
        const el = await render(SURFACES[1].module, SURFACES[1].tag, true);
        let finish;
        el.chat._api.endTour = jest.fn().mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
        el.chat.endTour();
        el.chat.endTour();
        await el.updateComplete;

        expect(el.chat._api.endTour).toHaveBeenCalledTimes(1);
        expect(endButton(el).disabled).toBe(true);
        finish({status: 'idle', skill: null});
        await settle(el);
        expect(el.chat.endingTour).toBe(false);
    });

    test('an answer for a conversation left meanwhile changes nothing', async () => {
        document.body.replaceChildren();
        const el = await render(SURFACES[1].module, SURFACES[1].tag, true);
        let finish;
        el.chat._api.endTour = jest.fn().mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
        const pending = el.chat.endTour();
        el.chat.activeUid = 2;
        el.chat.tour = {pageTitle: 'Team', languageName: ''};
        finish({status: 'idle', skill: null});
        await pending;

        expect(el.chat.tour).toEqual({pageTitle: 'Team', languageName: ''});
    });
});

describe('the page module mark after the tour', () => {
    function pageModule() {
        const doc = document.implementation.createHTMLDocument('page module');
        const element = doc.createElement('div');
        element.id = 'element-tt_content-7';
        doc.body.append(element);
        Object.defineProperty(doc, 'defaultView', {value: {TYPO3: {lang: {'highlight.label': 'Betrifft den aktuellen Vorschlag'}}}});

        return doc;
    }

    test('the clear message removes the mark and the badge', () => {
        const doc = pageModule();
        highlightContentElement(doc, 7);
        const parent = {};

        const applied = handleHighlightMessage({
            origin: 'https://example.org', source: parent, data: {type: HIGHLIGHT_MESSAGE, version: 1, clear: true},
        }, {location: {origin: 'https://example.org'}, parent, document: doc});

        expect(applied).toBe(true);
        expect(doc.querySelector('.nr-mcp-agent-highlight')).toBeNull();
        expect(doc.querySelector('.nr-mcp-agent-highlight-label')).toBeNull();
    });

    test('a clear message from another origin is ignored', () => {
        const doc = pageModule();
        highlightContentElement(doc, 7);
        const parent = {};

        const applied = handleHighlightMessage({
            origin: 'https://evil.example', source: parent, data: {type: HIGHLIGHT_MESSAGE, version: 1, clear: true},
        }, {location: {origin: 'https://example.org'}, parent, document: doc});

        expect(applied).toBe(false);
        expect(doc.querySelector('.nr-mcp-agent-highlight-label')).not.toBeNull();
    });

    test('the chat asks for the clear only when it had marked an element', () => {
        const posted = [];
        const win = {frames: {list_frame: {postMessage: (message, origin) => posted.push([message, origin])}}, location: {origin: 'https://example.org'}};

        expect(clearSentHighlight({_sentHighlight: '', highlightAnnounced: ''}, win)).toBe(false);
        const chat = {_sentHighlight: '1:tt_content:7', highlightAnnounced: '7'};
        expect(clearSentHighlight(chat, win)).toBe(true);

        expect(posted).toEqual([[{type: HIGHLIGHT_MESSAGE, version: 1, clear: true}, 'https://example.org']]);
        expect(chat._sentHighlight).toBe('');
        expect(chat.highlightAnnounced).toBe('');
    });
});
