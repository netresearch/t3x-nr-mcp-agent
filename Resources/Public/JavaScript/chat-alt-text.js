// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';
import {format} from '@netresearch/nr-mcp-agent/text-format.js';

/**
 * A conversation about one missing alt text (ADR-026), as both chat surfaces
 * render it: the image card the chat starts with, the divider when the hit
 * changes, the chat's own confirmations, the proposal with its answers, the
 * offer of the next hit, and the image the input is about.
 *
 * Shared by the full-page module and the panel, like chat-reply-options.js,
 * so the two cannot drift. The answers are real buttons in a labelled group;
 * the chat's lines are stored as codes and rendered here in the reader's
 * language.
 */

/** The notices the chat writes into such a conversation (AltTextConversation). */
export const NOTICE = Object.freeze({
    HIT: 'altTextHit',
    SWITCHED: 'altTextSwitched',
    SAVED: 'altTextSaved',
    DECORATIVE: 'altTextDecorative',
    SKIPPED: 'altTextSkipped',
    ACTION: 'altTextAction',
    ASK_DECORATIVE: 'altTextAskDecorative',
    NOT_SAVED: 'altTextNotSaved',
    RETRY: 'altTextRetry',
});

const ACTION_LABELS = new Map([
    ['skip', 'altText.skip'],
    ['next', 'altText.next'],
]);

function userLine(text) {
    return html`<div class="message-row user"><div class="message-bubble"><div class="message user">${text}</div></div></div>`;
}

const IMAGE_NOTES = new Map([
    ['noVision', 'altText.note.noVision'],
    ['unreadable', 'altText.note.unreadable'],
]);

export const chatAltTextStyles = css`
    .alt-text-card {
        border: 1px solid var(--nr-chat-border, #d0d0d0);
        border-radius: 6px;
        padding: 10px 12px;
        background: var(--nr-chat-surface, #fff);
        display: grid;
        gap: 6px;
    }
    .alt-text-card h3 {
        margin: 0;
        font-size: 14px;
    }
    .alt-text-card h3:focus-visible {
        outline: 2px solid var(--nr-chat-focus, #0078e6);
        outline-offset: 2px;
    }
    .alt-text-card img {
        max-width: 100%;
        max-height: 180px;
        object-fit: contain;
        align-self: start;
        border-radius: 4px;
        background: var(--nr-chat-surface-variant, #f4f4f4);
    }
    .alt-text-card dl {
        margin: 0;
        display: grid;
        grid-template-columns: max-content 1fr;
        gap: 2px 8px;
        font-size: 12px;
    }
    .alt-text-card dt {
        font-weight: 600;
    }
    .alt-text-card dd {
        margin: 0;
        overflow-wrap: anywhere;
    }
    .alt-text-card p {
        margin: 0;
        font-size: 12px;
    }
    .alt-text-note {
        color: var(--nr-chat-text-variant);
    }
    .alt-text-divider {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 8px 0;
        font-size: 12px;
        color: var(--nr-chat-text-variant);
    }
    .alt-text-divider::before,
    .alt-text-divider::after {
        content: '';
        flex: 1;
        border-top: 1px solid var(--nr-chat-border, #d0d0d0);
    }
    .alt-text-line {
        margin: 4px 0;
        font-size: 13px;
    }
    .alt-text-line.saved {
        color: var(--nr-chat-status-success, #2e7d32);
    }
    .alt-text-line.failed {
        color: var(--nr-chat-status-danger, #c62828);
    }
    .alt-text-proposal {
        display: grid;
        gap: 8px;
    }
    .alt-text-proposal-text {
        margin: 0;
        font-size: 13px;
    }
    .alt-text-answers {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .alt-text-next {
        padding: 8px 12px 0;
    }
    .alt-text-context {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px 6px;
        padding: 6px 12px 0;
        font-size: 12px;
    }
    .alt-text-chip {
        border: 1px solid var(--nr-chat-border, #d0d0d0);
        border-radius: 12px;
        padding: 1px 8px;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .alt-text-context-info {
        margin: 0;
        flex-basis: 100%;
        color: var(--nr-chat-text-variant);
    }
`;

