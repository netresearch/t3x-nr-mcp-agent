// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * "Änderungen dieses Auftrags" and the approval card's structured facts
 * (ADR-034), on both surfaces: the full-page module and the popup.
 *
 * The assertions are about what a reader sees and can operate: a native
 * disclosure that asks for the list only when opened, one badge per fact (a
 * record that is not hidden is not called public, a draft is not called
 * hidden), honest notes where the list does not cover everything, and a card
 * that marks a refused call and offers no approval for it.
 */

import {describe, test, expect, beforeEach, jest} from '@jest/globals';
import {changeBadges, openInContentFrame} from '../../Resources/Public/JavaScript/chat-changes.js';

const SURFACES = [
    {name: 'chat-app (full page)', module: '../../Resources/Public/JavaScript/chat-app.js', tag: 'nr-chat-app', open: false},
    {name: 'ai-chat-panel (popup)', module: '../../Resources/Public/JavaScript/ai-chat-panel.js', tag: 'ai-chat-panel', open: true},
];

const CHANGE = {
    table: 'pages', tableLabel: 'Seite', uid: 12, title: 'Neue Seite', action: 'created',
    writeTarget: true, failedAfterWrite: false, draft: true, workspace: {id: 3, title: 'Relaunch'},
    language: {id: 1, label: 'Deutsch'}, hidden: true, restrictions: ['starttime'], legacy: false, complete: null,
    editUrl: '/typo3/record/edit?edit[pages][12]=edit', historyUrl: '/typo3/record/history?element=pages:12', workspaceUrl: '',
};

const DEPENDENT = {
    ...CHANGE, table: 'tt_content', tableLabel: 'Inhaltselement', uid: 40, title: 'Text', writeTarget: false,
    hidden: false, restrictions: [], language: null, editUrl: '', historyUrl: '',
};

const DATA = {runs: 1, changes: [CHANGE, DEPENDENT], withheld: 0, omitted: 0, notCaptured: false, legacy: false, unreadableRuns: 0};

async function render(modulePath, tag, open, {data = DATA, available = true, pending = null, uiOpen = false} = {}) {
    await import(modulePath);
    const el = document.createElement(tag);
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: 1,
        available: true,
        loading: false,
        issues: [],
        conversations: [{uid: 1, title: 'A chat', status: pending ? 'awaiting_approval' : 'idle', messageCount: 1, pinned: false}],
        messages: [{role: 'user', content: 'Lege eine Seite an'}],
        status: pending ? 'awaiting_approval' : 'idle',
        errorMessage: '',
        pendingApproval: pending,
        changesAvailable: available,
        changesOpen: uiOpen,
        changes: data ? {state: 'loaded', data} : null,
    });
    el.chat._api = {...el.chat._api, getChanges: jest.fn(async () => data ?? DATA)};
    if (open) {
        el.state = 'OPEN';
    }
    el.requestUpdate();
    await el.updateComplete;

    return el;
}

