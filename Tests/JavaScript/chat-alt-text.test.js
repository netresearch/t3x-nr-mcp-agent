// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * "Optimieren" in the chat panel (ADR-026): the chat opens as the column
 * beside the list, the module menu folds, a conversation starts about the
 * image, or the open one moves to it; the list learns what happens through
 * the panel's event; the card's answers reach the server; closing unfolds the
 * menu and tells the list.
 */

import {describe, test, expect, beforeAll, beforeEach, afterEach, jest} from '@jest/globals';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';
import Persistent from '@typo3/backend/storage/persistent.js';
import {PREFERENCE_FOLDED_MENU, setModuleMenuOpen} from '../../Resources/Public/JavaScript/chat-column.js';

/** The German labels of the chat as the reader gets them (de.locallang_chat.xlf). */
function germanLabels() {
    const xml = readFileSync(join(import.meta.dirname, '../../Resources/Private/Language/de.locallang_chat.xlf'), 'utf8');
    const labels = {};
    for (const unit of xml.matchAll(/<trans-unit id="([^"]+)"[^>]*>[\s\S]*?<target>([\s\S]*?)<\/target>/g)) {
        labels[unit[1]] = unit[2];
    }
    return labels;
}

function el(tag, attributes = {}, children = []) {
    const element = document.createElement(tag);
    for (const [name, value] of Object.entries(attributes)) {
        element.setAttribute(name, value);
    }
    element.append(...children);

    return element;
}

const CARD = {
    role: 'assistant',
    content: '[Image card]',
    notice: 'altTextHit',
    data: {fileName: 'team.jpg', subjectType: '', subject: 'Startseite', partType: 'element', part: 'Text & Images – “Unser Team”', language: 'Deutsch', section: 'Unser Team', reference: 1, imageNote: 'noVision', preview: '/fileadmin/_processed_/team.jpg'},
};

const PROPOSAL = {
    runUuid: 'run-1',
    turnDigest: 'digest-1',
    approveBlocked: '',
    answers: 'altText',
    altText: {kind: 'proposal', proposal: 'Ein Team am Tisch'},
    calls: [{heading: 'Set the alternative text', previewLines: []}],
};

/** The 13.4 shape of the module menu: the scaffold's class and the top bar button that toggles it. */
function moduleMenu() {
    const scaffold = el('div', {class: 't3js-scaffold scaffold-modulemenu-expanded'});
    const button = el('button', {class: 't3js-topbar-button-modulemenu'});
    button.addEventListener('click', () => scaffold.classList.toggle('scaffold-modulemenu-expanded'));
    return {scaffold, button};
}

describe('the module menu, by shape', () => {
    afterEach(() => document.body.replaceChildren());

    test('13.4: folds and unfolds through the top bar button, and says whether it changed', () => {
        const {scaffold, button} = moduleMenu();
        document.body.append(scaffold, button);

        expect(setModuleMenuOpen(document, false)).toBe(true);
        expect(scaffold.classList.contains('scaffold-modulemenu-expanded')).toBe(false);
        expect(setModuleMenuOpen(document, false)).toBe(false);
        expect(setModuleMenuOpen(document, true)).toBe(true);
        expect(scaffold.classList.contains('scaffold-modulemenu-expanded')).toBe(true);
    });

    test('14.3: folds through the sidebar toggle element', () => {
        const scaffold = el('div', {class: 't3js-scaffold scaffold-sidebar-expanded'});
        const toggle = el('typo3-backend-sidebar-toggle');
        toggle.addEventListener('click', () => scaffold.classList.toggle('scaffold-sidebar-expanded'));
        document.body.append(scaffold, toggle);

        expect(setModuleMenuOpen(document, false)).toBe(true);
        expect(scaffold.classList.contains('scaffold-sidebar-expanded')).toBe(false);
    });

    test('a menu that is already folded is left alone, and so is a backend of another shape', () => {
        const {scaffold, button} = moduleMenu();
        scaffold.classList.remove('scaffold-modulemenu-expanded');
        const click = jest.fn();
        button.addEventListener('click', click);
        document.body.append(scaffold, button);

        expect(setModuleMenuOpen(document, false)).toBe(false);
        expect(click).not.toHaveBeenCalled();

        document.body.replaceChildren(el('div', {class: 't3js-scaffold'}));
        expect(setModuleMenuOpen(document, false)).toBe(false);
    });
});

