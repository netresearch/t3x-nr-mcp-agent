/**
 * Exporting a conversation as Markdown (NEXT-172).
 *
 * Built client-side from the transcript the chat already holds, so what the
 * file contains is decided here: the user and assistant turns, attachment
 * names, and nothing the chat itself hides.
 */

import {describe, test, expect, jest, afterEach} from '@jest/globals';
import {ChatCoreController, downloadTextFile} from '../../Resources/Public/JavaScript/chat-core.js';

function controller(state) {
    const host = {addController() {}, requestUpdate() {}};
    const chat = new ChatCoreController(host);
    Object.assign(chat, state);
    return chat;
}

describe('buildMarkdownExport', () => {
    const chat = () => controller({
        activeUid: 7,
        conversations: [{uid: 7, title: 'Seitenbaum prüfen'}],
        messages: [
            {role: 'user', content: 'Welche Seiten sind versteckt?', createdAt: '2026-09-23T08:00:00+00:00'},
            {role: 'assistant', content: '', tool_calls: [{id: 'c1'}]},
            {role: 'tool', content: '{"rows": []}'},
            {role: 'assistant', content: 'Drei Seiten:\n\n- Impressum\n- Test\n- Alt'},
            {role: 'system', content: 'notice'},
            {role: 'user', content: 'Und das Bild?', fileUid: 4, fileName: 'bild.png'},
        ],
    });

    test('starts with the conversation title', () => {
        expect(chat().buildMarkdownExport().startsWith('# Seitenbaum prüfen\n')).toBe(true);
    });

    test('contains the user and assistant turns in order, the assistant text verbatim', () => {
        const md = chat().buildMarkdownExport();
        const question = md.indexOf('Welche Seiten sind versteckt?');
        const answer = md.indexOf('Drei Seiten:\n\n- Impressum\n- Test\n- Alt');
        const followUp = md.indexOf('Und das Bild?');

        expect(question).toBeGreaterThan(-1);
        expect(answer).toBeGreaterThan(question);
        expect(followUp).toBeGreaterThan(answer);
        expect(md).toContain('## export.roleUser');
        expect(md).toContain('## export.roleAssistant');
    });

    test('leaves out tool traffic and system notices', () => {
        const md = chat().buildMarkdownExport();
        expect(md).not.toContain('"rows"');
        expect(md).not.toContain('notice');
        // One heading per visible turn: two user, one assistant.
        expect(md.match(/^## /gm)).toHaveLength(3);
    });

    test('names an attached file', () => {
        expect(chat().buildMarkdownExport()).toContain('*export.attachment: bild.png*');
    });
});

describe('exportFileName', () => {
    test('slugs the title and appends the date', () => {
        const chat = controller({activeUid: 1, conversations: [{uid: 1, title: 'Über Seiten & Inhalte!'}]});
        expect(chat.exportFileName(new Date(2026, 8, 3))).toBe('ai-chat-uber-seiten-inhalte-2026-09-03.md');
    });

    test('falls back when the title has nothing usable', () => {
        const chat = controller({activeUid: 1, conversations: [{uid: 1, title: '???'}]});
        expect(chat.exportFileName(new Date(2026, 0, 1))).toBe('ai-chat-conversation-2026-01-01.md');
    });
});

describe('downloadTextFile', () => {
    afterEach(() => {
        delete URL.createObjectURL;
        delete URL.revokeObjectURL;
    });

    test('clicks a download anchor carrying the file name and removes it again', () => {
        URL.createObjectURL = jest.fn(() => 'blob:x');
        URL.revokeObjectURL = jest.fn();
        const clicked = [];
        const spy = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
            clicked.push({href: this.href, download: this.download, attached: this.isConnected});
        });

        downloadTextFile(document, 'a.md', '# x');

        expect(clicked).toEqual([{href: 'blob:x', download: 'a.md', attached: true}]);
        expect(document.querySelector('a[download]')).toBeNull();
        expect(URL.createObjectURL.mock.calls[0][0]).toBeInstanceOf(Blob);
        spy.mockRestore();
    });
});