describe.each(SURFACES)('$name change view', ({module: modulePath, tag, open}) => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    test('is absent while there is nothing to list', async () => {
        const el = await render(modulePath, tag, open, {available: false});

        expect(el.shadowRoot.querySelector('.changes-view')).toBeNull();
    });

    test('is a closed native disclosure that asks for the list only when it is opened', async () => {
        const el = await render(modulePath, tag, open, {data: null});
        const details = el.shadowRoot.querySelector('details.changes-view');

        expect(details).not.toBeNull();
        expect(details.open).toBe(false);
        expect(details.querySelector('summary').textContent).toContain('chat.changesTitle');
        expect(el.chat._api.getChanges).not.toHaveBeenCalled();

        details.open = true;
        details.dispatchEvent(new Event('toggle'));
        await el.updateComplete;

        expect(el.chat._api.getChanges).toHaveBeenCalledWith(1);
        expect(el.chat.changesOpen).toBe(true);
    });

    test('lists one row per record with its link, and the count in the summary', async () => {
        const el = await render(modulePath, tag, open, {uiOpen: true});
        const rows = el.shadowRoot.querySelectorAll('.changes-view .change');

        expect(rows).toHaveLength(2);
        expect(rows[0].querySelector('a').getAttribute('href')).toBe(CHANGE.editUrl);
        expect(rows[0].querySelector('a').textContent).toBe('Neue Seite');
        // A record without a link is named, not linked.
        expect(rows[1].querySelector('.change-record a')).toBeNull();
        expect(rows[1].querySelector('.change-record').textContent).toContain('Text');
        expect(el.shadowRoot.querySelector('.changes-view summary').textContent).toContain('(2)');
    });

    test('states every fact as its own badge', async () => {
        const el = await render(modulePath, tag, open, {uiOpen: true});
        const badges = (row) => [...row.querySelectorAll('.change-badge')].map((b) => b.dataset.badge);
        const rows = el.shadowRoot.querySelectorAll('.changes-view .change');

        expect(badges(rows[0])).toEqual(['action', 'workspace', 'language', 'hidden', 'restriction-starttime', 'role']);
        // Not hidden is stated as such and is no word for "public"; the draft badge stands beside it.
        expect(badges(rows[1])).toEqual(['action', 'workspace', 'hidden', 'role']);
        expect(rows[1].querySelector('[data-badge="hidden"]').textContent).toBe('chat.changeNotHidden');
        expect(rows[1].querySelector('[data-badge="workspace"]').textContent).toBe('chat.changeWorkspaceDraft');
        expect(rows[0].querySelector('.change-badges').getAttribute('aria-label')).toBe('chat.changeFacts');
    });

    test('never ends without saying what the list covers, and says more where the server reports a gap', async () => {
        const plain = await render(modulePath, tag, open, {uiOpen: true});
        const notes = (el) => [...el.shadowRoot.querySelectorAll('.changes-view .changes-note')].map((n) => n.textContent);

        expect(notes(plain)).toEqual(['chat.changesCoverage']);

        document.body.replaceChildren();
        const gaps = await render(modulePath, tag, open, {
            uiOpen: true,
            data: {...DATA, notCaptured: true, omitted: 4, withheld: 2, legacy: true, unreadableRuns: 1},
        });

        expect(notes(gaps)).toEqual([
            'chat.changesNotCaptured', 'chat.changesOmitted', 'chat.changesWithheld',
            'chat.changesLegacy', 'chat.changesUnreadable', 'chat.changesCoverage',
        ]);
    });

    test('an empty list says so and still states its coverage', async () => {
        const el = await render(modulePath, tag, open, {uiOpen: true, data: {...DATA, changes: []}});
        const text = [...el.shadowRoot.querySelectorAll('.changes-view .changes-note')].map((n) => n.textContent);

        expect(text).toEqual(['chat.changesNone', 'chat.changesCoverage']);
    });
});

describe('badges', () => {
    test('a record in live is a warning, a draft is not hidden by being a draft, and a failure is its own badge', () => {
        const live = changeBadges({...CHANGE, draft: false, workspace: {id: 0, title: ''}, hidden: null, restrictions: [], language: null, failedAfterWrite: true});

        expect(live.map((b) => b.key)).toEqual(['action', 'workspace', 'role', 'failed']);
        expect(live.find((b) => b.key === 'workspace').tone).toBe('warning');
        expect(live.find((b) => b.key === 'failed').tone).toBe('danger');
    });

    test('a legacy entry names no workspace, language or flag it does not know', () => {
        const legacy = changeBadges({table: 'pages', uid: 1, action: '', legacy: true, complete: false, writeTarget: true, restrictions: [], hidden: null, language: null, draft: false});

        expect(legacy.map((b) => b.key)).toEqual(['action', 'legacy', 'role', 'partial']);
    });
});

describe('opening a backend link', () => {
    afterEach(() => {
        delete globalThis.TYPO3;
    });

    test('goes to the backend content frame when there is one, and leaves a modified click alone', () => {
        const setUrl = jest.fn();
        globalThis.TYPO3 = {Backend: {ContentContainer: {setUrl}}};

        const plain = {button: 0, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, preventDefault: jest.fn()};
        openInContentFrame(plain, '/typo3/record/edit');
        expect(setUrl).toHaveBeenCalledWith('/typo3/record/edit');
        expect(plain.preventDefault).toHaveBeenCalled();

        const modified = {...plain, ctrlKey: true, preventDefault: jest.fn()};
        openInContentFrame(modified, '/typo3/record/edit');
        expect(modified.preventDefault).not.toHaveBeenCalled();
    });

    test('follows the link as it is without a content frame', () => {
        const event = {button: 0, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, preventDefault: jest.fn()};
        openInContentFrame(event, '/typo3/record/edit');

        expect(event.preventDefault).not.toHaveBeenCalled();
    });
});

const REFUSED = {
    name: 'create_page_draft', actionLabel: 'Seite anlegen', heading: 'Seite anlegen', toolStillRegistered: true,
    previewLines: ['Die Konfiguration verlangt einen Entwurfs-Workspace.'], technicalDetails: '', previewFailed: false,
    argumentsJson: '{}', refused: true, workspaceId: 0, workspaceTitle: '', language: null, workspace: '',
};

