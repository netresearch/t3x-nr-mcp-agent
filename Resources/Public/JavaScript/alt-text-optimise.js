// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {findPanel} from '@netresearch/nr-mcp-agent/chat-panel-registry.js';
import {format} from '@netresearch/nr-mcp-agent/text-format.js';

/**
 * "Optimieren" in the list of missing alt texts (ADR-026).
 *
 * The list runs in the module frame; the chat panel belongs to the backend
 * frame around it (ADR-011) and is found through chat-panel-registry.js. A
 * click opens the chat as the column beside the list, on the row's image
 * (ai-chat-panel.js, optimiseAltText()). The panel says what happens through
 * the event `nr-mcp-agent:alt-text` on itself; this module follows it:
 *
 * - `active`: the row the chat is on is highlighted (`aria-current`) and its
 *   button reads "Im Chat"; a row in another tab shows that tab, a row on
 *   another page of the tab loads that page;
 * - `done` and `gone`: the row leaves the list, the counts follow, and a
 *   live region says so;
 * - `closed`: the highlight goes, and the keyboard focus goes back to the
 *   row's button.
 *
 * Where the panel is not available, the chat module opens with the image.
 * A button marked aria-disabled names its reason (aria-describedby) and
 * does nothing.
 */

export const EVENT = 'nr-mcp-agent:alt-text';

/**
 * @param {HTMLElement} root the list (`[data-nr-alt-texts]`)
 * @param {() => object|null} [panelOf] where the chat panel is found
 * @param {(url: string) => void} [navigate] how the frame loads another URL
 */
function buttonOf(row) {
    return row?.querySelector('[data-nr-alt-text-optimise]') ?? null;
}

function setLabel(button, activeLabel) {
    const label = button?.querySelector('[data-nr-alt-text-label]');
    if (label) {
        label.textContent = activeLabel ? button.dataset.labelActive : button.dataset.label;
    }
}

/** The heading for the number of open images: none, one or the count. */
function headingText(heading, remaining) {
    if (remaining === 0) {
        return heading.dataset.titleNone;
    }
    return remaining === 1 ? heading.dataset.titleOne : format(heading.dataset.titleOther, remaining);
}

