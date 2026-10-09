// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * "/" in the input picks the conversation's skill, and a module link can
 * start a conversation about a page with a skill (ADR-019). Asserted on both
 * surfaces, through the keyboard, down to the API calls.
 */

import {describe, test, expect, beforeAll, jest} from '@jest/globals';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const SKILLS = [
    {identifier: 'seo-page-tour', name: 'SEO einer Seite', description: 'Geführt durch die SEO-Punkte'},
    {identifier: 'content-tour', name: 'Inhalt verbessern', description: ''},
];

async function render(modulePath, tag, open, overrides = {}) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'Neu', status: 'idle', messageCount: 0, pinned: false}],
        messages: [],
        status: 'idle',
        errorMessage: '',
        ...overrides,
    });
    el.chat._api.listSkills = jest.fn().mockResolvedValue({available: true, skills: SKILLS});
    el.chat._api.updateSkill = jest.fn().mockImplementation(async (uid, skill) => ({
        skill: skill ? {identifier: skill, name: SKILLS.find((s) => s.identifier === skill)?.name ?? skill} : null,
    }));
    el.chat._api.sendMessage = jest.fn().mockResolvedValue({status: 'processing'});
    if (open) el.state = 'OPEN';
    el.requestUpdate();
    await el.updateComplete;
    return el;
}

async function type(el, value) {
    const textarea = el.shadowRoot.querySelector('textarea');
    textarea.value = value;
    textarea.dispatchEvent(new Event('input'));
    await new Promise((resolve) => setTimeout(resolve, 0));
    await el.updateComplete;
    return textarea;
}

async function key(el, name) {
    const textarea = el.shadowRoot.querySelector('textarea');
    const event = new KeyboardEvent('keydown', {key: name, cancelable: true, bubbles: true});
    textarea.dispatchEvent(event);
    await new Promise((resolve) => setTimeout(resolve, 0));
    await el.updateComplete;
    return event;
}

describe.each(SURFACES)('$name slash commands', ({module: modulePath, tag, open}) => {
    beforeAll(() => {
        document.body.replaceChildren();
    });

    test('"/" lists the skills, and the textarea says so to assistive technology', async () => {
        const el = await render(modulePath, tag, open);
        const textarea = await type(el, '/');

        const options = [...el.shadowRoot.querySelectorAll('.slash-list [role="option"]')];
        expect(options.map((o) => o.textContent.replace(/\s+/g, ' ').trim()))
            .toEqual(['/seo-page-tour SEO einer Seite Geführt durch die SEO-Punkte', '/content-tour Inhalt verbessern']);
        expect(textarea.getAttribute('role')).toBe('combobox');
        expect(textarea.getAttribute('aria-expanded')).toBe('true');
        expect(textarea.getAttribute('aria-controls')).toBe(el.shadowRoot.querySelector('.slash-list').id);
        expect(textarea.getAttribute('aria-activedescendant')).toBe(options[0].id);
        expect(el.chat._api.listSkills).toHaveBeenCalledTimes(1);
    });

    test('what follows the "/" filters the list', async () => {
        const el = await render(modulePath, tag, open);
        await type(el, '/inhalt');

        expect([...el.shadowRoot.querySelectorAll('.slash-list [role="option"]')]).toHaveLength(1);
    });

    test('arrow keys move, Enter picks the skill and sends no message', async () => {
        const el = await render(modulePath, tag, open);
        await type(el, '/');
        await key(el, 'ArrowDown');
        expect(el.shadowRoot.querySelector('textarea').getAttribute('aria-activedescendant')).toBe('nr-chat-slash-1');

        const enter = await key(el, 'Enter');

        expect(enter.defaultPrevented).toBe(true);
        expect(el.chat._api.updateSkill).toHaveBeenCalledWith(1, 'content-tour');
        expect(el.chat._api.sendMessage).not.toHaveBeenCalled();
        expect(el.chat.inputValue).toBe('');
        expect(el.shadowRoot.querySelector('.slash-list')).toBeNull();
        expect(el.shadowRoot.querySelector('.skill-chip').textContent).toContain('Inhalt verbessern');
    });

    test('Escape closes the list and keeps the text', async () => {
        const el = await render(modulePath, tag, open);
        await type(el, '/seo');
        await key(el, 'Escape');

        expect(el.shadowRoot.querySelector('.slash-list')).toBeNull();
        expect(el.chat.inputValue).toBe('/seo');
        expect(el.shadowRoot.querySelector('textarea').getAttribute('aria-expanded')).toBe('false');
    });

    test('a "/" with a space is an ordinary message', async () => {
        const el = await render(modulePath, tag, open);
        await type(el, '/ ist kein Befehl');

        expect(el.shadowRoot.querySelector('.slash-list')).toBeNull();
    });

    test('without skills the list says so', async () => {
        const el = await render(modulePath, tag, open);
        el.chat._api.listSkills = jest.fn().mockResolvedValue({available: false, skills: []});
        await type(el, '/');

        expect(el.shadowRoot.querySelector('.slash-list').textContent.trim()).toBe('skills.none');
        const enter = await key(el, 'Enter');
        expect(el.chat._api.updateSkill).not.toHaveBeenCalled();
        expect(enter.defaultPrevented).toBe(true);
    });

    test('the conversation\'s skill is shown above the input and can be removed', async () => {
        const el = await render(modulePath, tag, open, {skill: {identifier: 'seo-page-tour', name: 'SEO einer Seite'}});
        const chip = el.shadowRoot.querySelector('.skill-chip');

        expect(chip.textContent).toContain('SEO einer Seite');
        const remove = chip.querySelector('button');
        expect(remove.getAttribute('aria-label')).toBe('skills.remove');
        remove.click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await el.updateComplete;

        expect(el.chat._api.updateSkill).toHaveBeenCalledWith(1, '');
        expect(el.shadowRoot.querySelector('.skill-chip')).toBeNull();
    });
});

