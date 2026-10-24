// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The "AI Assistant" prompt widget on the dashboard: a free question that
 * starts a conversation in the chat panel.
 *
 * The dashboard runs inside the module frame; the panel belongs to the backend
 * frame around it (ADR-011) and is found wherever the user put it, its own
 * window included (chat-panel-registry.js). A submitted question opens the
 * panel on a new conversation, with the question as its first message,
 * exactly as typed (ai-chat-panel.js, startConversation()). Where the panel is
 * not available, a frame that is not the backend's, the form is submitted as
 * it stands and opens the chat module; the question does not travel with it,
 * a link never sends a message on the user's behalf. Where the chat refuses
 * the question, the question stays and the widget says why.
 *
 * The listeners sit on the document: the dashboard loads this module for every
 * widget that may be placed, and renders a widget's content after it loaded.
 * Without a widget on the dashboard nothing here does anything.
 */

import {findPanel} from '@netresearch/nr-mcp-agent/chat-panel-registry.js';

const FORM = 'form[data-nr-mcp-agent-prompt]';

function formOf(target) {
    return target instanceof Element ? target.closest(FORM) : null;
}

function fieldOf(form) {
    return form.querySelector('textarea');
}

/**
 * Say why the question was not sent, or clear the reason. The field is
 * marked invalid only when the question itself was refused (too long); a
 * question refused because another is still being sent, or because the chat
 * is not available, is not wrong.
 *
 * @param {HTMLFormElement} form
 * @param {string} message
 * @param {boolean} [invalid]
 */
function showError(form, message, invalid = false) {
    const error = form.querySelector('[data-nr-mcp-agent-prompt-error]');
    if (error) {
        // A live region that stays in place: one that appears with its text
        // is not reliably announced.
        error.textContent = message;
    }
    const field = fieldOf(form);
    if (invalid && message !== '') {
        field?.setAttribute('aria-invalid', 'true');
    } else {
        field?.removeAttribute('aria-invalid');
    }
}

/**
 * The send button can be pressed only while there is a question to send and
 * none is on its way. Blank input counts as empty, as it does in the chat.
 * While a question is on its way the button is only marked unavailable
 * (aria-disabled; the submit handler refuses it): a disabled button loses
 * the keyboard focus it has after being pressed.
 *
 * @param {HTMLFormElement} form
 */
export function syncSendButton(form) {
    const button = form.querySelector('button[type="submit"]');
    if (!button) {
        return;
    }
    button.disabled = (fieldOf(form)?.value ?? '').trim() === '';
    if (form.hasAttribute('aria-busy')) {
        button.setAttribute('aria-disabled', 'true');
    } else {
        button.removeAttribute('aria-disabled');
    }
}

document.addEventListener('input', (event) => {
    const form = formOf(event.target);
    if (form) {
        syncSendButton(form);
        showError(form, '');
    }
});

// Enter sends, Shift+Enter is a new line. A key that confirms an input
// method's composition is not a send: `isComposing` says so, except in
// Safari, which reports that key with isComposing false and keyCode 229.
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) {
        return;
    }
    const form = formOf(event.target);
    if (!form || event.target !== fieldOf(form)) {
        return;
    }
    event.preventDefault();
    form.requestSubmit();
});

document.addEventListener('submit', (event) => {
    const form = formOf(event.target);
    if (!form) {
        return;
    }
    const field = fieldOf(form);
    const text = field?.value ?? '';
    if (text.trim() === '' || form.hasAttribute('aria-busy')) {
        event.preventDefault();
        return;
    }

    const panel = findPanel();
    if (typeof panel?.startConversation !== 'function') {
        return; // the chat module opens
    }

    event.preventDefault();
    form.setAttribute('aria-busy', 'true');
    syncSendButton(form);
    showError(form, '');
    Promise.resolve()
        .then(() => panel.startConversation(text))
        .then((result) => {
            if (result?.sent) {
                if (field) {
                    field.value = '';
                }
                return;
            }
            // Kept when it did not reach the chat, with the reason, and the
            // keyboard focus goes back to the question when it was in the
            // widget (the chat, where it opened, keeps its own).
            showError(form, result?.error ?? '', result?.invalid === true);
            if (field && document.hasFocus() && form.contains(document.activeElement)) {
                field.focus();
            }
        }, () => {})
        .finally(() => {
            form.removeAttribute('aria-busy');
            syncSendButton(form);
        });
});
