// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';

/**
 * "/" in the input lists the skills the user can pick, and the conversation's
 * skill is shown above the input with a way to remove it (ADR-019).
 *
 * Shared by both surfaces. The list follows the ARIA combobox pattern on the
 * existing textarea: the textarea keeps focus, `aria-activedescendant` names
 * the highlighted option, arrow keys move, Enter or Tab picks, Escape closes.
 * Picking a skill never sends a message.
 */
export const chatSlashStyles = css`
    .slash-list {
        list-style: none;
        margin: 0 12px;
        padding: 4px 0;
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid var(--nr-chat-border);
        border-radius: 4px;
        background: var(--nr-chat-surface-high);
    }
    .slash-list li {
        padding: 4px 8px;
        cursor: pointer;
        font-size: 12px;
    }
    .slash-list li[aria-selected="true"] {
        background: var(--nr-chat-active);
        color: var(--nr-chat-on-active);
    }
    .slash-list .slash-description {
        display: block;
        color: inherit;
        opacity: 0.8;
    }
    .slash-list .slash-empty {
        cursor: default;
        color: var(--nr-chat-text-variant);
    }
    .skill-chip {
        display: flex;
        align-items: center;
        gap: 6px;
        margin: 6px 12px 0;
        font-size: 12px;
        color: var(--nr-chat-text-variant);
    }
    .skill-chip strong {
        color: var(--nr-chat-text);
    }
`;

/** The id of the list, for `aria-controls`. */
export const SLASH_LIST_ID = 'nr-chat-slash-list';

/**
 * The ARIA attributes the textarea carries for the list.
 *
 * @returns {{expanded: string, activedescendant: string}}
 */
export function slashAria(chat) {
    const open = chat.slashOpen && chat.slashMatches().length > 0;
    return {
        expanded: String(chat.slashOpen),
        activedescendant: open ? `nr-chat-slash-${chat.slashIndex}` : '',
    };
}

/**
 * Keys while the list is open. Returns true when the key was the list's, so
 * the surface does not also send the message.
 *
 * @param {object} chat
 * @param {KeyboardEvent} e
 */
export function handleSlashKeydown(chat, e) {
    if (!chat.slashOpen) {
        return false;
    }

    const matches = chat.slashMatches();
    switch (e.key) {
        case 'ArrowDown':
        case 'ArrowUp': {
            e.preventDefault();
            if (matches.length > 0) {
                const step = e.key === 'ArrowDown' ? 1 : -1;
                chat.slashIndex = (chat.slashIndex + step + matches.length) % matches.length;
                chat.host.requestUpdate();
            }
            return true;
        }
        case 'Enter':
        case 'Tab': {
            if (e.shiftKey || matches.length === 0) {
                return false;
            }
            e.preventDefault();
            chat.selectSkill(matches[chat.slashIndex] ?? matches[0]);
            return true;
        }
        case 'Escape':
            e.preventDefault();
            chat.closeSlash();
            return true;
        default:
            return false;
    }
}

export function renderSlashList(chat) {
    if (!chat.slashOpen) {
        return nothing;
    }

    const matches = chat.slashMatches();
    if (matches.length === 0) {
        return html`
            <ul class="slash-list" id="${SLASH_LIST_ID}" role="listbox" aria-label="${lll('skills.listLabel')}">
                <li class="slash-empty" role="option" aria-disabled="true" aria-selected="false">
                    ${chat.skills === null ? lll('skills.loading') : lll('skills.none')}
                </li>
            </ul>
        `;
    }

    return html`
        <ul class="slash-list" id="${SLASH_LIST_ID}" role="listbox" aria-label="${lll('skills.listLabel')}">
            ${matches.map((skill, index) => html`
                <li id="nr-chat-slash-${index}" role="option"
                    aria-selected="${String(index === chat.slashIndex)}"
                    @mousedown=${(e) => e.preventDefault()}
                    @click=${() => chat.selectSkill(skill)}>
                    <strong>/${skill.identifier}</strong> ${skill.name}
                    ${skill.description ? html`<span class="slash-description">${skill.description}</span>` : nothing}
                </li>
            `)}
        </ul>
    `;
}

export function renderActiveSkill(chat) {
    if (!chat.skill) {
        return nothing;
    }

    return html`
        <div class="skill-chip">
            ${lll('skills.active')} <strong>${chat.skill.name}</strong>
            <button type="button" class="btn btn-sm btn-icon"
                ?disabled=${chat.isProcessing()}
                @click=${() => chat.clearSkill()}
                aria-label="${lll('skills.remove')}" title="${lll('skills.remove')}">&times;</button>
        </div>
    `;
}