describe('"Optimieren" in the chat panel', () => {
    let panel;
    let area;
    let requests;
    let created;
    let altText;
    let pending;
    let status;
    let messages;
    let events;
    let menu;
    let answers;
    let decorativeGrant;

    beforeAll(async () => {
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
    });

    beforeEach(() => {
        globalThis.TYPO3 = {settings: {ajaxUrls: new Proxy({}, {get: (_, key) => '/ajax/' + String(key)})}};
        requests = [];
        created = 0;
        altText = null;
        pending = null;
        status = 'idle';
        messages = [];
        decorativeGrant = true;
        answers = {
            ai_chat_status: () => [200, {available: true, issues: []}],
            ai_chat_conversations: () => [200, {conversations: Array.from({length: created}, (_, i) => ({uid: 5 + i, title: '', status}))}],
            ai_chat_conversation_create: (url, body) => {
                messages = [{...CARD, data: {...CARD.data, reference: body.altTextReference}}];
                altText = {reference: body.altTextReference, open: true, next: true, decorative: decorativeGrant};
                return [201, {uid: 5 + created++}];
            },
            ai_chat_conversation_messages: () => [200, {messages, status, totalCount: messages.length, altText, pendingApproval: pending}],
            ai_chat_alt_text_switch: (url, body) => {
                altText = {reference: body.reference, open: true, next: true};
                messages = [...messages, {role: 'assistant', notice: 'altTextSwitched', noticeArgs: ['team.jpg', 'Startseite']}, {...CARD, data: {...CARD.data, reference: body.reference}}];
                return [202, {status: 'processing', reference: body.reference, tab: 'pages', page: 1}];
            },
            ai_chat_alt_text_next: () => {
                altText = {reference: 2, open: true, next: false};
                return [202, {status: 'processing', reference: 2, tab: 'tx_news', page: 3}];
            },
            ai_chat_alt_text_skip: () => [200, {status: 'idle'}],
            ai_chat_alt_text_decorative: () => {
                status = 'processing';
                pending = null;
                messages = [...messages, {role: 'user', content: '[request]', notice: 'altTextAskDecorative'}];
                return [202, {status: 'processing'}];
            },
            ai_chat_alt_text_retry: () => {
                status = 'processing';
                messages = [...messages, {role: 'user', content: '[request]', notice: 'altTextRetry'}];
                return [202, {status: 'processing'}];
            },
            ai_chat_conversation_approve: () => [202, {status: 'processing'}],
        };
        globalThis.fetch = jest.fn(async (url, options = {}) => {
            const route = String(url).replace(/^\/ajax\//, '').replace(/\?.*$/, '');
            const body = options.body ? JSON.parse(options.body) : null;
            requests.push({route, body});
            const [code, data] = answers[route]?.(String(url), body) ?? [404, {error: 'unknown'}];
            return {ok: code < 400, status: code, json: async () => data};
        });
        Persistent.reset();
        localStorage.clear();
        menu = moduleMenu();
        area = el('div', {slot: 'content'}, [el('typo3-backend-module-router')]);
        document.body.replaceChildren(menu.scaffold, menu.button, el('div', {class: 'scaffold-content t3js-scaffold-content'}, [
            el('typo3-backend-content-navigation', {identifier: 'backend'}, [el('div', {slot: 'navigation'}), area]),
        ]));
        panel = document.createElement('ai-chat-panel');
        document.body.append(panel);
        panel._areaWidth = 1400;
        events = [];
        panel.addEventListener('nr-mcp-agent:alt-text', (event) => events.push(event.detail));
    });

    afterEach(() => {
        panel.chat.stopPolling();
        panel.remove();
        delete globalThis.fetch;
        delete globalThis.TYPO3;
    });

    const routes = (name) => requests.filter((r) => r.route === name);

    test('opens the column, folds the module menu and starts a conversation about the image alone', async () => {
        const result = await panel.optimiseAltText(1);

        expect(result.ok).toBe(true);
        expect(panel.layout).toBe('column');
        expect(panel.parentElement).toBe(area);
        expect(menu.scaffold.classList.contains('scaffold-modulemenu-expanded')).toBe(false);
        expect(routes('ai_chat_conversation_create')[0].body).toEqual({altTextReference: 1});
        expect(events).toContainEqual({version: 1, type: 'active', reference: 1, tab: undefined, page: undefined});
        expect(panel.activeAltTextReference).toBe(1);
    });

    test('the first message is the image card, and the keyboard focus goes to its heading', async () => {
        await panel.optimiseAltText(1);
        await panel.updateComplete;
        await new Promise((resolve) => setTimeout(resolve, 0));

        const heading = panel.shadowRoot.querySelector('.alt-text-card h3');
        expect(heading.textContent).toBe('altText.heading');
        expect(panel.shadowRoot.activeElement).toBe(heading);
        const lines = [...panel.shadowRoot.querySelectorAll('.alt-text-card dd')].map((dd) => dd.textContent);
        expect(lines).toEqual(['team.jpg', 'Startseite', 'Text & Images – “Unser Team”', 'Deutsch']);
        expect(panel.shadowRoot.querySelector('.alt-text-card img').getAttribute('src')).toBe('/fileadmin/_processed_/team.jpg');
        expect(panel.shadowRoot.querySelector('.alt-text-note').textContent).toBe('altText.note.noVision');
    });

    test('another row moves the open conversation to its image instead of starting another', async () => {
        await panel.optimiseAltText(1);
        events = [];

        await panel.optimiseAltText(10);

        expect(routes('ai_chat_conversation_create')).toHaveLength(1);
        expect(routes('ai_chat_alt_text_switch')[0].body).toEqual({conversationUid: 5, reference: 10});
        expect(events).toContainEqual({version: 1, type: 'active', reference: 10, tab: 'pages', page: 1});
        await panel.updateComplete;
        expect(panel.shadowRoot.querySelector('.alt-text-divider').textContent).toBe('altText.switched');
    });

    test('the header and the tab name the new image after a switch, without reloading the list', async () => {
        answers.ai_chat_conversations = () => [200, {conversations: Array.from({length: created}, (_, i) => ({uid: 5 + i, title: 'Alt-Text: page-templates.png', status}))}];
        const switchTo = answers.ai_chat_alt_text_switch;
        answers.ai_chat_alt_text_switch = (url, body) => {
            const [code, data] = switchTo(url, body);
            return [code, {...data, title: 'Alt-Text: elements.png'}];
        };
        await panel.optimiseAltText(1);
        await panel.updateComplete;
        expect(panel.shadowRoot.querySelector('.panel-header .title').textContent).toBe('Alt-Text: page-templates.png');

        await panel.optimiseAltText(10);
        await panel.updateComplete;

        expect(panel.shadowRoot.querySelector('.panel-header .title').textContent).toBe('Alt-Text: elements.png');
        const tab = panel.shadowRoot.querySelector('.conv-tab.active');
        expect(tab.getAttribute('title')).toContain('Alt-Text: elements.png');
        expect(tab.textContent).toContain('Alt-Text: elements.png');
    });

    test('an image fixed meanwhile is reported to the list, which drops it', async () => {
        answers.ai_chat_conversation_create = () => [409, {error: 'Dieses Bild ist nicht mehr offen.', code: 'done'}];

        const result = await panel.optimiseAltText(1);

        expect(result).toMatchObject({ok: false, code: 'done'});
        expect(events).toEqual([{version: 1, type: 'gone', reference: 1}]);
    });

    test('closing tells the list and unfolds the module menu it folded', async () => {
        await panel.optimiseAltText(1);
        events = [];

        panel.hide();

        expect(events).toEqual([{version: 1, type: 'closed', reference: 1}]);
        expect(menu.scaffold.classList.contains('scaffold-modulemenu-expanded')).toBe(true);
    });

    test('a menu the user had folded already stays folded after closing', async () => {
        menu.scaffold.classList.remove('scaffold-modulemenu-expanded');
        await panel.optimiseAltText(1);

        panel.hide();

        expect(menu.scaffold.classList.contains('scaffold-modulemenu-expanded')).toBe(false);
    });

    test('a fixed image is reported once the state says so', async () => {
        await panel.optimiseAltText(1);
        events = [];
        altText = {reference: 1, open: false, next: true};

        await panel.chat.loadMessages();

        expect(events).toEqual([{version: 1, type: 'done', reference: 1}]);
    });

    test('the proposal offers the four answers, and each reaches its endpoint', async () => {
        pending = PROPOSAL;
        status = 'awaiting_approval';
        await panel.optimiseAltText(1);
        await panel.updateComplete;

        const card = panel.shadowRoot.querySelector('.alt-text-proposal');
        expect(card.querySelector('.alt-text-proposal-text').textContent).toContain('Ein Team am Tisch');
        const buttons = [...card.querySelectorAll('button')];
        expect(buttons.map((b) => b.textContent.trim())).toEqual(['altText.apply', 'altText.variant', 'altText.decorative', 'altText.skip']);
        expect(buttons[2].getAttribute('title')).toBe('altText.decorative.help');

        buttons[1].click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(routes('ai_chat_conversation_approve').at(-1).body).toEqual({conversationUid: 5, approve: false, turnDigest: 'digest-1', reason: 'variant'});
    });

    test('"Als dekorativ markieren" asks for the mark\'s card and saves nothing itself; no warning step comes before it', async () => {
        pending = PROPOSAL;
        status = 'awaiting_approval';
        await panel.optimiseAltText(1);
        await panel.updateComplete;

        panel.shadowRoot.querySelectorAll('.alt-text-proposal button')[2].click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await panel.updateComplete;

        expect(routes('ai_chat_alt_text_decorative')).toEqual([{route: 'ai_chat_alt_text_decorative', body: {conversationUid: 5}}]);
        expect(routes('ai_chat_conversation_approve')).toHaveLength(0);
        expect(panel.chat.status).toBe('processing');
        expect(panel.shadowRoot.querySelector('.alt-text-proposal')).toBeNull();
        // The editor's request stands in the chat as their message.
        expect([...panel.shadowRoot.querySelectorAll('.message.user')].map((m) => m.textContent)).toContain('altText.decorative');
    });

    test('without the grant for the mark the proposal offers no "Als dekorativ markieren"', async () => {
        decorativeGrant = false;
        pending = PROPOSAL;
        status = 'awaiting_approval';
        await panel.optimiseAltText(1);
        await panel.updateComplete;

        const buttons = [...panel.shadowRoot.querySelectorAll('.alt-text-proposal button')];
        expect(buttons.map((b) => b.textContent.trim())).toEqual(['altText.apply', 'altText.variant', 'altText.skip']);
        expect(panel.shadowRoot.querySelector('#alt-text-decorative-help')).toBeNull();
    });

    test('while the approval is blocked, "Übernehmen" is not offered', async () => {
        pending = {...PROPOSAL, approveBlocked: 'secondApprover'};
        status = 'awaiting_approval';
        await panel.optimiseAltText(1);
        await panel.updateComplete;

        const buttons = [...panel.shadowRoot.querySelectorAll('.alt-text-proposal button')];
        expect(buttons.map((b) => b.textContent.trim())).toEqual(['altText.variant', 'altText.decorative', 'altText.skip']);
    });

    test('the mark\'s card shows its lines with the warning, and approving it is the one confirmation', async () => {
        pending = {...PROPOSAL, answers: 'altTextDecorative', altText: {kind: 'decorative', proposal: ''}, calls: [{heading: 'Mark as decorative', previewLines: ['Image: team.jpg', 'This image belongs to the section “Unser Team” and therefore probably needs an alt text. Mark it as decorative anyway?']}]};
        status = 'awaiting_approval';
        await panel.optimiseAltText(1);
        await panel.updateComplete;

        const card = panel.shadowRoot.querySelector('.alt-text-proposal');
        expect(card.textContent).toContain('section “Unser Team”');
        const buttons = [...card.querySelectorAll('button')];
        expect(buttons.map((b) => b.textContent.trim())).toEqual(['altText.decorative', 'altText.createText', 'altText.skip']);

        buttons[0].click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(routes('ai_chat_conversation_approve').at(-1).body).toMatchObject({conversationUid: 5, approve: true});
        expect(routes('ai_chat_alt_text_decorative')).toHaveLength(0);
    });

    test('a proposal whose write did not land can be offered again, while it is the last word', async () => {
        await panel.optimiseAltText(1);
        messages = [...messages, {role: 'assistant', content: 'The page is locked.'}, {role: 'assistant', content: '[x]', notice: 'altTextNotSaved', data: {proposal: 'Ein Team am Tisch'}}];
        status = 'idle';
        await panel.chat.loadMessages();
        await panel.updateComplete;

        const retry = panel.shadowRoot.querySelector('.alt-text-line.failed + .alt-text-answers button');
        expect(retry.textContent.trim()).toBe('altText.retry');
        retry.click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await panel.updateComplete;

        expect(routes('ai_chat_alt_text_retry')).toEqual([{route: 'ai_chat_alt_text_retry', body: {conversationUid: 5}}]);
        expect(panel.chat.status).toBe('processing');
        expect(panel.shadowRoot.querySelector('.alt-text-line.failed + .alt-text-answers')).toBeNull();
    });

    test('a proposal that did not land is no longer offered again once the chat has moved on', async () => {
        await panel.optimiseAltText(1);
        messages = [...messages, {role: 'assistant', content: '[x]', notice: 'altTextNotSaved', data: {proposal: 'Ein Team am Tisch'}}, {role: 'user', content: 'Something else'}];
        status = 'idle';
        await panel.chat.loadMessages();
        await panel.updateComplete;

        expect(panel.shadowRoot.querySelector('.alt-text-line.failed')).not.toBeNull();
        expect(panel.shadowRoot.querySelector('.alt-text-line.failed + .alt-text-answers')).toBeNull();
    });

    test('in German the chat\'s lines read with their values filled in, and the card says when the image was not looked at', async () => {
        globalThis.TYPO3.lang = germanLabels();
        messages = [];
        await panel.optimiseAltText(1);
        messages = [
            {...CARD, data: {...CARD.data, imageNote: 'unreadable'}},
            {role: 'assistant', notice: 'altTextSwitched', noticeArgs: ['logo.png', 'Startseite']},
            {role: 'assistant', content: '[x]', notice: 'altTextNotSaved', data: {proposal: 'Ein Team am Tisch'}},
        ];
        status = 'idle';
        await panel.chat.loadMessages();
        await panel.updateComplete;

        const root = panel.shadowRoot;
        expect(root.querySelector('.alt-text-card .alt-text-note').textContent).toBe(globalThis.TYPO3.lang['altText.note.unreadable']);
        expect(root.querySelector('.alt-text-card .alt-text-note').textContent).toContain('Bild');
        expect(root.querySelector('.alt-text-divider').textContent).toBe('Treffer gewechselt · logo.png · Startseite');
        expect(root.querySelector('.alt-text-line.failed').textContent).toBe('Der Alt-Text „Ein Team am Tisch“ wurde nicht gespeichert.');
        expect(root.querySelector('.alt-text-context-info').textContent).toContain('im Element Text & Images – “Unser Team” auf der Seite Startseite');
        expect(root.textContent).not.toContain('undefined');
        expect(root.textContent).not.toMatch(/%\d*\$?[sd]/);
    });

    test('a row chosen while the proposing turn runs is taken up when the turn ends', async () => {
        await panel.optimiseAltText(1);
        status = 'processing';
        await panel.chat.loadMessages();

        const queued = await panel.optimiseAltText(10);

        expect(queued).toMatchObject({ok: true, code: 'queued'});
        expect(routes('ai_chat_alt_text_switch')).toHaveLength(0);

        status = 'idle';
        await panel.chat.loadMessages();
        for (let i = 0; i < 10 && routes('ai_chat_alt_text_switch').length === 0; i++) {
            await new Promise((resolve) => setTimeout(resolve, 0));
        }

        expect(routes('ai_chat_alt_text_switch')[0].body).toEqual({conversationUid: 5, reference: 10});
    });

    test('a row queued while the turn runs is dropped when the chat is closed', async () => {
        await panel.optimiseAltText(1);
        status = 'processing';
        await panel.chat.loadMessages();
        expect(await panel.optimiseAltText(10)).toMatchObject({ok: true, code: 'queued'});

        panel.hide();
        panel.toggle();
        status = 'idle';
        await panel.chat.loadMessages();
        for (let i = 0; i < 10; i++) {
            await new Promise((resolve) => setTimeout(resolve, 0));
        }

        expect(routes('ai_chat_alt_text_switch')).toHaveLength(0);
    });

    test('a card that cannot be approved says why, in German: the mark the reader may not set, a call on another image', async () => {
        globalThis.TYPO3.lang = germanLabels();
        pending = {...PROPOSAL, answers: 'altTextDecorative', approveBlocked: 'altTextMarkNotPermitted', altText: {kind: 'decorative', proposal: ''}, calls: [{heading: '', previewLines: []}]};
        status = 'awaiting_approval';
        await panel.optimiseAltText(1);
        await panel.updateComplete;

        const card = panel.shadowRoot.querySelector('.alt-text-proposal');
        expect(card.querySelector('.alt-text-note').textContent).toBe('Dieses Bild darfst du nicht als dekorativ markieren. Du kannst dir stattdessen einen Alt-Text vorschlagen lassen.');
        expect([...card.querySelectorAll('button')].map((b) => b.textContent.trim())).toEqual([globalThis.TYPO3.lang['altText.createText'], globalThis.TYPO3.lang['altText.skip']]);

        pending = {runUuid: 'r', turnDigest: 'd', approveBlocked: 'altTextOtherImage', answers: 'plain', altText: null, calls: [{heading: 'Alt-Text', previewLines: ['Bild: logo.png'], toolStillRegistered: true}]};
        await panel.chat.loadMessages();
        await panel.updateComplete;

        expect(panel.shadowRoot.querySelector('.approval-blocked').textContent).toBe('Dieser Vorschlag betrifft ein anderes Bild als das im Chat und kann hier nicht übernommen werden. Ablehnen kannst du ihn.');
    });

    test('the folded module menu is remembered, so a chat closed after a reload unfolds it', async () => {
        await panel.optimiseAltText(1);
        expect(Persistent.get(PREFERENCE_FOLDED_MENU)).toBe('1');

        panel.chat.stopPolling();
        panel.remove();
        panel = document.createElement('ai-chat-panel');
        document.body.append(panel);
        panel.hide();

        expect(menu.scaffold.classList.contains('scaffold-modulemenu-expanded')).toBe(true);
        expect(Persistent.get(PREFERENCE_FOLDED_MENU)).toBe('0');
    });

    test('"Überspringen" saves nothing and reaches the chat\'s own endpoint', async () => {
        pending = PROPOSAL;
        status = 'awaiting_approval';
        await panel.optimiseAltText(1);
        await panel.updateComplete;

        panel.shadowRoot.querySelectorAll('.alt-text-proposal button')[3].click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(routes('ai_chat_alt_text_skip')[0].body).toEqual({conversationUid: 5});
        expect(routes('ai_chat_conversation_approve')).toHaveLength(0);
    });

    test('"Nächsten Treffer optimieren" is offered while another image is open, and tells the list where it is', async () => {
        await panel.optimiseAltText(1);
        await panel.updateComplete;
        events = [];

        const next = panel.shadowRoot.querySelector('.alt-text-next button');
        expect(next.textContent.trim()).toBe('altText.next');
        next.click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await panel.updateComplete;

        expect(routes('ai_chat_alt_text_next')[0].body).toEqual({conversationUid: 5});
        expect(events).toContainEqual({version: 1, type: 'active', reference: 2, tab: 'tx_news', page: 3});
        expect(panel.shadowRoot.querySelector('.alt-text-next')).toBeNull();
    });

    test('the input shows the image it is about and what may change', async () => {
        await panel.optimiseAltText(1);
        await panel.updateComplete;

        const context = panel.shadowRoot.querySelector('.alt-text-context');
        expect([...context.querySelectorAll('.alt-text-chip')].map((chip) => chip.textContent)).toEqual(['Startseite', 'team.jpg · Text & Images – “Unser Team”']);
        expect(context.querySelector('.alt-text-context-info').textContent).toBe('altText.context.infoElement');
    });
});
