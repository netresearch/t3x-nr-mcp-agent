// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {css} from 'lit';

/**
 * Shared color-scheme mapping for the chat UI.
 *
 * Defines semantic --nr-chat-* custom properties on :host, resolved from
 * TYPO3 v14 backend tokens (light-dark() aware, following the active
 * data-color-scheme) with TYPO3 v13 token names and plain light-mode
 * literals as fallbacks. CSS custom properties inherit into Lit shadow
 * DOM, so both components adapt to the backend color scheme without any
 * JavaScript scheme detection.
 *
 * Shared between ai-chat-panel.js and chat-app.js.
 */
export const themeStyles = css`
    :host {
        /* Surfaces */
        --nr-chat-surface: var(--typo3-surface-container-lowest, #fff);
        --nr-chat-surface-low: var(--typo3-surface-container-low, #f5f5f5);
        --nr-chat-surface-base: var(--typo3-surface-container-base, var(--typo3-surface-container, #f0f0f0));
        --nr-chat-surface-high: var(--typo3-surface-container-high, #e8e8e8);

        /* Text */
        --nr-chat-text: var(--typo3-text-color-base, var(--typo3-text-color, #333));
        --nr-chat-text-variant: var(--typo3-text-color-variant, #666);
        --nr-chat-link: var(--typo3-component-link-color, var(--typo3-text-color-primary, #0078d4));

        /* Borders and interaction states */
        --nr-chat-border: var(--typo3-component-border-color, var(--typo3-list-border-color, #ccc));
        --nr-chat-input-border: var(--typo3-input-border-color, #ccc);
        --nr-chat-hover: var(--typo3-state-default-hover-bg, var(--typo3-state-hover, rgba(0, 0, 0, 0.04)));
        --nr-chat-active: var(--typo3-component-active-bg, var(--typo3-state-active, rgba(0, 0, 0, 0.08)));
        --nr-chat-on-active: var(--typo3-component-active-color, #fff);

        /* Primary accent (send button, user bubble, assistant avatar) */
        --nr-chat-accent: var(--typo3-surface-primary, var(--typo3-primary, #0078d4));
        --nr-chat-on-accent: var(--typo3-surface-primary-text, #fff);
        --nr-chat-accent-hover: var(--typo3-state-primary-hover-bg, color-mix(in srgb, var(--nr-chat-accent) 85%, black));
        --nr-chat-focus-ring: var(--typo3-text-color-primary, var(--typo3-primary, #0078d4));
        --nr-chat-focus: var(--nr-chat-focus-ring);
        --nr-chat-surface-variant: var(--typo3-surface-container-low, #f4f4f4);

        /*
         * Context chips above the input (NEXT-221): the specification's light
         * values are the fallbacks; the backend tokens follow the colour
         * scheme, so the chips do too.
         */
        --nr-chat-chip-page-bg: var(--typo3-surface-container-base, #f2f2f4);
        --nr-chat-chip-page-text: var(--typo3-text-color-base, #3a3a40);
        --nr-chat-chip-element-bg: var(--typo3-surface-container-lowest, #fff);
        --nr-chat-chip-element-ink: var(--typo3-text-color-base, #000);
        /*
         * The element marker: a black circle with a dot in the accent, the
         * same in both schemes, as the accent only ever sits on black. The
         * primitive token does not follow the scheme; the accent has no
         * backend token and can be set like the prompt widget's.
         */
        --nr-chat-chip-marker: var(--token-color-neutral-100, #000);
        --nr-chat-chip-accent: var(--nr-mcp-agent-context-accent, #dfff00);

        /* Status chips (scheme-aware background/text pairs) */
        --nr-chat-success-bg: var(--typo3-surface-container-success, #e8f5e9);
        --nr-chat-success-text: var(--typo3-surface-container-success-text, #2e7d32);
        --nr-chat-warning-bg: var(--typo3-surface-container-warning, #fff3e0);
        --nr-chat-warning-text: var(--typo3-surface-container-warning-text, #e65100);
        --nr-chat-warning-border: color-mix(in srgb, var(--nr-chat-warning-text) 30%, transparent);
        --nr-chat-info-bg: var(--typo3-surface-container-info, #e3f2fd);
        --nr-chat-info-text: var(--typo3-surface-container-info-text, #0d47a1);
        --nr-chat-danger-bg: var(--typo3-surface-container-danger, #ffebee);
        --nr-chat-danger-text: var(--typo3-surface-container-danger-text, #c62828);
        /*
         * The neutral status: core's default badge pair. Not the variant text
         * colour: TYPO3 14.3 defines --typo3-text-color-variant as a mix of
         * currentColor, so on a badge of its own background it took the
         * colour of the row around it, white in the selected row (1.04:1).
         */
        --nr-chat-neutral-bg: var(--typo3-badge-default-bg, #f7f7f8);
        --nr-chat-neutral-text: var(--typo3-badge-default-color, #1a1a1a);
        /* The edge of a waiting status: the warning text colour, 3:1 or more against the rows. */
        --nr-chat-warning-edge: var(--nr-chat-status-warning);

        /* Plain status text on default surfaces */
        --nr-chat-status-info: var(--typo3-text-color-info, #1565c0);
        --nr-chat-status-success: var(--typo3-text-color-success, #2e7d32);
        --nr-chat-status-warning: var(--typo3-text-color-warning, #8a5300);
        --nr-chat-status-danger: var(--typo3-text-color-danger, #c62828);

        color: var(--nr-chat-text);
    }
`;
