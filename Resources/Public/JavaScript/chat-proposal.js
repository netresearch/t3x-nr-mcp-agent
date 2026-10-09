// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';

/**
 * The proposal of a guided process (ADR-023): the approval card of a write
 * in a process run, shown in the message flow as a quoted block with what
 * was found, what it is about, the current and the proposed value, and the
 * three answers directly below it.
 *
 * Every word in it comes from the server's card, never from the model's
 * answer: the heading is the change's name, "Betroffen" is nr-llm's
 * structured target with the schema's labels, and "Aktuell" / "Vorschlag" and
 * the length are nr-llm's structured preview. Where nr-llm carries no
 * structured preview, the block shows nr-llm's preview lines as they are.
 */

export const chatProposalStyles = css`
    .proposal {
        margin: 8px 0 0;
        padding: 2px 0 2px 12px;
        border-left: 3px solid var(--typo3-component-primary-color, #0078e6);
    }
    .proposal .approval-title { margin: 0 0 4px; }
    .proposal-facts {
        display: grid;
        grid-template-columns: max-content minmax(0, 1fr);
        gap: 2px 12px;
        margin: 0;
    }
    .proposal-facts dt { font-weight: 600; }
    .proposal-facts dd { margin: 0; overflow-wrap: anywhere; white-space: pre-wrap; }
`;

/**
 * Whether the card renders as a proposal: a write in a process run with one
 * pending call, which is what nr-llm's one-approval rule leaves for a
 * process (nr-llm ADR-214, item 9).
 *
 * @param {object|null} pending the card as getMessages() returns it
 */
export function isProposal(pending) {
    return pending?.answers === 'process' && pending.calls?.length === 1;
}

/**
 * "Betroffen": the table's label, the record's uid and the fields' labels.
 *
 * @param {{tableLabel: string, uid: number, fieldLabels: string[]}|null} affected
 */
export function affectedText(affected) {
    if (!affected) {
        return '';
    }

    const record = `${affected.tableLabel} ${affected.uid}`;

    return affected.fieldLabels?.length ? `${record} · ${affected.fieldLabels.join(', ')}` : record;
}

/**
 * @param {{count: number, min: number, max: number}} measure
 * @param {string} suffix the field's name when the proposal changes several
 */
function renderMeasure(measure, suffix) {
    const within = measure.count >= measure.min && measure.count <= measure.max;

    return html`
        <dt>${lll('chat.proposalLength')}${suffix}</dt>
        <dd class="proposal-measure" data-within=${within ? 'true' : 'false'}>${measure.count} ${lll('chat.proposalCharacters')}
            (${lll('chat.proposalTarget')} ${measure.min}–${measure.max}${within ? '' : html`, ${lll('chat.proposalOutOfRange')}`})</dd>
    `;
}

/**
 * @param {object} call one call of the card
 */
function renderStructured(call) {
    const labels = call.affected?.fields ?? [];
    const several = call.structured.length > 1;

    return call.structured.map((entry) => {
        const index = labels.indexOf(entry.field);
        const label = index >= 0 ? call.affected.fieldLabels[index] : entry.field;

        // With several fields, each term names its field; a <dd> without its
        // <dt> would not be a valid description list.
        const suffix = several ? ` · ${label}` : '';

        return html`
            <dt>${lll('chat.proposalCurrent')}${suffix}</dt>
            <dd>${entry.current === '' ? lll('chat.proposalEmpty') : entry.current}</dd>
            <dt>${lll('chat.proposalProposed')}${suffix}</dt>
            <dd>${entry.proposed === '' ? lll('chat.proposalEmpty') : entry.proposed}</dd>
            ${entry.measure ? renderMeasure(entry.measure, suffix) : nothing}
        `;
    });
}

/**
 * The block above the answers.
 *
 * @param {object} call the card's one call
 * @param {(call: object) => unknown} renderLines the surface's own rendering of nr-llm's preview lines
 */
export function renderProposal(call, renderLines) {
    const affected = affectedText(call.affected);

    return html`
        <div class="proposal">
            <p class="approval-title" role="heading" aria-level="3"><strong>${call.actionLabel || lll('chat.approvalTitleGeneric')}</strong></p>
            ${affected || call.structured ? html`
                <dl class="proposal-facts">
                    ${affected ? html`<dt>${lll('chat.proposalAffected')}</dt><dd>${affected}</dd>` : nothing}
                    ${call.structured ? renderStructured(call) : nothing}
                </dl>
            ` : nothing}
            ${call.structured ? nothing : renderLines(call)}
        </div>
    `;
}
