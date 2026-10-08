// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';

/**
 * The answer to a question a run asks, offered above the input (ADR-018):
 * reply buttons for a choice, a small form for anything else the chat can
 * show, and a note when it can show nothing.
 *
 * Shared by the full-page module and the panel, like chat-activity.js, so the
 * two surfaces cannot drift. The buttons are real buttons in a labelled group:
 * reachable with Tab, pressed with Enter or Space, and disabled while an
 * answer is on its way. Focus is left where it is when the question arrives —
 * the reader may be typing.
 */
export const chatReplyOptionsStyles = css`
    .reply-options {
        display: flex;
        flex-direction: column;
        gap: 6px;
        padding: 8px 12px 0;
    }
    .reply-options-question,
    .reply-options-hint,
    .reply-options-reason {
        margin: 0;
        font-size: 12px;
        color: var(--nr-chat-text-variant);
    }
    .reply-options-question {
        font-weight: 600;
        color: var(--nr-chat-text);
    }
    .reply-options-reason {
        color: var(--nr-chat-status-danger);
    }
    .reply-options-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .reply-form {
        display: grid;
        gap: 6px;
    }
    .reply-form label {
        display: grid;
        gap: 2px;
        font-size: 12px;
    }
    .reply-form label.reply-form-checkbox {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .reply-form-actions {
        display: flex;
        justify-content: flex-end;
    }
`;

/**
 * What the status line says after a decision on the approval card: the
 * approval, or one of the two denials (ADR-018).
 *
 * @param {'approved'|'variant'|'skip'|'denied'} taken
 */
export function decisionLabel(taken) {
    if (taken === 'approved') {
        return lll('chat.approvalGranted');
    }

    return taken === 'variant' ? lll('chat.approvalDeniedVariant') : lll('chat.approvalDeniedSkip');
}

/**
 * The input's placeholder: an invitation to answer in one's own words when
 * typed text answers the question, the usual one otherwise.
 */
export function replyPlaceholder(chat) {
    return chat.answersAsFreeText() ? lll('input.freeTextPlaceholder') : lll('chat.placeholder');
}

/**
 * @param {object} chat the ChatCoreController
 */
export function renderReplyOptions(chat) {
    if (chat.status !== 'awaiting_input') {
        return nothing;
    }

    const pending = chat.pendingInput;
    // A refusal the runtime handed back is the reason the question is open
    // again; it belongs next to the answers, not in the error line under the
    // transcript, which would offer a Retry that steps past the question.
    const reason = chat.errorMessage
        ? html`<p class="reply-options-reason" role="alert">${chat.errorMessage}</p>`
        : nothing;

    return html`
        <div class="reply-options">
            ${reason}
            ${renderAnswers(chat, pending)}
        </div>
    `;
}

function renderAnswers(chat, pending) {
    if (pending?.kind === 'choice') {
        return html`
            ${pending.question ? nothing : html`<p class="reply-options-question">${lll('input.chooseAnswer')}</p>`}
            <div class="reply-options-buttons" role="group" aria-label="${lll('input.groupLabel')}">
                ${pending.options.map((option) => html`
                    <button type="button" class="btn btn-sm reply-option"
                        ?disabled=${chat.inputBusy}
                        @click=${() => chat.submitInput({choice: option.value})}>${option.label}</button>
                `)}
            </div>
            ${pending.freeText ? nothing : html`<p class="reply-options-hint">${lll('input.messageEndsQuestion')}</p>`}
        `;
    }

    if (pending?.kind === 'form') {
        return renderForm(chat, pending);
    }

    return html`
        <p class="reply-options-hint">${lll('input.unsupported')} ${lll('input.messageEndsQuestion')}</p>
        ${chat.approvalUrl ? html`<a class="approval-run-link" href="${chat.approvalUrl}">${lll('chat.approvalOpen')}</a>` : nothing}
    `;
}

function renderForm(chat, pending) {
    const submit = (event) => {
        event.preventDefault();
        chat.submitInput({fields: readForm(event.target, pending.fields)});
    };

    return html`
        ${pending.question ? html`<p class="reply-options-question">${pending.question}</p>` : nothing}
        <form class="reply-form" @submit=${submit}>
            ${pending.fields.map((field) => renderField(field))}
            <div class="reply-form-actions">
                <button type="submit" class="btn btn-sm btn-primary" ?disabled=${chat.inputBusy}>${lll('input.formSubmit')}</button>
            </div>
        </form>
    `;
}

function renderField(field) {
    const id = `reply-field-${field.name}`;
    if (field.type === 'boolean') {
        return html`
            <label class="reply-form-checkbox" for="${id}">
                <input type="checkbox" id="${id}" name="${field.name}">
                ${field.label}
            </label>
        `;
    }

    let control;
    if (field.type === 'select') {
        // Option values may be numbers or booleans; the form only carries
        // strings, so the position travels and is mapped back in readForm().
        control = html`
            <select id="${id}" name="${field.name}" ?required=${field.required}>
                <option value=""></option>
                ${field.options.map((option, index) => html`<option value="${index}">${option.label}</option>`)}
            </select>
        `;
    } else if (field.type === 'integer' || field.type === 'number') {
        control = html`<input type="number" id="${id}" name="${field.name}" ?required=${field.required}
            step="${field.type === 'integer' ? '1' : 'any'}" inputmode="${field.type === 'integer' ? 'numeric' : 'decimal'}">`;
    } else {
        control = html`<input type="text" id="${id}" name="${field.name}" ?required=${field.required}>`;
    }

    return html`
        <label for="${id}">
            <span>${field.label}</span>
            ${control}
            ${field.description ? html`<span class="reply-options-hint">${field.description}</span>` : nothing}
        </label>
    `;
}

/**
 * The values of the form, keyed by the schema's property names. Numbers stay
 * strings — the server converts them to the schema's type and refuses what
 * does not fit; a select answers with the option's own value.
 *
 * @param {HTMLFormElement} form
 * @param {Array<{name: string, type: string, options: Array<{value: *}>}>} fields
 * @returns {Object<string, *>}
 */
export function readForm(form, fields) {
    const values = {};
    for (const field of fields) {
        const control = form.elements.namedItem(field.name);
        if (!control) continue;
        if (field.type === 'boolean') {
            values[field.name] = control.checked;
        } else if (field.type === 'select') {
            if (control.value !== '') {
                values[field.name] = field.options[Number(control.value)]?.value;
            }
        } else if (control.value !== '') {
            values[field.name] = control.value;
        }
    }
    return values;
}
