// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The tabs of the list "Fehlende Alt-Texte" (alt-text-tabs.js): a click and
 * the keys of the WAI-ARIA tabs pattern show a tab and hide the others, only
 * the open tab is in the tab order.
 */

import {describe, test, expect} from '@jest/globals';
import {initAltTextTabs} from '../../Resources/Public/JavaScript/alt-text-tabs.js';

const TYPES = ['pages', 'tx_events', 'tx_news'];

/** The markup List.html renders: the first tab open. */
function render() {
    document.body.replaceChildren();
    const tablist = document.createElement('div');
    tablist.setAttribute('role', 'tablist');
    tablist.dataset.nrAltTextTabs = '';
    document.body.append(tablist);
    TYPES.forEach((type, index) => {
        const tab = document.createElement('button');
        tab.setAttribute('role', 'tab');
        tab.id = `tab-${type}`;
        tab.dataset.tabType = type;
        tab.setAttribute('aria-controls', `panel-${type}`);
        tab.setAttribute('aria-selected', index === 0 ? 'true' : 'false');
        tab.tabIndex = index === 0 ? 0 : -1;
        tab.textContent = type;
        tablist.append(tab);
        const panel = document.createElement('div');
        panel.setAttribute('role', 'tabpanel');
        panel.id = `panel-${type}`;
        panel.hidden = index !== 0;
        document.body.append(panel);
    });
    initAltTextTabs(tablist);

    return {
        tablist,
        tab: (type) => document.getElementById(`tab-${type}`),
        panel: (type) => document.getElementById(`panel-${type}`),
    };
}

function press(element, key) {
    const event = new KeyboardEvent('keydown', {key, bubbles: true, cancelable: true});
    element.dispatchEvent(event);

    return event;
}

/** Which tab is open, checked on every tab and panel. */
function open(view) {
    const selected = TYPES.filter((type) => view.tab(type).getAttribute('aria-selected') === 'true');
    for (const type of TYPES) {
        const isOpen = selected.includes(type);
        expect(view.tab(type).tabIndex).toBe(isOpen ? 0 : -1);
        expect(view.panel(type).hidden).toBe(!isOpen);
    }

    return selected;
}

describe('the tabs of the missing alt texts list', () => {
    test('a click opens the tab and hides the others', () => {
        const view = render();

        view.tab('tx_events').click();

        expect(open(view)).toEqual(['tx_events']);
    });

    test('the right and left arrows move to the next and previous tab and wrap around', () => {
        const view = render();
        view.tab('pages').focus();

        press(view.tab('pages'), 'ArrowRight');
        expect(open(view)).toEqual(['tx_events']);
        expect(document.activeElement).toBe(view.tab('tx_events'));

        press(view.tab('tx_events'), 'ArrowLeft');
        press(view.tab('pages'), 'ArrowLeft');
        expect(open(view)).toEqual(['tx_news']);
        expect(document.activeElement).toBe(view.tab('tx_news'));

        press(view.tab('tx_news'), 'ArrowRight');
        expect(open(view)).toEqual(['pages']);
    });

    test('home and end jump to the first and the last tab', () => {
        const view = render();

        press(view.tab('pages'), 'End');
        expect(open(view)).toEqual(['tx_news']);

        press(view.tab('tx_news'), 'Home');
        expect(open(view)).toEqual(['pages']);
        expect(document.activeElement).toBe(view.tab('pages'));
    });

    test('a handled key does not scroll the page, any other key is left alone', () => {
        const view = render();

        expect(press(view.tab('pages'), 'ArrowDown').defaultPrevented).toBe(true);
        expect(press(view.tab('tx_events'), 'Tab').defaultPrevented).toBe(false);
        expect(open(view)).toEqual(['tx_events']);
    });
});