export function initAltTextOptimise(root, panelOf = findPanel, navigate = (url) => root.ownerDocument.location.assign(url)) {
    const doc = root.ownerDocument;
    const status = root.querySelector('[data-nr-alt-text-status]');
    const heading = doc.querySelector('[data-nr-alt-text-heading]');
    let active = 0;
    let panel = null;

    function rowOf(reference) {
        return root.querySelector(`[data-nr-alt-text-row="${Number(reference)}"]`);
    }

    function highlight(reference) {
        for (const row of root.querySelectorAll('[data-nr-alt-text-row]')) {
            const current = Number(row.dataset.nrAltTextRow) === reference;
            row.classList.toggle('nr-alt-texts-active', current);
            if (current) {
                row.setAttribute('aria-current', 'true');
            } else {
                row.removeAttribute('aria-current');
            }
            setLabel(buttonOf(row), current);
        }
        active = reference;
    }

    /** Show the tab the row is in. */
    function showTabOf(row) {
        const panelElement = row.closest('[role="tabpanel"]');
        if (panelElement?.hidden) {
            doc.getElementById(panelElement.getAttribute('aria-labelledby') ?? '')?.click();
        }
    }

    function announce(text) {
        if (status) {
            status.textContent = text;
        }
    }

    /**
     * The heading and the tab's count after a row left the list. The heading
     * counts every open image (`data-count`), not the rows of this page.
     */
    function updateCounts(tabPanel) {
        const remaining = Math.max(0, (Number.parseInt(heading?.dataset.count ?? '', 10) || 0) - 1);
        if (heading) {
            heading.dataset.count = String(remaining);
            heading.textContent = headingText(heading, remaining);
        }
        if (!tabPanel) {
            return;
        }
        const tab = doc.getElementById(tabPanel.getAttribute('aria-labelledby') ?? '');
        const badge = tab?.querySelector('.badge');
        const count = Math.max(0, Number.parseInt(badge?.querySelector('[aria-hidden="true"]')?.textContent ?? '0', 10) - 1);
        if (badge) {
            badge.querySelector('[aria-hidden="true"]').textContent = String(count);
            const text = badge.querySelector('.visually-hidden');
            if (text) {
                text.textContent = format(root.querySelector('[data-count-pattern]')?.dataset.countPattern, count);
            }
        }
        if (tabPanel.querySelectorAll('[data-nr-alt-text-row]').length === 0) {
            const empty = doc.createElement('p');
            empty.textContent = tabPanel.dataset.emptyText ?? '';
            tabPanel.replaceChildren(empty);
        }
    }

    function remove(reference) {
        const row = rowOf(reference);
        if (!row) {
            return;
        }
        const tabPanel = row.closest('[role="tabpanel"]');
        const group = row.closest('tbody');
        const file = row.dataset.fileName ?? '';
        row.remove();
        if (group?.querySelectorAll('[data-nr-alt-text-row]').length === 0) {
            group.remove();
        }
        updateCounts(tabPanel);
        announce(format(globalThis.TYPO3?.lang?.['status.done'] ?? '%1$s: %2$s', file, heading?.textContent ?? ''));
        if (active === reference) {
            active = 0;
        }
    }

    /** Load the page of the list the row is on, keeping the chat on it. */
    function goTo(detail) {
        const target = new URL(root.dataset.listUri ?? '', doc.baseURI);
        if (detail.tab) {
            target.searchParams.set('tab', String(detail.tab));
        }
        if (detail.page) {
            target.searchParams.set('page', String(detail.page));
        }
        navigate(target.toString());
    }

    function onEvent(event) {
        const detail = event.detail ?? {};
        if (detail.version !== 1) {
            return;
        }
        const reference = Number(detail.reference) || 0;
        if (detail.type === 'active') {
            const row = rowOf(reference);
            if (reference > 0 && !row && detail.tab) {
                goTo(detail);
                return;
            }
            if (row) {
                showTabOf(row);
            }
            highlight(reference);
        } else if (detail.type === 'done' || detail.type === 'gone') {
            remove(reference);
        } else if (detail.type === 'closed') {
            const button = buttonOf(rowOf(reference));
            highlight(0);
            button?.focus();
        }
    }

    function listen() {
        const found = panelOf();
        if (!found || found === panel || typeof found.addEventListener !== 'function') {
            return found;
        }
        panel = found;
        panel.addEventListener(EVENT, onEvent);
        // Opened again on a page of the list the chat already works on.
        highlight(Number(panel.activeAltTextReference) || 0);
        return panel;
    }

    root.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-nr-alt-text-optimise], [data-nr-alt-text-optimise-disabled]') : null;
        if (!button || 'nrAltTextOptimiseDisabled' in button.dataset) {
            return;
        }
        const reference = Number.parseInt(button.dataset.nrAltTextOptimise ?? '', 10);
        const found = listen();
        if (typeof found?.optimiseAltText !== 'function') {
            const target = new URL(root.dataset.chatUri ?? '', doc.baseURI);
            target.searchParams.set('altTextReference', String(reference));
            navigate(target.toString());
            return;
        }
        highlight(reference);
        found.optimiseAltText(reference).then((result) => {
            if (!result?.ok && result?.code !== 'done') {
                highlight(Number(found.activeAltTextReference) || 0);
                announce(result?.error ?? '');
            }
        }, () => highlight(0));
    });

    globalThis.addEventListener('pagehide', () => panel?.removeEventListener(EVENT, onEvent));
    listen();

    return {highlight, remove};
}

for (const root of document.querySelectorAll('[data-nr-alt-texts]')) {
    initAltTextOptimise(root);
}