/**
 * The label that says why a card offers no approve button (ADR-021,
 * ADR-026): a second person is needed, the preview could not be built with
 * the reader's rights, the reader may not set the decorative mark, or the
 * call is about another image than the conversation's.
 */
export function approveBlockedLabel(reason) {
    switch (reason) {
        case 'secondApprover':
            return 'chat.approvalSecondApprover';
        case 'altTextMarkNotPermitted':
            return 'altText.markNotPermitted';
        case 'altTextOtherImage':
            return 'altText.otherImage';
        default:
            return 'chat.approvalOwnNeedsPreview';
    }
}

/** Whether the card is one of a conversation about a missing alt text. */
export function isAltTextCard(pending) {
    return pending?.answers === 'altText' || pending?.answers === 'altTextDecorative';
}

/**
 * A message the chat wrote into a conversation about a missing alt text, or
 * null for any other message.
 *
 * @param {object} chat the ChatCoreController
 * @param {{role?: string, notice?: string, noticeArgs?: string[], data?: object}} msg
 * @param {number} idx the message's place in the transcript, for its ids
 */
export function renderAltTextMessage(chat, msg, idx) {
    const args = Array.isArray(msg.noticeArgs) ? msg.noticeArgs : [];
    switch (msg.notice) {
        case NOTICE.HIT:
            return renderCard(msg.data ?? {}, idx, chat.altText?.reference === msg.data?.reference);
        case NOTICE.SWITCHED:
            return html`<p class="alt-text-divider" role="separator" aria-label="${format(lll('altText.switched'), args[0] ?? '', args[1] ?? '')}">${format(lll('altText.switched'), args[0] ?? '', args[1] ?? '')}</p>`;
        case NOTICE.ACTION:
            return userLine(lll(ACTION_LABELS.get(args[0]) ?? 'altText.skip'));
        case NOTICE.ASK_DECORATIVE:
            return userLine(lll('altText.decorative'));
        case NOTICE.RETRY:
            return userLine(lll('altText.retry'));
        case NOTICE.NOT_SAVED:
            return renderNotSaved(chat, msg, idx);
        case NOTICE.SAVED:
            return html`<p class="alt-text-line saved">${lll('altText.saved')}</p>`;
        case NOTICE.DECORATIVE:
            return html`<p class="alt-text-line saved">${lll('altText.decorativeSaved')}</p>`;
        case NOTICE.SKIPPED:
            return html`<p class="alt-text-line">${lll('altText.skipped')}</p>`;
        default:
            return null;
    }
}

/**
 * A proposal whose approved write did not land (the model's answer before it
 * says why): the text stays, and while it is the last word and the chat
 * waits, it can be offered again, as a new card.
 */
function renderNotSaved(chat, msg, idx) {
    const text = format(lll('altText.notSaved'), msg.data?.proposal ?? '');
    const last = idx === (chat.messages?.length ?? 0) - 1;
    if (!last || chat.isProcessing?.()) {
        return html`<p class="alt-text-line failed">${text}</p>`;
    }

    return html`
        <div class="alt-text-proposal">
            <p class="alt-text-line failed">${text}</p>
            <div class="alt-text-answers" role="group" aria-label="${lll('altText.answers')}">
                <button type="button" class="btn btn-sm btn-answer btn-primary" ?disabled=${chat.altTextBusy}
                    @click=${() => chat.retryAltText()}>${lll('altText.retry')}</button>
            </div>
        </div>
    `;
}

/**
 * The reader-language text of a line the chat wrote, for the export; null
 * for any other message.
 */
