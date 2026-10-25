// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * "Optimieren" in the list of missing alt texts (alt-text-optimise.js): the
 * button hands the image to the chat panel, the row the chat works on is
 * highlighted, a finished image leaves the list with its counts, and closing
 * the chat gives the keyboard focus back to the row. Without the panel the
 * chat module opens on the image; a button marked aria-disabled does nothing.
 */

import {describe, test, expect, beforeEach, jest} from '@jest/globals';
import {initAltTextOptimise, EVENT} from '../../Resources/Public/JavaScript/alt-text-optimise.js';

/** The markup List.html renders: two tabs, the first open; two rows on pages, one on news. */
function render({available = true, count = 3} = {}) {
    const markup = `
        <h1 data-nr-alt-text-heading data-count="${count}" data-title-none="Keine fehlenden Alt-Texte" data-title-one="1 fehlender Alt-Text" data-title-other="%d fehlende Alt-Texte">3 fehlende Alt-Texte</h1>
        <div data-nr-alt-texts data-chat-uri="/typo3/module/ai-chat?token=x" data-list-uri="/typo3/module/alt-texts?token=y">
            <p id="nr-alt-texts-optimise-note">Im Arbeitsbereich nicht verfügbar.</p>
            <div role="status" data-nr-alt-text-status></div>
            <div role="tablist" data-count-pattern="%d Treffer">
                <button role="tab" id="tab-pages" aria-controls="panel-pages"><span class="badge"><span aria-hidden="true">2</span><span class="visually-hidden">2 Treffer</span></span></button>
                <button role="tab" id="tab-news" aria-controls="panel-news"><span class="badge"><span aria-hidden="true">1</span><span class="visually-hidden">1 Treffer</span></span></button>
            </div>
            <div role="tabpanel" id="panel-pages" aria-labelledby="tab-pages" data-empty-text="Keine Seiten.">
                <table><tbody>
                    ${row(1, 'team.jpg', available)}
                    ${row(2, 'logo.png', available)}
                </tbody></table>
            </div>
            <div role="tabpanel" id="panel-news" aria-labelledby="tab-news" data-empty-text="Keine News." hidden>
                <table><tbody>${row(10, 'news.jpg', available)}</tbody></table>
            </div>
        </div>`;
    const parsed = new DOMParser().parseFromString(markup, 'text/html');
    document.body.replaceChildren(...[...parsed.body.childNodes].map((node) => document.adoptNode(node)));

    return document.querySelector('[data-nr-alt-texts]');
}

function row(reference, file, available) {
    const button = available
        ? `<button type="button" data-nr-alt-text-optimise="${reference}" data-label="Optimieren" data-label-active="Im Chat"><span data-nr-alt-text-label>Optimieren</span></button>`
        : `<button type="button" aria-disabled="true" aria-describedby="nr-alt-texts-optimise-note" data-nr-alt-text-optimise-disabled>Optimieren</button>`;

    return `<tr data-nr-alt-text-row="${reference}" data-file-name="${file}"><td>${file}</td><td>${button}</td></tr>`;
}

