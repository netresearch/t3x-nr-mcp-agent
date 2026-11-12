// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The hints of nr-llm's rule-set judge (nr-llm ADR-288): the line the chat
 * stores with notice `ruleHints` shows a label in the reader's language and,
 * under it, each hint as nr-llm wrote it, as text. Asserted on both surfaces,
 * the full-page module and the popup, and in the Markdown export.
 */

import {describe, test, expect, beforeEach} from '@jest/globals';
import {ChatCoreController, escapeMarkdownText} from '../../Resources/Public/JavaScript/chat-core.js';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const VIOLATION = 'This passage may break the rule "No superlatives" (snippet "Legal"): "the <b>best</b> airfryer ever"';
const UNAVAILABLE = 'This text was not checked against the rule set, because the check gave no usable answer.';

const LINE = {
    role: 'assistant',
    content: '[The rule-set check reported on the change this run proposed.]',
    notice: 'ruleHints',
    data: {hints: [{kind: 'violation', message: VIOLATION}, {kind: 'unavailable', message: UNAVAILABLE}]},
};

async function render(modulePath, tag, open, messages) {
    await import(modulePath);

    const el = document.createElement(tag);
    document.body.append(el);

    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'A chat', status: 'awaiting_approval', messageCount: 3, pinned: false}],
        status: 'idle',
        errorMessage: '',
        approvalUrl: '',
        messages,
    });

    if (open) {
        el.state = 'OPEN';
    }

    el.requestUpdate();
    await el.updateComplete;

    return el;
}

describe.each(SURFACES)('$name rule-set hints', ({module: modulePath, tag, open}) => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    test('the line shows the label, not the stored sentence, and each hint under it', async () => {
        const el = await render(modulePath, tag, open, [
            {role: 'user', content: 'Schreib einen Teaser'},
            {role: 'assistant', content: 'Ich schlage einen Teaser vor.'},
            LINE,
        ]);

        const rows = el.shadowRoot.querySelectorAll('.message-row.assistant');
        const row = rows[rows.length - 1];
        expect(row.querySelector('.message.assistant').textContent).toContain('chat.ruleHints');
        expect(row.textContent).not.toContain('The rule-set check reported');

        const notice = row.querySelector('.message-notice');
        expect(notice.getAttribute('role')).toBe('note');
        const items = [...notice.querySelectorAll('li')].map((item) => item.textContent.trim());
        expect(items).toEqual([VIOLATION, UNAVAILABLE]);
    });

    test('a hint is rendered as text, never as markup', async () => {
        const el = await render(modulePath, tag, open, [LINE]);

        const notice = el.shadowRoot.querySelector('.message-notice');
        expect(notice.querySelector('b')).toBeNull();
        expect(notice.textContent).toContain('<b>best</b>');
    });

    test('a line without usable hints renders no list', async () => {
        const el = await render(modulePath, tag, open, [
            {...LINE, data: {hints: [{kind: 'violation'}, 'text', null]}},
        ]);

        expect(el.shadowRoot.querySelector('.message-notice')).toBeNull();
    });

    test('the nothing-saved notice is unchanged beside it', async () => {
        const el = await render(modulePath, tag, open, [
            {role: 'assistant', content: 'Erledigt.', notice: 'nothingSaved'},
            LINE,
        ]);

        const notices = el.shadowRoot.querySelectorAll('.message-notice');
        expect(notices).toHaveLength(2);
        expect(notices[0].textContent).toContain('chat.nothingSaved');
        expect(notices[1].querySelectorAll('li')).toHaveLength(2);
    });
});

describe('rule-set hints in the export', () => {
    test('the label and the hints are exported as a list', () => {
        const chat = new ChatCoreController({addController() {}, requestUpdate() {}});
        Object.assign(chat, {
            activeUid: 7,
            messagesUid: 7,
            conversations: [{uid: 7, title: 'Teaser'}],
            messages: [{role: 'user', content: 'Schreib einen Teaser'}, LINE],
        });

        const md = chat.buildMarkdownExport();
        expect(md).toContain('chat.ruleHints');
        expect(md).toContain('- This passage may break the rule "No superlatives" \\(snippet "Legal"\\): "the &lt;b&gt;best&lt;/b&gt; airfryer ever"');
        expect(md).toContain(`- ${UNAVAILABLE}`);
        expect(md).not.toContain('<b>');
        expect(md).not.toContain('The rule-set check reported');
    });

    test('a message without the notice exports no hints', () => {
        const chat = new ChatCoreController({addController() {}, requestUpdate() {}});

        expect(chat.ruleHints({role: 'assistant', content: 'x', data: {hints: [{kind: 'violation', message: 'y'}]}})).toEqual([]);
    });
});

describe('escapeMarkdownText', () => {
    test('HTML and Markdown syntax stay literal text', () => {
        expect(escapeMarkdownText('<img src=x onerror=alert(1)> & *bold* [link](u) `c` # h')).toBe(
            '&lt;img src=x onerror=alert\\(1\\)&gt; &amp; \\*bold\\* \\[link\\]\\(u\\) \\`c\\` \\# h',
        );
    });
});