const WAITING = {
    ...REFUSED, name: 'update_page_metadata', actionLabel: 'Metadaten ändern', heading: 'Metadaten ändern',
    previewLines: ['Titel: „Neu“'], refused: false, workspaceId: 3, workspaceTitle: 'Relaunch',
};

function card(calls, extra = {}) {
    return {
        runUuid: 'run-1', turnDigest: 'd', configLabel: 'Demo', unreadableReason: null, answers: 'plain',
        approveBlocked: '', draftWorkspaceRequired: false, calls, ...extra,
    };
}

describe.each(SURFACES)('$name approval card, structured facts', ({module: modulePath, tag, open}) => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    test('marks a refused call, shows the rule\'s explanation, and the button names what is carried out', async () => {
        const el = await render(modulePath, tag, open, {available: false, pending: card([REFUSED, WAITING])});
        const calls = el.shadowRoot.querySelectorAll('.approval-call');

        expect(calls[0].querySelector('[data-refused="true"]').textContent).toContain('chat.approvalRefused');
        expect(calls[0].textContent).toContain('Die Konfiguration verlangt einen Entwurfs-Workspace.');
        expect(calls[1].querySelector('[data-refused]')).toBeNull();
        const approve = el.shadowRoot.querySelector('.approval-actions .btn-primary');
        expect(approve.textContent.trim()).toBe('Metadaten ändern');
    });

    test('offers no approve button when the server says every call is refused', async () => {
        const el = await render(modulePath, tag, open, {available: false, pending: card([REFUSED], {approveBlocked: 'allRefused'})});

        expect(el.shadowRoot.querySelector('.approval-actions .btn-primary')).toBeNull();
        expect(el.shadowRoot.querySelector('.approval-blocked').textContent).toContain('chat.approvalAllRefused');
    });

    test('tells a reader who works live that the configuration requires a draft workspace', async () => {
        const el = await render(modulePath, tag, open, {available: false, pending: card([REFUSED], {draftWorkspaceRequired: true, approveBlocked: 'allRefused'})});
        expect(el.shadowRoot.querySelector('.approval-note[role="note"]').textContent).toContain('chat.approvalDraftWorkspaceRequired');

        document.body.replaceChildren();
        const inDraft = await render(modulePath, tag, open, {available: false, pending: card([WAITING], {draftWorkspaceRequired: true})});
        expect(inDraft.shadowRoot.querySelector('.approval-note')).toBeNull();

        document.body.replaceChildren();
        const notRequired = await render(modulePath, tag, open, {available: false, pending: card([REFUSED], {draftWorkspaceRequired: false, approveBlocked: 'allRefused'})});
        expect(notRequired.shadowRoot.querySelector('.approval-note')).toBeNull();
    });
});

describe('loading the list', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    test('an answer for a conversation the reader has left is dropped', async () => {
        const el = await render(SURFACES[0].module, SURFACES[0].tag, false, {data: null});
        let release;
        el.chat._api.getChanges = jest.fn(() => new Promise((resolve) => { release = resolve; }));

        const loading = el.chat.loadChanges();
        el.chat.activeUid = 2;
        el.chat.changes = null;
        release(DATA);
        await loading;

        expect(el.chat.changes).toBeNull();
    });

    test('a list that is open is asked for again when a poll reports changes, and a closed one is not', async () => {
        const el = await render(SURFACES[0].module, SURFACES[0].tag, false, {uiOpen: true});
        el.chat._setChangesAvailable({changesAvailable: true});
        expect(el.chat._api.getChanges).toHaveBeenCalledTimes(1);

        el.chat.changesOpen = false;
        el.chat._setChangesAvailable({changesAvailable: true});
        expect(el.chat._api.getChanges).toHaveBeenCalledTimes(1);

        // The fast poll path carries no flag and changes nothing.
        el.chat.changesOpen = true;
        el.chat._setChangesAvailable({});
        expect(el.chat._api.getChanges).toHaveBeenCalledTimes(1);
    });

    test('a failed request keeps the list that was shown and says so', async () => {
        const el = await render(SURFACES[0].module, SURFACES[0].tag, false, {uiOpen: true});
        el.chat._api.getChanges = jest.fn(async () => { throw new Error('boom'); });

        await el.chat.loadChanges();
        await el.updateComplete;

        expect(el.chat.changes.state).toBe('error');
        expect(el.chat.changes.data).toEqual(DATA);
        expect(el.shadowRoot.querySelector('.changes-view [role="alert"]').textContent).toContain('chat.changesError');
        expect(el.shadowRoot.querySelectorAll('.changes-view .change')).toHaveLength(2);
    });
});