describe('a module link that starts a conversation', () => {
    test('creates it with the page, language and skill, then points the URL at it', async () => {
        document.body.replaceChildren();
        await import('../../Resources/Public/JavaScript/chat-app.js');
        const {ApiClient} = await import('../../Resources/Public/JavaScript/api-client.js');
        globalThis.history.replaceState(null, '', '/typo3/module/ai-chat?pageUid=10&languageUid=1&skill=seo-page-tour');
        // The real init() runs when the element connects, through the real
        // client; only the transport answers are canned.
        const create = jest.spyOn(ApiClient.prototype, 'createConversation').mockResolvedValue({uid: 42});
        jest.spyOn(ApiClient.prototype, 'getStatus').mockResolvedValue({available: true, issues: []});
        jest.spyOn(ApiClient.prototype, 'listConversations').mockResolvedValue({conversations: [{uid: 42, title: '', status: 'idle'}]});
        jest.spyOn(ApiClient.prototype, 'getMessages').mockResolvedValue({status: 'idle', messages: [], totalCount: 0});
        const el = document.createElement('nr-chat-app');

        expect(el.initialStartContext()).toEqual({pageUid: 10, languageUid: 1, skill: 'seo-page-tour'});

        document.body.append(el);
        for (let i = 0; i < 20 && el.chat.activeUid !== 42; i++) {
            await new Promise((resolve) => setTimeout(resolve, 0));
        }
        jest.restoreAllMocks();

        expect(create).toHaveBeenCalledWith({pageUid: 10, languageUid: 1, skill: 'seo-page-tour'});
        expect(el.chat.activeUid).toBe(42);
        expect(globalThis.location.search).toBe('?conversation=42');
    });

    test('a link that names a conversation starts none', async () => {
        await import('../../Resources/Public/JavaScript/chat-app.js');
        globalThis.history.replaceState(null, '', '/typo3/module/ai-chat?conversation=5&pageUid=10');

        expect(document.createElement('nr-chat-app').initialStartContext()).toBeNull();
    });

    test('a link without page or skill starts none', async () => {
        await import('../../Resources/Public/JavaScript/chat-app.js');
        globalThis.history.replaceState(null, '', '/typo3/module/ai-chat?pageUid=abc');

        expect(document.createElement('nr-chat-app').initialStartContext()).toBeNull();
    });
});

describe('the backend context', () => {
    test('carries the page language when the module URL names it', async () => {
        const {currentBackendContext} = await import('../../Resources/Public/JavaScript/chat-core.js');
        const win = (search) => ({document: {querySelector: () => ({module: 'web_layout'})}, frames: {list_frame: {location: {search}}}});

        expect(currentBackendContext(win('?id=10&language=1'))).toEqual({pageId: 10, module: 'web_layout', languageId: 1});
        expect(currentBackendContext(win('?id=10&languages%5B0%5D=2'))).toEqual({pageId: 10, module: 'web_layout', languageId: 2});
        expect(currentBackendContext(win('?id=10'))).toEqual({pageId: 10, module: 'web_layout'});
    });
});