export function altTextNoticeText(msg) {
    const args = Array.isArray(msg.noticeArgs) ? msg.noticeArgs : [];
    const data = msg.data ?? {};
    switch (msg.notice) {
        case NOTICE.HIT:
            return [lll('altText.heading'), ...cardLines(data).map(([label, value]) => `${label}: ${value}`), lll('altText.missing')].join('\n');
        case NOTICE.SWITCHED:
            return format(lll('altText.switched'), args[0] ?? '', args[1] ?? '');
        case NOTICE.ACTION:
            return lll(ACTION_LABELS.get(args[0]) ?? 'altText.skip');
        case NOTICE.ASK_DECORATIVE:
            return lll('altText.decorative');
        case NOTICE.RETRY:
            return lll('altText.retry');
        case NOTICE.NOT_SAVED:
            return format(lll('altText.notSaved'), data.proposal ?? '');
        case NOTICE.SAVED:
            return lll('altText.saved');
        case NOTICE.DECORATIVE:
            return lll('altText.decorativeSaved');
        case NOTICE.SKIPPED:
            return lll('altText.skipped');
        default:
            return null;
    }
}

/** @returns {Array<[string, string]>} */
function cardLines(data) {
    const lines = [
        [lll('altText.file'), data.fileName ?? ''],
        data.subjectType ? [data.subjectType, data.subject ?? ''] : [lll('altText.page'), data.subject ?? ''],
        [data.partType === 'field' ? lll('altText.field') : lll('altText.element'), data.part ?? ''],
    ];
    if (data.language) {
        lines.push([lll('altText.language'), data.language]);
    }
    return lines;
}

/**
 * "Alt-Text ergänzen": the image, where it is used, and that it has no alt
 * text. The current card's heading is where the keyboard focus goes when
 * the chat takes up an image (`data-alt-text-current`).
 */
function renderCard(data, idx, current) {
    const note = IMAGE_NOTES.get(data.imageNote ?? '');

    return html`
        <section class="alt-text-card" aria-labelledby="alt-text-card-${idx}">
            <h3 id="alt-text-card-${idx}" tabindex="-1" ?data-alt-text-current=${current}>${lll('altText.heading')}</h3>
            ${data.preview ? html`<img src="${data.preview}" alt="" loading="lazy">` : nothing}
            <dl>
                ${cardLines(data).map(([label, value]) => html`<dt>${label}:</dt><dd>${value}</dd>`)}
            </dl>
            <p>${lll('altText.missing')}</p>
            ${note ? html`<p class="alt-text-note">${lll(note)}</p>` : nothing}
        </section>
    `;
}

/**
 * The proposal and its answers, in place of the approval card's body.
 *
 * A proposed text: "Übernehmen" approves the card, "Andere Variante" denies it
 * with that reason, "Überspringen" is the chat's own action, and "Als
 * dekorativ markieren" — offered only where the editor may set the mark —
 * asks for the mark's card. The mark's card (the model's proposal, case 1, or
 * the one the chip asked for): its lines, with the warning of case 2 where it
 * applies, "Als dekorativ markieren" approves it — the one confirmation —
 * and "Alt-Text erstellen" asks for a text instead.
 *
 * @param {object} chat the ChatCoreController
 * @param {object} pending the card as getMessages() returns it
 */
