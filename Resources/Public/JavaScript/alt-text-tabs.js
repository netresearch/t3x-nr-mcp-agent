// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * The tabs of the list "Fehlende Alt-Texte" (ADR-026), after the WAI-ARIA
 * tabs pattern with automatic activation: one tab is in the tab order, the
 * arrow keys move between the tabs and show each, Home and End jump to the
 * first and the last. Each panel's page links carry its tab (`tab`), so
 * paging stays on it.
 *
 * Written here rather than taken from the core: 13.4 builds its tabs on
 * Bootstrap (@typo3/backend/tabs.js), 14.3 has its own @typo3/backend/tab.js
 * and warns about the old module. 14.3's module also listens on the whole
 * document and moves the focus for the same keys (without showing the tab);
 * it focuses the tab this module just showed, so the two agree.
 */

const KEYS = ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'];

/**
 * @param {HTMLElement} tablist an element with role="tablist"
 */
export function initAltTextTabs(tablist) {
    const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
    const doc = tablist.ownerDocument;

    function select(tab, focus) {
        for (const other of tabs) {
            const selected = other === tab;
            other.setAttribute('aria-selected', selected ? 'true' : 'false');
            other.tabIndex = selected ? 0 : -1;
            other.classList.toggle('active', selected);
            const panel = doc.getElementById(other.getAttribute('aria-controls') ?? '');
            if (panel) {
                panel.hidden = !selected;
            }
        }
        if (focus) {
            tab.focus();
        }
    }

    tablist.addEventListener('click', (event) => {
        const tab = event.target instanceof Element ? event.target.closest('[role="tab"]') : null;
        if (tab && tabs.includes(tab)) {
            select(tab, false);
        }
    });

    tablist.addEventListener('keydown', (event) => {
        if (!KEYS.includes(event.key)) {
            return;
        }
        const current = tabs.indexOf(event.target instanceof Element ? event.target.closest('[role="tab"]') : null);
        if (current < 0) {
            return;
        }
        const last = tabs.length - 1;
        const next = {
            ArrowRight: current === last ? 0 : current + 1,
            ArrowDown: current === last ? 0 : current + 1,
            ArrowLeft: current === 0 ? last : current - 1,
            ArrowUp: current === 0 ? last : current - 1,
            Home: 0,
            End: last,
        }[event.key];
        event.preventDefault();
        select(tabs[next], true);
    });
}

for (const tablist of document.querySelectorAll('[data-nr-alt-text-tabs]')) {
    initAltTextTabs(tablist);
}