/** A stand-in for the chat panel: an event target with optimiseAltText(). */
function fakePanel(result = {ok: true}) {
    const panel = new EventTarget();
    panel.activeAltTextReference = 0;
    panel.optimiseAltText = jest.fn(async () => result);
    panel.emit = (detail) => panel.dispatchEvent(new CustomEvent(EVENT, {detail}));

    return panel;
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const label = (reference) => document.querySelector(`[data-nr-alt-text-row="${reference}"] [data-nr-alt-text-label]`)?.textContent;
const current = () => [...document.querySelectorAll('[aria-current="true"]')].map((r) => Number(r.dataset.nrAltTextRow));

describe('"Optimieren" in the list', () => {
    let assign;

    beforeEach(() => {
        delete globalThis.TYPO3;
        assign = jest.fn();
    });

    test('a click hands the image to the panel and highlights its row with "Im Chat"', async () => {
        const root = render();
        const panel = fakePanel();
        initAltTextOptimise(root, () => panel, assign);

        root.querySelector('[data-nr-alt-text-optimise="2"]').click();
        await flush();

        expect(panel.optimiseAltText).toHaveBeenCalledWith(2);
        expect(current()).toEqual([2]);
        expect(label(2)).toBe('Im Chat');
        expect(label(1)).toBe('Optimieren');
    });

    test('the panel moving to another row moves the highlight, and shows that row\'s tab', () => {
        const root = render();
        const panel = fakePanel();
        initAltTextOptimise(root, () => panel, assign);
        const tabClick = jest.fn(() => {
            document.getElementById('panel-news').hidden = false;
        });
        document.getElementById('tab-news').addEventListener('click', tabClick);

        panel.emit({version: 1, type: 'active', reference: 10, tab: 'tx_news', page: 1});

        expect(tabClick).toHaveBeenCalledTimes(1);
        expect(current()).toEqual([10]);
        expect(assign).not.toHaveBeenCalled();
    });

    test('a row on another page of the list loads that page', () => {
        const root = render();
        const panel = fakePanel();
        initAltTextOptimise(root, () => panel, assign);

        panel.emit({version: 1, type: 'active', reference: 99, tab: 'tx_news', page: 3});

        expect(assign).toHaveBeenCalledTimes(1);
        const target = new URL(assign.mock.calls[0][0]);
        expect(target.pathname).toBe('/typo3/module/alt-texts');
        expect(target.searchParams.get('token')).toBe('y');
        expect(target.searchParams.get('tab')).toBe('tx_news');
        expect(target.searchParams.get('page')).toBe('3');
    });

    test('a finished image leaves the list; the heading, the tab\'s count and the live region follow', () => {
        globalThis.TYPO3 = {lang: {'status.done': '%1$s erledigt. %2$s'}};
        const root = render();
        const panel = fakePanel();
        initAltTextOptimise(root, () => panel, assign);

        panel.emit({version: 1, type: 'done', reference: 1});

        expect(document.querySelector('[data-nr-alt-text-row="1"]')).toBeNull();
        expect(document.querySelector('[data-nr-alt-text-heading]').textContent).toBe('2 fehlende Alt-Texte');
        const badge = document.querySelector('#tab-pages .badge');
        expect(badge.querySelector('[aria-hidden="true"]').textContent).toBe('1');
        expect(badge.querySelector('.visually-hidden').textContent).toBe('1 Treffer');
        expect(document.querySelector('[data-nr-alt-text-status]').textContent).toBe('team.jpg erledigt. 2 fehlende Alt-Texte');
    });

    test('the heading counts every open image, not the rows of this page', () => {
        const root = render({count: 120});
        const panel = fakePanel();
        initAltTextOptimise(root, () => panel, assign);

        panel.emit({version: 1, type: 'done', reference: 1});

        expect(document.querySelector('[data-nr-alt-text-heading]').textContent).toBe('119 fehlende Alt-Texte');
        expect(document.querySelector('[data-nr-alt-text-heading]').dataset.count).toBe('119');
    });

    test('the last row of a tab leaves the tab\'s empty text; the last of all the heading\'s', () => {
        const root = render();
        const panel = fakePanel();
        initAltTextOptimise(root, () => panel, assign);

        panel.emit({version: 1, type: 'gone', reference: 10});
        expect(document.getElementById('panel-news').textContent.trim()).toBe('Keine News.');
        expect(document.querySelector('[data-nr-alt-text-heading]').textContent).toBe('2 fehlende Alt-Texte');

        panel.emit({version: 1, type: 'gone', reference: 1});
        expect(document.querySelector('[data-nr-alt-text-heading]').textContent).toBe('1 fehlender Alt-Text');
        panel.emit({version: 1, type: 'done', reference: 2});
        expect(document.querySelector('[data-nr-alt-text-heading]').textContent).toBe('Keine fehlenden Alt-Texte');
    });

    test('closing the chat drops the highlight and gives the focus back to the row\'s button', () => {
        const root = render();
        const panel = fakePanel();
        initAltTextOptimise(root, () => panel, assign);
        panel.emit({version: 1, type: 'active', reference: 2});

        panel.emit({version: 1, type: 'closed', reference: 2});

        expect(current()).toEqual([]);
        expect(label(2)).toBe('Optimieren');
        expect(document.activeElement).toBe(root.querySelector('[data-nr-alt-text-optimise="2"]'));
    });

    test('an event of another version is ignored', () => {
        const root = render();
        const panel = fakePanel();
        initAltTextOptimise(root, () => panel, assign);

        panel.emit({version: 2, type: 'done', reference: 1});

        expect(document.querySelector('[data-nr-alt-text-row="1"]')).not.toBeNull();
    });

    test('a refused start takes the highlight back to the image the chat is on and says why', async () => {
        const root = render();
        const panel = fakePanel({ok: false, code: 'workspace', error: 'Nur im Live-Arbeitsbereich.'});
        panel.activeAltTextReference = 1;
        initAltTextOptimise(root, () => panel, assign);

        root.querySelector('[data-nr-alt-text-optimise="2"]').click();
        await flush();

        expect(current()).toEqual([1]);
        expect(document.querySelector('[data-nr-alt-text-status]').textContent).toBe('Nur im Live-Arbeitsbereich.');
    });

    test('opened again while the chat works on a row of this page, that row is highlighted at once', () => {
        const root = render();
        const panel = fakePanel();
        panel.activeAltTextReference = 2;

        initAltTextOptimise(root, () => panel, assign);

        expect(current()).toEqual([2]);
    });

    test('without the panel the chat module opens with the image', () => {
        const root = render();
        initAltTextOptimise(root, () => null, assign);

        root.querySelector('[data-nr-alt-text-optimise="10"]').click();

        expect(assign).toHaveBeenCalledTimes(1);
        const target = new URL(assign.mock.calls[0][0]);
        expect(target.pathname).toBe('/typo3/module/ai-chat');
        expect(target.searchParams.get('token')).toBe('x');
        expect(target.searchParams.get('altTextReference')).toBe('10');
    });

    test('a button marked aria-disabled does nothing', async () => {
        const root = render({available: false});
        const panel = fakePanel();
        initAltTextOptimise(root, () => panel, assign);

        root.querySelector('[data-nr-alt-text-optimise-disabled]').click();
        await flush();

        expect(panel.optimiseAltText).not.toHaveBeenCalled();
        expect(assign).not.toHaveBeenCalled();
        expect(current()).toEqual([]);
    });
});