export function renderAltTextCard(chat, pending) {
    const busy = chat.approvalBusy || chat.altTextBusy;
    const blocked = Boolean(pending.approveBlocked);
    const decorative = Boolean(chat.altText?.decorative);
    if (pending.answers === 'altTextDecorative') {
        const lines = pending.calls?.[0]?.previewLines ?? [];
        return html`
            <div class="alt-text-proposal">
                ${lines.map((line) => html`<p class="alt-text-proposal-text">${line}</p>`)}
                ${blocked ? html`<p class="alt-text-note">${lll(approveBlockedLabel(pending.approveBlocked))}</p>` : nothing}
                <div class="alt-text-answers" role="group" aria-label="${lll('altText.answers')}">
                    ${blocked ? nothing : html`<button type="button" class="btn btn-sm btn-answer btn-primary" ?disabled=${busy}
                        title="${lll('altText.decorative.help')}"
                        @click=${() => chat.decideApproval(true)}>${lll('altText.decorative')}</button>`}
                    <button type="button" class="btn btn-sm btn-answer" ?disabled=${busy}
                        @click=${() => chat.decideApproval(false, 'variant')}>${lll('altText.createText')}</button>
                    <button type="button" class="btn btn-sm btn-answer" ?disabled=${busy}
                        @click=${() => chat.skipAltText()}>${lll('altText.skip')}</button>
                </div>
            </div>
        `;
    }

    return html`
        <div class="alt-text-proposal">
            <p class="alt-text-proposal-text"><strong>${lll('altText.proposal')}</strong> ${pending.altText?.proposal ?? ''}</p>
            ${blocked ? html`<p class="alt-text-note">${lll(approveBlockedLabel(pending.approveBlocked))}</p>` : nothing}
            <div class="alt-text-answers" role="group" aria-label="${lll('altText.answers')}">
                ${blocked ? nothing : html`<button type="button" class="btn btn-sm btn-answer btn-primary" ?disabled=${busy}
                    @click=${() => chat.decideApproval(true)}>${lll('altText.apply')}</button>`}
                <button type="button" class="btn btn-sm btn-answer" ?disabled=${busy}
                    @click=${() => chat.decideApproval(false, 'variant')}>${lll('altText.variant')}</button>
                ${decorative ? html`<button type="button" class="btn btn-sm btn-answer" ?disabled=${busy}
                    title="${lll('altText.decorative.help')}" aria-describedby="alt-text-decorative-help"
                    @click=${() => chat.markAltTextDecorative()}>${lll('altText.decorative')}</button>` : nothing}
                <button type="button" class="btn btn-sm btn-answer" ?disabled=${busy}
                    @click=${() => chat.skipAltText()}>${lll('altText.skip')}</button>
            </div>
            ${decorative ? html`<p class="alt-text-note" id="alt-text-decorative-help">${lll('altText.decorative.help')}</p>` : nothing}
        </div>
    `;
}

/**
 * Below the transcript, while the conversation waits for the editor and no
 * proposal is open: "Nächsten Treffer optimieren" while another image is
 * open.
 *
 * @param {object} chat the ChatCoreController
 */
export function renderAltTextNext(chat) {
    const state = chat.altText;
    if (!state || chat.isProcessing() || chat.status === 'awaiting_approval' || chat.status === 'awaiting_input') {
        return nothing;
    }

    if (!state.next) {
        return nothing;
    }

    return html`
        <div class="alt-text-next alt-text-answers" role="group" aria-label="${lll('altText.answers')}">
            <button type="button" class="btn btn-sm btn-answer" ?disabled=${chat.altTextBusy}
                @click=${() => chat.nextAltText()}>${lll('altText.next')}</button>
        </div>
    `;
}

/**
 * Above the input: the image the conversation is about, as chips, and what
 * the assistant may change ("Der Assistant ändert nur den Alt-Text dieser
 * Bildverwendung …").
 *
 * @param {object} chat the ChatCoreController
 */
export function renderAltTextContext(chat) {
    const reference = chat.altText?.reference;
    if (!reference) {
        return nothing;
    }

    const card = [...chat.messages].reverse().find((msg) => msg.notice === NOTICE.HIT && msg.data?.reference === reference)?.data;
    if (!card) {
        return nothing;
    }

    const subject = card.subjectType ? `${card.subjectType}: ${card.subject}` : card.subject;
    const info = card.partType === 'field'
        ? format(lll('altText.context.infoField'), card.part ?? '', subject ?? '')
        : format(lll('altText.context.infoElement'), card.part ?? '', subject ?? '');

    return html`
        <div class="alt-text-context" role="group" aria-label="${lll('altText.context.label')}">
            <span class="alt-text-chip" title="${subject}">${subject}</span>
            <span class="alt-text-chip" title="${card.fileName} · ${card.part}">${card.fileName} · ${card.part}</span>
            <p class="alt-text-context-info">${info}</p>
        </div>
    `;
}
