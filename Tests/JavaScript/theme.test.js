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
import {colourLiteralsInCss, colourLiteralsInModule, takeColourGuardNotices} from './support/color-literals.js';

const jsDir = join(dirname(fileURLToPath(import.meta.url)), '../../Resources/Public/JavaScript');

const STYLE_SOURCES = [
    'ai-chat-panel.js',
    'chat-activity.js',
    'chat-app.js',
    'chat-core.js',
    'chat-editing.js',
    'conversation-tabs.js',
    'dashboard-widget.js',
    'icons.js',
    'markdown-styles.js',
    'theme.js',
    'toolbar/chat-panel.js',
];

const read = (file) => readFileSync(join(jsDir, file), 'utf8');

/**
 * The guard used to be a regular expression over source lines. Two review
 * rounds in a row found forms it missed (a hex beside a var() on the same line,
 * hsl(), named colours, SVG attributes, comment detection by a leading `*`), so
 * it now parses: @babel/parser for the modules and css-tree for the CSS in
 * them. css-tree's lexer matches each declaration against its property's
 * grammar, and only what the grammar types as `<color>` is checked — the same
 * name is a colour in `color: red` and not in `grid-area: red`.
 * See Tests/JavaScript/support/color-literals.js.
 */
describe('color-scheme safety (no bare color literals)', () => {
    test.each(STYLE_SOURCES)('%s uses colour literals only as var() fallbacks', (file) => {
        takeColourGuardNotices();
        expect(colourLiteralsInModule(read(file)).map((hit) => `${file}:${hit}`)).toEqual([]);
        // Every value in our own sources matches css-tree's grammar; none was only scanned.
        expect(takeColourGuardNotices()).toEqual([]);
    });

    const css = (text) => colourLiteralsInCss(text).length > 0;
    const js = (text) => colourLiteralsInModule(text).length > 0;

    test.each([
        ['a hex literal beside a var()', '.a { background: var(--x); color: #555; }'],
        ['rgb()', '.a { color: rgb(85 85 85); }'],
        ['rgba()', '.a { color: rgba(0, 0, 0, .6); }'],
        ['hsl()', '.a { color: hsl(0 0% 33%); }'],
        ['hsla()', '.a { color: hsla(0, 0%, 33%, 1); }'],
        ['hwb()', '.a { color: hwb(0 20% 20%); }'],
        ['oklch()', '.a { color: oklch(45% 0 0); }'],
        ['color()', '.a { color: color(srgb 0.3 0.3 0.3); }'],
        ['a named colour', '.a { color: gray; }'],
        ['a named colour in a shorthand', '.a { border: 1px solid black; }'],
        ['a named colour in a custom property', ':host { --nr-chat-foo: white; }'],
        ['a hex in a custom property', ':host { --x: #555; }'],
        ['a text-shadow', '.a { text-shadow: 0 1px 0 #fff; }'],
        ['a gradient', '.a { background: linear-gradient(white, var(--x)); }'],
        ['scrollbar-color', '.a { scrollbar-color: gray transparent; }'],
        ['a color-mix() of two literals', '.a { color: color-mix(in srgb, #555 50%, white); }'],
        ['a literal mixed with currentColor', '.a { color: color-mix(in srgb, currentColor 50%, #555); }'],
        ['light-dark() of literals', '.a { color: light-dark(#333, #eee); }'],
        ['a colour beside an exempt box-shadow', '.a { box-shadow: 0 0 4px rgb(0 0 0 / 20%); color: #555; }'],
        ['a box-shadow ring without blur', '.a { box-shadow: 0 0 0 2px #000; }'],
        ['an inset box-shadow ring', '.a { box-shadow: inset 0 0 0 2px black; }'],
        ['a hard layer in a multi-layer box-shadow', '.a { box-shadow: 0 4px 24px rgb(0 0 0 / 18%), 0 0 0 1px #ccc; }'],
        ['a -webkit-box-shadow ring', '.a { -webkit-box-shadow: 0 0 0 1px #000; }'],
        ['a literal as an env() fallback', '.a { color: env(x, red); }'],
        ['a deprecated system colour', '.a { background: ThreeDFace; }'],
        ['another deprecated system colour', '.a { color: WindowText; }'],
        ['a relative colour from a literal', '.a { color: rgb(from white r g b / 50%); }'],
        ['a star-hack property', '.a { *color: #555; }'],
        ['a rule inside @keyframes', '@keyframes k { from { color: red; } }'],
    ])('the CSS check catches %s', (_label, text) => {
        expect(css(text)).toBe(true);
    });

    test.each([
        ['a literal as a var() fallback', '.a { color: var(--x, var(--y, #555)); }'],
        ['a named colour as a var() fallback', '.a { color: var(--x, black); }'],
        ['a color-mix() over currentColor and transparent', '.a { border: 1px solid color-mix(in srgb, currentColor 15%, transparent); }'],
        ['a soft box-shadow', '.a { box-shadow: -4px 0 12px rgb(0 0 0 / 20%); }'],
        ['a focus ring from the token', '.a { box-shadow: 0 0 0 1px var(--nr-chat-focus-ring); }'],
        ['white-space', '.a { white-space: nowrap; }'],
        ['transparent and currentColor', '.a { background: transparent; color: currentColor; }'],
        ['a system colour', '.a { color: CanvasText; }'],
        ['a current system colour named like a control', '.a { background: ButtonFace; color: ButtonText; }'],
        ['a hex in an attribute selector', '[data-x="#555"] { color: var(--x); }'],
        ['a CSS comment', '/* was color: #555; */ .a { color: var(--x); }'],
        ['an animation name that is not a colour', '.a { animation: spin 1s linear infinite; }'],
        ['a property named in a transition', '.a { transition: background 0.15s, color 0.15s; }'],
    ])('the CSS check lets %s through', (_label, text) => {
        expect(css(text)).toBe(false);
    });

    test.each([
        ['a hex in a Lit css`` block', 'const s = css`.a { color: #555; }`;'],
        ['a named colour in a style attribute', 'const t = html`<div style="color:white;"></div>`;'],
        ['an upper-case style attribute', 'const t = html`<div style="COLOR:WHITE"></div>`;'],
        ['an interpolated literal in a style attribute', "const t = html`<div style=\"color:${ok ? 'var(--a)' : '#555'};\"></div>`;"],
        ['an SVG fill hex', 'const i = html`<svg><path fill="#000" d="M0"/></svg>`;'],
        ['an SVG fill named', 'const i = html`<svg><path fill="black" d="M0"/></svg>`;'],
        ['an SVG stroke named', 'const i = html`<svg><path stroke="white" d="M0"/></svg>`;'],
        ['a single-quoted SVG fill', "const i = html`<svg><path fill='black'/></svg>`;"],
        ['element.style.prop', "el.style.background = 'white';"],
        ['element.style.setProperty()', "el.style.setProperty('color', 'white');"],
        ['element.style.cssText', "el.style.cssText = 'color: #555';"],
        ['Object.assign(element.style)', "Object.assign(el.style, {backgroundColor: 'rgb(0 0 0)'});"],
        ['setAttribute(style)', "el.setAttribute('style', 'color: red');"],
        ['setAttribute(fill)', "el.setAttribute('fill', 'black');"],
        ['setAttribute(stroke)', "el.setAttribute('stroke', '#333');"],
        ['a <style> element in html``', 'const t = html`<style>.a { color: red; }</style>`;'],
        ['unsafeCSS()', "import {css, unsafeCSS} from 'lit';\nconst s = css`${unsafeCSS('.a { color: #555; }')}`;"],
        ['an aliased css tag', "import {css as litCss} from 'lit';\nconst s = litCss`.a { color: #555; }`;"],
        ['a namespaced lit.css tag', "import * as lit from 'lit';\nconst s = lit.css`.a { color: #555; }`;"],
        ['replaceSync()', "sheet.replaceSync('.a{color:#555}');"],
        ['an unquoted SVG fill', 'const i = html`<path fill=black d="M0"/>`;'],
        ['an SVG stop-color', 'const i = html`<stop offset="0" stop-color="red"/>`;'],
        ['an SVG color attribute', 'const i = html`<svg color="red"><path fill="currentColor"/></svg>`;'],
        ['markup in a string assigned to innerHTML', "el.innerHTML = '<div style=\"color:red\"></div>';"],
        ['markup in an untagged template', 'const markup = `<div style="color:red"></div>`;'],
        ['a literal after an interpolated declaration', 'const t = html`<div style="${extra} color: red"></div>`;'],
    ])('the module check catches %s', (_label, source) => {
        expect(js(source)).toBe(true);
    });

    test.each([
        ['a hex in a block comment', '/*\n * color: #555;\n */\nconst a = 1;'],
        ['a hex in a line comment', '// was #555\nconst a = 1;'],
        ['an SVG using currentColor', 'const i = html`<svg fill="none" stroke="currentColor"><path d="M0"/></svg>`;'],
        ['an SVG fill from a custom property', 'const i = html`<svg><path fill="var(--nr-icon-accent, #2F99A4)"/></svg>`;'],
        ['prose that names a colour', "const label = 'Red means the run failed';"],
        ['a style from a token', "Object.assign(el.style, {background: 'var(--typo3-surface-container-lowest, #fff)'});"],
        ['a data-fill attribute', 'const t = html`<div data-fill="black"></div>`;'],
        ['an SVG fill from a gradient', 'const i = html`<path fill="url(#g)"/>`;'],
        // Interpolations outside a CSS value must still leave CSS that parses.
        ['a whole style attribute interpolated', 'const t = html`<div style="${styleMap({a: 1})}"></div>`;'],
        ['an interpolation between declarations', 'const t = html`<div style="color: var(--x); ${extra}"></div>`;'],
        ['an interpolated <style> element', 'const t = html`<style>${sheet}</style>`;'],
    ])('the module check lets %s through', (_label, source) => {
        expect(js(source)).toBe(false);
    });

    /** A module that does not parse must fail loudly, never pass as clean. */
    test.each([
        ['a syntax error', 'const = 1;'],
        // Babel can recover from this one; with error recovery on it would return
        // a tree and the module would be reported clean.
        ['a recoverable error', 'let a;\nlet a;\nconst s = css`.a { color: var(--x); }`;'],
        ['an unterminated template', 'const t = `abc'],
    ])('the module check throws on %s', (_label, source) => {
        expect(() => colourLiteralsInModule(source)).toThrow();
    });

    /**
     * Whether an identifier is a colour depends on the property: the lexer
     * matches each value against its property's grammar, so a name that is a
     * colour elsewhere is not one here.
     */
    test.each([
        ['transition', 'transition: color 0.2s;'],
        ['transition-property', 'transition-property: background;'],
        ['an upper-case transition', 'TRANSITION: Background 1s;'],
        ['-webkit-transition', '-webkit-transition: background 0.15s;'],
        ['-webkit-transition-property', '-webkit-transition-property: background;'],
        ['will-change', 'will-change: background-color;'],
        ['grid-area', 'grid-area: red;'],
        ['animation', 'animation: red 1s linear;'],
        ['animation-name', 'animation-name: tomato;'],
        ['an animation named like a deprecated system colour', 'animation-name: Background;'],
        ['a system font', 'font: menu;'],
        ['view-transition-name', 'view-transition-name: red;'],
        ['counter-reset', 'counter-reset: red;'],
        ['container-name', 'container-name: menu;'],
        ['current system colours', 'color: HighlightText; background: Highlight; border-color: ButtonBorder; outline-color: GrayText; caret-color: LinkText;'],
        ['more current system colours', 'color: AccentColorText; background: AccentColor; border-color: SelectedItem;'],
    ])('the grammar does not read %s as a colour', (_label, declarations) => {
        expect(css(`.a { ${declarations} }`)).toBe(false);
    });

    test.each([
        ['a deprecated system colour in a border', 'border-color: ButtonHighlight;'],
        ['a deprecated system colour in mixed case', 'background: threedFACE;'],
        ['a deprecated system colour inside light-dark()', 'color: light-dark(WindowText, CanvasText);'],
        ['a literal mixed with a system colour', 'color: color-mix(in srgb, Canvas 50%, red);'],
        ['-webkit-focus-ring-color', 'outline-color: -webkit-focus-ring-color;'],
        ['a box-shadow ring with a hairline blur', 'box-shadow: 0 0 0.01px 2px red;'],
        ['a box-shadow ring with a var() blur', 'box-shadow: 0 0 var(--b) 2px red;'],
        ['a box-shadow ring with a calc() blur', 'box-shadow: inset 0 0 calc(0px) 2px red;'],
        ['a box-shadow without blur', 'box-shadow: 2px 2px red;'],
        ['a hard text-shadow', 'text-shadow: 1px 1px 0 red;'],
        ['a drop-shadow() filter', 'filter: drop-shadow(0 0 2px red);'],
        ['a literal beside a shadow held in a var()', 'box-shadow: var(--shadow), 0 0 0 1px red;'],
    ])('the grammar finds %s', (_label, declarations) => {
        expect(css(`.a { ${declarations} }`)).toBe(true);
    });

    test('an @property initial value is scanned as a colour', () => {
        expect(css("@property --x { syntax: '<color>'; inherits: false; initial-value: red; }")).toBe(true);
    });

    /** A value that does not match its property's grammar is reported, not skipped. */
    test.each([
        ['CSS that does not parse', '.a { color: red; } }}} {'],
    ])('the CSS check throws on %s', (_label, text) => {
        expect(() => colourLiteralsInCss(text)).toThrow(/colour guard/);
    });

    /**
     * A value that parses but matches no grammar is scanned for colours instead
     * of throwing, and a notice names it. calc-size() is newer than css-tree
     * 3.2.1's grammar; `linear(0, red)` is invalid CSS, because linear() takes
     * numbers and percentages and `red` is neither.
     */
    test.each([
        ['calc-size()', '.a { height: calc-size(auto, size); }', []],
        ['a value outside the grammar with a colour in it', '.a { transition: color 1s linear(0, red); }', ['transition: red']],
    ])('a value css-tree cannot match (%s) is scanned and noticed, not thrown', (_label, text, found) => {
        takeColourGuardNotices();
        expect(colourLiteralsInCss(text)).toEqual(found);
        expect(takeColourGuardNotices()).toHaveLength(1);
    });

    test('contrast-color() over a custom property is scanned without a finding', () => {
        expect(colourLiteralsInCss('.a { color: contrast-color(var(--bg)); }')).toEqual([]);
    });

    /**
     * Values built only from custom properties: the channels, or the whole
     * value, come from var(). None of them is a literal, and none may throw.
     */
    test.each([
        ['rgb() of one custom property', '.a { color: rgb(var(--rgb)); }'],
        ['rgba() of a custom property and an alpha', '.a { color: rgba(var(--rgb), .5); }'],
        ['a Bootstrap-style background', '.a { background: rgb(var(--bs-body-bg-rgb)); }'],
        ['rgb() of three custom properties', '.a { color: rgb(var(--r) var(--g) var(--b)); }'],
        ['oklch() of three custom properties', '.a { color: oklch(var(--l) var(--c) var(--h)); }'],
        ['hsl() of three custom properties', '.a { color: hsl(var(--h) var(--s) var(--l)); }'],
        ['a transition made of custom properties', '.a { transition: var(--p) var(--d) var(--e) var(--dl); }'],
        ['an animation made of custom properties', '.a { animation: var(--n) var(--d) var(--e) var(--i); }'],
    ])('the CSS check lets %s through', (_label, text) => {
        takeColourGuardNotices();
        expect(colourLiteralsInCss(text)).toEqual([]);
        expect(takeColourGuardNotices()).toEqual([]);
    });

    test.each([
        ['hsl() with a fixed saturation and lightness', '.a { color: hsl(var(--h) 50% 40%); }', 'color: hsl(var(--h) 50% 40%)'],
        ['rgb() with two fixed channels', '.a { border: 1px solid rgb(var(--r) 0 0); }', 'border: rgb(var(--r) 0 0)'],
    ])('the CSS check reports %s as written in the source', (_label, text, finding) => {
        expect(colourLiteralsInCss(text)).toEqual([finding]);
    });

    /**
     * One rule on both paths: a colour function is a literal when at least one
     * colour channel is written in the source. An alpha alone does not decide
     * the colour, and a calc() of custom properties is still a custom property.
     * The grammar path is a `color` declaration; the free scan runs on a custom
     * property, which has no grammar, and on a value that matches none.
     */
    const COLOUR_FUNCTIONS = [
        ['rgb() with a channel computed from a literal', 'rgb(calc(var(--r) + 10) var(--g) var(--b))', true],
        ['hsl() with a fixed lightness and a computed saturation', 'hsl(var(--h) calc(var(--s) * 1%) 40%)', true],
        ['hsl() with a fixed lightness', 'hsl(var(--h) var(--s) 40%)', true],
        ['hsl() with fixed channels after one custom property', 'hsl(var(--hs) 40%)', true],
        ['legacy hsl() with a fixed channel after one custom property', 'hsl(var(--hs), 40%)', true],
        ['color() with one fixed channel', 'color(srgb var(--r) 0.5 var(--b))', true],
        ['rgb() of calc()s of custom properties', 'rgb(calc(var(--r)) calc(var(--g)) calc(var(--b)))', false],
        ['rgb() with one calc() of a custom property', 'rgb(calc(var(--r)) var(--g) var(--b))', false],
        ['rgb() with a computed alpha', 'rgb(var(--r) var(--g) var(--b) / calc(var(--a)))', false],
        ['rgb() with a literal alpha only', 'rgb(var(--r) var(--g) var(--b) / 50%)', false],
        ['rgb() of one custom property with a literal alpha', 'rgb(var(--rgb) / 50%)', false],
        ['legacy rgba() with a literal alpha only', 'rgba(var(--rgb), .5)', false],
        ['legacy hsl() of four groups with a literal alpha only', 'hsl(var(--h), var(--s), var(--l), .5)', false],
        ['color() of custom properties', 'color(srgb var(--r) var(--g) var(--b))', false],
    ];
    test.each(COLOUR_FUNCTIONS)('the grammar path judges %s', (_label, colour, literal) => {
        expect(colourLiteralsInCss(`.a { color: ${colour}; }`)).toEqual(literal ? [`color: ${colour}`] : []);
    });
    test.each(COLOUR_FUNCTIONS)('the free scan judges %s', (_label, colour, literal) => {
        expect(colourLiteralsInCss(`.a { --x: ${colour}; }`)).toEqual(literal ? [`--x: ${colour}`] : []);
    });

    test.each([
        ['a border of custom properties with a fixed lightness', '.a { border: var(--w) var(--s) hsl(var(--h) var(--s2) 40%); }', ['border: hsl(var(--h) var(--s2) 40%)']],
        ['a value outside the grammar holding a fixed colour', '.a { border: var(--w) solid hsl(var(--h) 50% 40%) calc-size(auto, size); }', ['border: hsl(var(--h) 50% 40%)']],
        ['a value outside the grammar holding an alpha only', '.a { border: var(--w) solid rgb(var(--r) var(--g) var(--b) / 50%) calc-size(auto, size); }', []],
        ['a colour-mix() of a fixed hsl() and a custom property', '.a { color: color-mix(in oklch, hsl(var(--h) 50% 40%), var(--x)); }', ['color: hsl(var(--h) 50% 40%)']],
    ])('a value no stand-in fits is judged by the same rule: %s', (_label, text, found) => {
        expect(colourLiteralsInCss(text)).toEqual(found);
    });

    /** At-rule descriptors match their own grammar, not a property's. */
    test('@font-face descriptors match the descriptor grammar', () => {
        takeColourGuardNotices();
        const text = '@font-face { font-family: X; font-weight: 100 900; font-style: oblique 0deg 20deg; font-stretch: 75% 125%; }';
        expect(colourLiteralsInCss(text)).toEqual([]);
        expect(takeColourGuardNotices()).toEqual([]);
    });

    /** `symbols: red` names a symbol; only the descriptor grammar knows it is not a colour. */
    test('a counter-style symbol named like a colour is not a colour', () => {
        expect(colourLiteralsInCss('@counter-style x { system: cyclic; symbols: red; pad: 2 red; }')).toEqual([]);
    });

    test('a colour in a font palette descriptor is reported', () => {
        expect(colourLiteralsInCss('@font-palette-values --p { font-family: X; override-colors: 0 red; }')).toEqual(['override-colors: red']);
    });

    test.each([
        ['an optional call to style.setProperty()', "el?.style.setProperty('color', 'red');"],
        ['an optional call to setAttribute()', "el?.setAttribute('fill', 'red');"],
        ['Object.assign() on an optional member', "Object.assign(el?.style, {color: 'red'});"],
        ['CSSStyleSheet.replace()', "sheet.replace('.a { color: red; }');"],
        ['an unquoted hex attribute', 'const i = html`<path fill=#333 d="M0"/>`;'],
        ['a style attribute with spaces around =', 'const i = html`<rect style = "fill : red"/>`;'],
        ['a lit svg`` template', "import {svg} from 'lit';\nconst i = svg`<path stroke=\"red\"/>`;"],
        ['lit.css on a default import', "import Lit from 'lit';\nconst s = Lit.css`.a { color: red; }`;"],
    ])('the module check catches %s', (_label, source) => {
        expect(js(source)).toBe(true);
    });

    test.each([
        ['a URL with a colour parameter', "const u = '/typo3/ajax?color=red&x=1';"],
        ['prose that shows an attribute', "const help = 'Use fill=black for the icon';"],
        ['a data-color attribute', 'const t = html`<div data-color="red"></div>`;'],
        ['String.prototype.replace()', "const s = text.replace('status: red', 'x');"],
        ['a style property removed with an empty string', "el.style.right = '';"],
    ])('the module check lets %s through', (_label, source) => {
        expect(js(source)).toBe(false);
    });

    test.each([
        ['a multi-line interpolation before the literal',
            "import {css, unsafeCSS} from 'lit';\nconst s = css`\n  .a { color: ${unsafeCSS(\n    X\n  )}; }\n  .b { color: #555; }\n`;", 6],
        ['conditional options of different height',
            "import {css} from 'lit';\nconst s = css`\n  .a { ${on ? `\n    border: 0;\n  ` : ''} }\n\n  .b { color: #555; }\n`;", 7],
        ['an html`` template with a multi-line interpolation',
            "import {html} from 'lit';\nconst t = html`<div>${items.map(\n  (i) => i\n)}</div>\n<span style=\"color: red\"></span>`;", 5],
        ['a string with \\n escapes',
            "const m = '<p>\\n</p>\\n<span style=\"color:red\"></span>';", 1],
        ['a line continuation in a template',
            "import {css} from 'lit';\nconst s = css`.a {\\\n}\n.b { color: #555; }`;", 4],
        ['one property of a multi-line Object.assign()',
            "Object.assign(el.style, {\n  width: '1px',\n  color: 'red',\n});", 3],
    ])('the line of a finding survives %s', (_label, source, line) => {
        const lines = colourLiteralsInModule(source).map((hit) => Number(hit.split(':')[0]));
        expect(lines.length).toBeGreaterThan(0);
        expect(new Set(lines)).toEqual(new Set([line]));
    });

    test('a finding names the line of the literal, not the start of its css`` block', () => {
        expect(colourLiteralsInModule('const s = css`\n  .a {\n    color: #555;\n  }`;')).toEqual(['3: color: #555']);
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
    test.each(['processing', 'tool_loop', 'awaiting_approval'])(
        'the %s tab icon takes the status info colour',
        (status) => {
            const rule = [...read('ai-chat-panel.js').matchAll(/([^{}]+)\{([^{}]*)\}/g)]
                .find(([, selector]) => selector.includes(`.conv-tab .tab-icon.status-${status}`));
            expect(rule?.[2].trim()).toBe('color: var(--nr-chat-status-info);');
        },
    );

    const ruleBody = (file, selector) => [...read(file).replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)]
        .find(([, sel]) => sel.split(',').map((s) => s.trim()).includes(selector))?.[2].trim();

    /**
     * The selected conversation took core's active background (the primary
     * colour) and kept the body text colour: 2.58-2.61:1.
     */
    test.each([
        ['chat-app.js', '.conversation-item.active'],
        ['ai-chat-panel.js', '.sidebar-item.active'],
    ])('%s gives %s the active text colour with the active background', (file, selector) => {
        expect(ruleBody(file, selector)).toBe('background: var(--nr-chat-active);\n            color: var(--nr-chat-on-active);');
        expect(theme).toContain('--nr-chat-on-active: var(--typo3-component-active-color,');
    });

    /**
     * The icon buttons in the selected panel row kept the button text colour
     * on the active background (3.12:1 in light); they take the row's colour,
     * and their hover tint is mixed from it rather than from the light hover
     * surface.
     */
    test('the icon buttons in the selected panel row follow the row colour', () => {
        expect(ruleBody('ai-chat-panel.js', '.sidebar-item.active .btn-icon')).toBe('color: inherit;');
        expect(ruleBody('ai-chat-panel.js', '.sidebar-item.active .btn-icon:hover'))
            .toBe('background: color-mix(in srgb, currentColor 15%, transparent);');
        // The browser's own ring is dark, 1.88-2.83:1 on the active background.
        expect(ruleBody('ai-chat-panel.js', '.sidebar-item.active .btn-icon:focus-visible'))
            .toBe('outline: 2px solid var(--nr-chat-on-active);\n            outline-offset: -2px;');
    });

    /**
     * --nr-chat-focus-ring and the active background resolve to the same colour
     * in the light scheme, so the focus ring on the selected row measured 1.00:1.
     * A ring in the active text colour alone fixed that and lost the outer edge
     * instead: light against the light sidebar, 1.06:1. Two colours, both inside
     * the row: the ring colour outside, the active text colour within.
     */
    test.each([
        ['chat-app.js', '.conversation-item.active:focus-visible'],
        ['ai-chat-panel.js', '.sidebar-item.active:focus-visible'],
    ])('%s draws a two-colour focus indicator on %s', (file, selector) => {
        expect(ruleBody(file, selector)).toBe([
            'outline: 2px solid var(--nr-chat-on-active);',
            'outline-offset: -4px;',
            'box-shadow: inset 0 0 0 2px var(--nr-chat-focus-ring);',
            'border-bottom-color: var(--nr-chat-focus-ring);',
        ].join('\n            '));
    });

    /** No rule for this status left the badge at 1.17-1.98:1. */
    test.each(['chat-app.js', 'ai-chat-panel.js'])('%s pairs the awaiting-approval badge colours', (file) => {
        expect(ruleBody(file, '.status-badge.status-awaiting_approval'))
            .toBe('background: var(--nr-chat-info-bg); color: var(--nr-chat-info-text);');
        expect(theme).toContain('--nr-chat-info-bg: var(--typo3-surface-container-info,');
        expect(theme).toContain('--nr-chat-info-text: var(--typo3-surface-container-info-text,');
    });

    test('status warnings map to the scheme-aware TYPO3 warning text token', () => {
        expect(theme).toContain('--nr-chat-status-warning: var(--typo3-text-color-warning,');
    });

    test('markdown link color uses the shared token', () => {
        expect(read('markdown-styles.js')).toContain('var(--nr-chat-link, #0078d4)');
    });
});
