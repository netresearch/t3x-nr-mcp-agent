/**
 * Heading structure of the AI Chat module.
 *
 * The module document is `<body><nr-chat-app>` and nothing else, so the
 * component is the only place its headings can come from. It used to open
 * with an h3 ("Conversations") and have no h1 at all: axe reported
 * page-has-heading-one on the demo, and a screen-reader user jumping by
 * heading landed on a third-level heading with nothing above it.
 */

import {describe, test, expect, beforeEach} from '@jest/globals';

async function renderModule(chatState = {}) {
    await import('../../Resources/Public/JavaScript/chat-app.js');

    const el = document.createElement('nr-chat-app');
    document.body.append(el);
    Object.assign(el.chat, {
        activeUid: null,
        available: true,
        loading: false,
        issues: [],
        conversations: [],
        messages: [],
        ...chatState,
    });
    el.requestUpdate();
    await el.updateComplete;

    return el;
}

/** Heading levels in document order, shadow root included. */
function headingLevels(el) {
    return [...el.shadowRoot.querySelectorAll('h1, h2, h3, h4, h5, h6')]
        .map((h) => Number(h.localName.slice(1)));
}

const OPEN_CONVERSATION = {activeUid: 42, conversations: [{uid: 42, title: 'Hello', status: 'idle'}]};

describe('chat module headings', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    test.each([
        ['without an open conversation', {}],
        ['with an open conversation', OPEN_CONVERSATION],
    ])('has exactly one h1 %s', async (_label, state) => {
        const el = await renderModule(state);

        expect(headingLevels(el).filter((level) => level === 1)).toHaveLength(1);
    });

    test('the h1 names the open conversation', async () => {
        const el = await renderModule(OPEN_CONVERSATION);

        expect(el.shadowRoot.querySelector('h1').textContent.trim()).toBe('Hello');
    });

    test('the h1 names the module when no conversation is open', async () => {
        const el = await renderModule();

        expect(el.shadowRoot.querySelector('h1').textContent.trim()).toBe('panel.title');
    });

    test.each([
        ['without an open conversation', {}],
        ['with the activity panel open', {...OPEN_CONVERSATION, activityOpen: true}],
    ])('skips no heading level %s', async (_label, state) => {
        const el = await renderModule(state);
        const levels = headingLevels(el);

        // No heading may go deeper than one level below the deepest one
        // opened so far — an h1 followed by an h3 breaks the outline.
        let deepest = 1;
        for (const level of levels) {
            expect(level).toBeLessThanOrEqual(deepest + 1);
            deepest = Math.max(deepest, level);
        }
        expect(levels.length).toBeGreaterThan(1);
    });
});

/**
 * The floating panel is a complementary region inside the backend page, not a
 * page of its own, so it carries no h1; its headings start at h2. Maximized, it
 * shows the conversation sidebar and the activity list side by side.
 */
describe('chat panel headings', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    async function renderPanel(chatState) {
        await import('../../Resources/Public/JavaScript/ai-chat-panel.js');
        const el = document.createElement('ai-chat-panel');
        document.body.append(el);
        el.state = 'maximized';
        Object.assign(el.chat, {available: true, loading: false, issues: [], messages: [], conversations: [], ...chatState});
        el.requestUpdate();
        await el.updateComplete;

        return el;
    }

    test('the maximized panel opens its outline at h2 and skips no level', async () => {
        const el = await renderPanel({...OPEN_CONVERSATION, activityOpen: true});
        const levels = headingLevels(el);

        expect(el.shadowRoot.querySelector('.panel-sidebar-header h2')).not.toBeNull();
        expect(levels.length).toBeGreaterThan(1);
        expect(levels).not.toContain(1);
        let deepest = 1;
        for (const level of levels) {
            expect(level).toBeLessThanOrEqual(deepest + 1);
            deepest = Math.max(deepest, level);
        }
    });
});

describe('chat module while loading', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    test('already has its h1', async () => {
        const el = await renderModule({loading: true});

        expect(headingLevels(el)).toEqual([1]);
        expect(el.shadowRoot.querySelector('h1').textContent.trim()).toBe('panel.title');
    });
});
