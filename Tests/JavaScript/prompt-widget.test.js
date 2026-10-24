// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The "AI Assistant" prompt widget on the dashboard (prompt-widget.js): Enter
 * sends, Shift+Enter is a new line, an empty question cannot be sent, and the
 * question reaches the chat panel exactly as typed.
 */

import {describe, test, expect, jest, beforeAll, beforeEach, afterEach} from '@jest/globals';
import {PANEL_PROPERTY} from '../../Resources/Public/JavaScript/chat-panel-registry.js';
import {readFileSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const QUESTION = '  Wie heißt die Seite <b>Über uns</b>?\nUnd **Absatz 2**?  ';

function el(tag, attributes = {}) {
    const element = document.createElement(tag);
    for (const [name, value] of Object.entries(attributes)) {
        element.setAttribute(name, value);
    }

    return element;
}

/** The widget's form as PromptWidget.html renders it. */
function widget() {
    const form = el('form', {action: '/typo3/module/nr/mcp/agent/chat', method: 'get', 'data-nr-mcp-agent-prompt': '1'});
    const label = el('label', {for: 'q'});
    label.textContent = 'What would you like to do?';
    const field = el('textarea', {id: 'q'});
    const button = el('button', {type: 'submit', 'aria-label': 'Send', disabled: ''});
    const error = el('output', {'data-nr-mcp-agent-prompt-error': '1'});
    form.append(el('input', {type: 'hidden', name: 'token', value: 't'}), label, field, button, error);
    document.body.append(form);

    return {form, field, button, error};
}

function type(field, value) {
    field.value = value;
    field.dispatchEvent(new Event('input', {bubbles: true}));
}

function press(field, init) {
    const event = new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true, ...init});
    field.dispatchEvent(event);
    return event;
}

/** Let the promise chain of a submit run to its end. */
async function settle() {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
}

