/**
 * Tests for the color-scheme handling of the chat UI.
 *
 * The TYPO3 v14 backend supports light AND dark color schemes via
 * light-dark()-aware --typo3-* custom properties. Any hardcoded color
 * that is not a fallback inside var()/color-mix() renders wrong in one
 * of the two schemes. These tests scan the style sources and fail when
 * a bare color literal sneaks back in (regression guard), and verify
 * the shared theme contract used by both Lit components.
 *
 * Static source analysis is used instead of rendering because the Lit
 * components resolve 'lit' via the TYPO3 backend importmap, which is
 * not available under Jest.
 */

import {describe, test, expect} from '@jest/globals';
import {readFileSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const jsDir = join(dirname(fileURLToPath(import.meta.url)), '../../Resources/Public/JavaScript');

const STYLE_SOURCES = [
    'ai-chat-panel.js',
    'chat-activity.js',
    'chat-app.js',
    'chat-core.js',
    'chat-editing.js',
    'conversation-tabs.js',
    'dashboard-widget.js',
    'markdown-styles.js',
    'theme.js',
    'toolbar/chat-panel.js',
];

const COLOR_LITERAL = /#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)/;

const read = (file) => readFileSync(join(jsDir, file), 'utf8');

/**
 * Remove every `var(...)` and `color-mix(...)` call, parentheses balanced, so
 * that only what a line sets OUTSIDE of them is left. A literal inside those is
 * a fallback and allowed; one outside is a colour that ignores the scheme.
 *
 * The previous guard skipped any line that merely CONTAINED `var(--`, which let
 * `background: var(--nr-chat-surface-high); color: #555;` through: the
 * background followed the scheme and the text colour on the same line did not.
 */
function stripSchemeAwareCalls(line) {
    let out = line;
    for (;;) {
        const match = /(?:var|color-mix)\(/.exec(out);
        if (!match) {
            return out;
        }
        let depth = 0;
        let end = match.index + match[0].length - 1;
        for (; end < out.length; end++) {
            if (out[end] === '(') depth++;
            if (out[end] === ')' && --depth === 0) break;
        }
        out = out.slice(0, match.index) + out.slice(end + 1);
    }
}

describe('color-scheme safety (no bare color literals)', () => {
    test.each(STYLE_SOURCES)('%s uses color literals only as var()/color-mix() fallbacks', (file) => {
        const offending = read(file)
            .split('\n')
            .map((line, idx) => ({line, no: idx + 1}))
            // A shadow darkens whatever is below it in both schemes; it is not a
            // colour a reader has to tell apart from its background.
            .filter(({line}) => !/^\s*box-shadow:/.test(line))
            .filter(({line}) => COLOR_LITERAL.test(stripSchemeAwareCalls(line)));
        expect(offending.map(({no, line}) => `${file}:${no}: ${line.trim()}`)).toEqual([]);
    });

    test('the guard catches a literal that shares its line with a var()', () => {
        expect(COLOR_LITERAL.test(stripSchemeAwareCalls('.a { background: var(--x); color: #555; }'))).toBe(true);
        expect(COLOR_LITERAL.test(stripSchemeAwareCalls('.a { color: var(--x, var(--y, #555)); }'))).toBe(false);
    });
});

describe('color-scheme safety (no opacity to mute text)', () => {
    /**
     * `opacity` mixes text with whatever is behind it, so a token that clears
     * 4.5:1 drops below it: the empty-state hint measured 3.95:1 on the demo at
     * `opacity: .85`. Muted text takes --nr-chat-text-variant instead. What stays
     * allowed is what WCAG exempts or what carries no text: disabled controls and
     * animation keyframes.
     */
    test.each(STYLE_SOURCES)('%s uses opacity only on disabled controls and in keyframes', (file) => {
        const offending = [];
        for (const [, selector, body] of read(file).matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
            if (!/(^|;|\s)opacity\s*:/.test(body)) {
                continue;
            }
            const sel = selector.trim();
            if (/:disabled/.test(sel) || /^[\d%,\s]+$|^(from|to)$/.test(sel)) {
                continue;
            }
            offending.push(`${file}: ${sel.split('\n').pop().trim()}`);
        }
        expect(offending).toEqual([]);
    });
});

describe('shared theme contract', () => {
    const theme = read('theme.js');

    test.each([
        '--nr-chat-surface',
        '--nr-chat-surface-low',
        '--nr-chat-surface-base',
        '--nr-chat-surface-high',
        '--nr-chat-text',
        '--nr-chat-text-variant',
        '--nr-chat-link',
        '--nr-chat-border',
        '--nr-chat-input-border',
        '--nr-chat-hover',
        '--nr-chat-active',
        '--nr-chat-accent',
        '--nr-chat-on-accent',
        '--nr-chat-accent-hover',
        '--nr-chat-focus-ring',
        '--nr-chat-success-bg',
        '--nr-chat-success-text',
        '--nr-chat-warning-bg',
        '--nr-chat-warning-text',
        '--nr-chat-danger-bg',
        '--nr-chat-danger-text',
        '--nr-chat-status-info',
        '--nr-chat-status-success',
        '--nr-chat-status-warning',
        '--nr-chat-status-danger',
    ])('theme.js defines %s', (property) => {
        expect(theme).toContain(`${property}:`);
    });

    test('theme maps the accent to the scheme-aware TYPO3 primary surface tokens', () => {
        expect(theme).toContain('--nr-chat-accent: var(--typo3-surface-primary,');
        expect(theme).toContain('--nr-chat-on-accent: var(--typo3-surface-primary-text,');
    });

    test('theme maps status chips to scheme-aware surface-container pairs', () => {
        expect(theme).toContain('var(--typo3-surface-container-success,');
        expect(theme).toContain('var(--typo3-surface-container-warning,');
        expect(theme).toContain('var(--typo3-surface-container-danger,');
    });

    test.each(['ai-chat-panel.js', 'chat-app.js'])('%s applies themeStyles before its own styles', (file) => {
        const source = read(file);
        expect(source).toContain("import {themeStyles} from './theme.js';");
        expect(source).toContain('static styles = [themeStyles, markdownStyles,');
    });

    /**
     * A --nr-chat-* property that theme.js does not declare always resolves to
     * the literal in its var() fallback, in both schemes: --nr-chat-status-warning
     * was used four times and declared nowhere, so the warnings were #ef6c00
     * (3.08:1 on white) and #8a5300 (about 2.6:1 on the dark surface).
     */
    test('every --nr-chat-* property used anywhere is declared in theme.js', () => {
        const declared = new Set([...theme.matchAll(/(--nr-chat-[a-z0-9-]+)\s*:/g)].map(([, name]) => name));
        const undeclared = [];
        for (const file of STYLE_SOURCES) {
            for (const [, name] of read(file).matchAll(/var\((--nr-chat-[a-z0-9-]+)/g)) {
                if (!declared.has(name)) {
                    undeclared.push(`${file}: ${name}`);
                }
            }
        }
        expect(undeclared).toEqual([]);
    });

    /**
     * The approval rules were once inserted in the middle of this selector list,
     * so "processing" and "tool_loop" tab icons lost their status colour and took
     * `.approval-card`'s margin-top instead.
     */
    test.each(['processing', 'tool_loop', 'awaiting_approval', 'locked'])(
        'the %s tab icon takes the status info colour',
        (status) => {
            const rule = [...read('ai-chat-panel.js').matchAll(/([^{}]+)\{([^{}]*)\}/g)]
                .find(([, selector]) => selector.includes(`.conv-tab .tab-icon.status-${status}`));
            expect(rule?.[2].trim()).toBe('color: var(--nr-chat-status-info);');
        },
    );

    test('status warnings map to the scheme-aware TYPO3 warning text token', () => {
        expect(theme).toContain('--nr-chat-status-warning: var(--typo3-text-color-warning,');
    });

    test('markdown link color uses the shared token', () => {
        expect(read('markdown-styles.js')).toContain('var(--nr-chat-link, #0078d4)');
    });
});
