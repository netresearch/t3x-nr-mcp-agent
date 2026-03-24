/**
 * Tests for markdown.js — the markdown rendering utility.
 *
 * All tests verify the PUBLIC CONTRACT: given a markdown string,
 * renderMarkdown() returns safe HTML. Tests run in Node (no DOM),
 * so DOMPurify is mocked to a passthrough — XSS safety is tested
 * separately via the sanitize() contract tests at the bottom.
 */

import {renderMarkdown} from '../../Resources/Public/JavaScript/markdown.js';

// ── Basic inline formatting ──────────────────────────────────────────────────

test('renders bold text', () => {
    const html = renderMarkdown('**bold**');
    expect(html).toContain('<strong>bold</strong>');
});

test('renders italic text', () => {
    const html = renderMarkdown('*italic*');
    expect(html).toContain('<em>italic</em>');
});

test('renders inline code', () => {
    const html = renderMarkdown('use `npm install`');
    expect(html).toContain('<code>npm install</code>');
});

// ── Block elements ───────────────────────────────────────────────────────────

test('renders h1 header', () => {
    const html = renderMarkdown('# Heading');
    expect(html).toContain('<h1>Heading</h1>');
});

test('renders h2 header', () => {
    const html = renderMarkdown('## Heading');
    expect(html).toContain('<h2>Heading</h2>');
});

test('renders h3 header', () => {
    const html = renderMarkdown('### Heading');
    expect(html).toContain('<h3>Heading</h3>');
});

test('renders unordered list', () => {
    const html = renderMarkdown('- item one\n- item two');
    expect(html).toContain('<ul>');
    expect(html).toContain('<li>item one</li>');
    expect(html).toContain('<li>item two</li>');
});

test('renders ordered list', () => {
    const html = renderMarkdown('1. first\n2. second');
    expect(html).toContain('<ol>');
    expect(html).toContain('<li>first</li>');
});

test('renders fenced code block', () => {
    const html = renderMarkdown('```js\nconsole.log("hi");\n```');
    expect(html).toContain('<pre>');
    expect(html).toContain('<code');
    expect(html).toContain('console.log');
});

test('renders horizontal rule', () => {
    const html = renderMarkdown('---');
    expect(html).toContain('<hr');
});

test('renders blockquote', () => {
    const html = renderMarkdown('> quoted text');
    expect(html).toContain('<blockquote>');
    expect(html).toContain('quoted text');
});

// ── Tables ───────────────────────────────────────────────────────────────────

test('renders GFM table with header and rows', () => {
    const md = '| Name | Score |\n|------|-------|\n| Alice | 10 |\n| Bob | 7 |';
    const html = renderMarkdown(md);
    expect(html).toContain('<table>');
    expect(html).toContain('<th>');
    expect(html).toContain('Alice');
    expect(html).toContain('Bob');
});

// ── LLM-specific patterns ────────────────────────────────────────────────────

test('renders typical LLM score output (bold + list + hr)', () => {
    const md = '**Score: 3/10**\n\n- Missing meta\n- Bad URL\n\n---';
    const html = renderMarkdown(md);
    expect(html).toContain('<strong>');
    expect(html).toContain('<li>');
    expect(html).toContain('<hr');
});

test('passes through unknown directives as plain text without crashing', () => {
    expect(() => renderMarkdown(':::warning\nsome text\n:::')).not.toThrow();
});

// ── XSS safety ───────────────────────────────────────────────────────────────

test('strips script tags from output', () => {
    const html = renderMarkdown('<script>alert("xss")</script>');
    expect(html).not.toContain('<script>');
    expect(html).not.toContain('alert("xss")');
});

test('strips javascript: href from links', () => {
    const html = renderMarkdown('[click](javascript:alert(1))');
    expect(html).not.toContain('javascript:');
});

test('strips onerror attributes', () => {
    const html = renderMarkdown('<img src="x" onerror="alert(1)">');
    expect(html).not.toContain('onerror');
});

// ── Edge cases ───────────────────────────────────────────────────────────────

test('returns empty string for empty input', () => {
    expect(renderMarkdown('')).toBe('');
});

test('returns plain paragraph for plain text input', () => {
    const html = renderMarkdown('hello world');
    expect(html).toContain('hello world');
});