describe('prompt widget', () => {
    let panel;
    let submitted;
    const record = (event) => submitted.push(event);

    beforeAll(async () => {
        await import('../../Resources/Public/JavaScript/prompt-widget.js');
    });

    beforeEach(() => {
        document.body.replaceChildren();
        panel = null;
        // The script looks the panel up by its tag name in the top document.
        jest.spyOn(document, 'querySelector').mockImplementation((sel) => (sel === 'ai-chat-panel' ? panel : null));
        // jsdom does not navigate; every submit is recorded, with whether the
        // script prevented it. Registered after the script's own listener.
        submitted = [];
        document.addEventListener('submit', record);
    });

    afterEach(() => {
        document.removeEventListener('submit', record);
        document.querySelector.mockRestore();
        delete window[PANEL_PROPERTY];
    });

    function fakePanel(sent = true, error = '', invalid = false) {
        panel = {startConversation: jest.fn(async () => (invalid ? {sent, error, invalid} : {sent, error}))};
        return panel;
    }

    test('the send button is disabled while the field is empty or blank', () => {
        const {field, button} = widget();
        expect(button.disabled).toBe(true);

        type(field, 'Hallo');
        expect(button.disabled).toBe(false);

        type(field, '   \n ');
        expect(button.disabled).toBe(true);

        type(field, '');
        expect(button.disabled).toBe(true);
    });

    test('Enter hands the question to the panel exactly as typed', async () => {
        const {field} = widget();
        fakePanel();
        type(field, QUESTION);

        const event = press(field);
        await settle();

        expect(event.defaultPrevented).toBe(true);
        expect(panel.startConversation).toHaveBeenCalledWith(QUESTION);
        expect(submitted).toHaveLength(1);
        expect(submitted[0].defaultPrevented).toBe(true);
    });

    test('Shift+Enter is a new line, not a send', () => {
        const {field} = widget();
        fakePanel();
        type(field, 'Erste Zeile');

        const event = press(field, {shiftKey: true});

        expect(event.defaultPrevented).toBe(false);
        expect(submitted).toHaveLength(0);
        expect(panel.startConversation).not.toHaveBeenCalled();
    });

    test('Enter that confirms an input method composition does not send', () => {
        const {field} = widget();
        fakePanel();
        type(field, 'かな');

        press(field, {isComposing: true});

        expect(submitted).toHaveLength(0);
        expect(panel.startConversation).not.toHaveBeenCalled();
    });

    test('Safari\'s composition Enter (keyCode 229, isComposing false) does not send', () => {
        const {field} = widget();
        fakePanel();
        type(field, 'かな');
        const event = new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true});
        Object.defineProperty(event, 'keyCode', {value: 229});

        field.dispatchEvent(event);

        expect(submitted).toHaveLength(0);
        expect(panel.startConversation).not.toHaveBeenCalled();
    });

    test('the send button sends', async () => {
        const {field, button} = widget();
        fakePanel();
        type(field, 'Was kann ich hier tun?');

        button.click();
        await settle();

        expect(panel.startConversation).toHaveBeenCalledWith('Was kann ich hier tun?');
    });

    test('an empty question is not sent, also by Enter', async () => {
        const {field} = widget();
        fakePanel();
        type(field, '  ');

        press(field);
        await settle();

        expect(panel.startConversation).not.toHaveBeenCalled();
        expect(submitted).toHaveLength(1);
        expect(submitted[0].defaultPrevented).toBe(true);
    });

    test('the field is emptied once the question is in the chat', async () => {
        const {field, button} = widget();
        fakePanel(true);
        type(field, 'Frage');

        press(field);
        await settle();

        expect(field.value).toBe('');
        expect(button.disabled).toBe(true);
    });

    test('the question stays when it did not reach the chat, and the widget says why', async () => {
        const {field, button, error} = widget();
        fakePanel(false, 'Der Chat sendet noch eine Nachricht.');
        type(field, 'Frage');

        press(field);
        await settle();

        expect(field.value).toBe('Frage');
        expect(button.disabled).toBe(false);
        expect(error.textContent).toBe('Der Chat sendet noch eine Nachricht.');

        // Typing again clears the reason.
        type(field, 'Frage?');
        expect(error.textContent).toBe('');
    });

    /**
     * Only a question the chat refuses for what it is (too long) is invalid;
     * one refused for the moment (still sending) or the chat's state is not.
     */
    test('the field is marked invalid only for a question refused for its content', async () => {
        const {field} = widget();
        fakePanel(false, 'Nachricht überschreitet das Limit von 10 Zeichen', true);
        type(field, 'Zu lange Frage');
        press(field);
        await settle();
        expect(field.getAttribute('aria-invalid')).toBe('true');

        // Correcting the question clears the mark with the reason.
        type(field, 'Kurz');
        expect(field.hasAttribute('aria-invalid')).toBe(false);

        fakePanel(false, 'Der Chat sendet noch eine Nachricht.');
        press(field);
        await settle();
        expect(field.hasAttribute('aria-invalid')).toBe(false);
    });

    test('a refusal for the moment clears an earlier invalid mark', async () => {
        const {field} = widget();
        fakePanel(false, 'Zu lang', true);
        type(field, 'Frage');
        press(field);
        await settle();
        expect(field.getAttribute('aria-invalid')).toBe('true');

        fakePanel(false, 'Der Chat sendet noch eine Nachricht.');
        press(field);
        await settle();

        expect(field.hasAttribute('aria-invalid')).toBe(false);
    });

    test('a reason from an earlier try goes once the question is sent', async () => {
        const {field, error} = widget();
        fakePanel(false, 'Zu lang');
        type(field, 'Frage');
        press(field);
        await settle();
        expect(error.textContent).toBe('Zu lang');

        fakePanel(true);
        press(field);
        await settle();

        expect(error.textContent).toBe('');
        expect(field.value).toBe('');
    });

    /**
     * Popped out into its own window, the panel is not in the backend's
     * document; it registered itself on the backend window.
     */
    test('finds a panel that is not in the backend\'s document', async () => {
        const {field} = widget();
        const popped = {isConnected: true, startConversation: jest.fn(async () => ({sent: true, error: ''}))};
        window[PANEL_PROPERTY] = popped;
        type(field, 'Frage');

        press(field);
        await settle();

        expect(popped.startConversation).toHaveBeenCalledWith('Frage');
        expect(submitted[0].defaultPrevented).toBe(true);
    });

    test('no second send while the first is on its way', async () => {
        const {form, field, button} = widget();
        let finish;
        panel = {startConversation: jest.fn(() => new Promise((resolve) => { finish = resolve; }))};
        type(field, 'Frage');

        press(field);
        await settle();
        expect(form.getAttribute('aria-busy')).toBe('true');
        expect(button.getAttribute('aria-disabled')).toBe('true');
        press(field);
        button.click();
        await settle();

        expect(panel.startConversation).toHaveBeenCalledTimes(1);
        expect(submitted.every((event) => event.defaultPrevented)).toBe(true);
        finish({sent: true, error: ''});
        await settle();
        expect(form.hasAttribute('aria-busy')).toBe(false);
        expect(button.hasAttribute('aria-disabled')).toBe(false);
    });

    /**
     * A disabled button drops the keyboard focus to the page: the pressed
     * button stays enabled while the question is on its way, and a refused
     * question gets the focus back so it can be corrected.
     */
    test('the focus stays on the pressed button and returns to the question on a refusal', async () => {
        const {field, button} = widget();
        let finish;
        panel = {startConversation: jest.fn(() => new Promise((resolve) => { finish = resolve; }))};
        type(field, 'Frage');
        button.focus();

        button.click();
        await settle();
        expect(button.disabled).toBe(false);
        expect(document.activeElement).toBe(button);

        finish({sent: false, error: 'Zu lang'});
        await settle();
        expect(document.activeElement).toBe(field);
    });

    test('a refusal leaves focus that is elsewhere where it is', async () => {
        const {field} = widget();
        const outside = el('input');
        document.body.append(outside);
        let finish;
        panel = {startConversation: jest.fn(() => new Promise((resolve) => { finish = resolve; }))};
        type(field, 'Frage');

        press(field);
        await settle();
        outside.focus();
        finish({sent: false, error: 'Zu lang'});
        await settle();

        expect(document.activeElement).toBe(outside);
    });

    /**
     * The design's ring is a box-shadow, which Windows' forced-colors mode
     * does not draw; the outline it draws instead must not be switched off.
     */
    test('the focus stays visible in forced-colors mode', () => {
        const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '../../Resources/Public/Css/prompt-widget.css'), 'utf8')
            .replace(/\/\*[\s\S]*?\*\//g, '');

        expect(css).not.toMatch(/outline\s*:\s*(none|0)\b/);
        const focus = css.match(/\.nr-mcp-agent-prompt-input:focus,[^{]*\{([^}]*)\}/)?.[1] ?? '';
        expect(focus).toMatch(/outline\s*:\s*2px solid transparent/);
        const forced = css.slice(css.indexOf('@media (forced-colors: active)'));
        expect(forced).toMatch(/\.nr-mcp-agent-prompt-input:focus[^{]*\{\s*outline-color\s*:\s*Highlight/);
    });

    test('without the panel the form opens the chat module, without the question', () => {
        const {form, field} = widget();
        type(field, 'Frage');

        press(field);

        expect(submitted).toHaveLength(1);
        expect(submitted[0].defaultPrevented).toBe(false);
        expect([...new FormData(form).keys()]).toEqual(['token']);
    });

    test('other forms and fields are left alone', () => {
        fakePanel();
        const form = el('form');
        const field = el('textarea');
        form.append(field);
        document.body.append(form);
        field.value = 'x';

        const event = press(field);

        expect(event.defaultPrevented).toBe(false);
        expect(submitted).toHaveLength(0);
        expect(panel.startConversation).not.toHaveBeenCalled();
    });
});
