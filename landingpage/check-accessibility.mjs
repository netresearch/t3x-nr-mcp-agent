/**
 * Static accessibility and HTML-semantics checks for rendered pages.
 *
 * Deliberately dependency-free and browser-free: these are the defects that are
 * decidable from the markup alone, which is where generated HTML gets them
 * wrong. It is not a substitute for axe or for testing with a screen reader —
 * contrast, focus order and live-region behaviour are not decidable here, and
 * this file does not pretend otherwise.
 *
 *   import { checkAccessibility } from './check-accessibility.mjs';
 *   for (const problem of checkAccessibility(html)) fail(name, problem);
 */

/** All attributes of one tag, lower-cased keys. */
function attrs(tag) {
  const out = {};
  for (const match of tag.matchAll(/([a-zA-Z-]+)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/g)) {
    out[match[1].toLowerCase()] = match[2] ?? match[3] ?? match[4] ?? '';
  }
  for (const match of tag.matchAll(/\s([a-zA-Z-]+)(?=[\s>])/g)) {
    const key = match[1].toLowerCase();
    if (!(key in out)) out[key] = '';
  }
  return out;
}

/** Text content of an element, given the whole document and the tag's index. */
function innerText(html, tagName, startIndex) {
  const close = html.indexOf(`</${tagName}`, startIndex);
  if (close === -1) return '';
  const open = html.indexOf('>', startIndex);
  if (open === -1 || open > close) return '';
  return html
    .slice(open + 1, close)
    .replace(/<[^>]+>/g, ' ')
    .replace(/&[a-z]+;|&#\d+;/gi, ' ')
    .trim();
}

export function checkAccessibility(html) {
  const problems = [];

  // ── Document ───────────────────────────────────────────────────────────────
  const htmlTag = html.match(/<html\b[^>]*>/i);
  if (!htmlTag || !attrs(htmlTag[0]).lang) {
    problems.push('the <html> element has no lang attribute');
  }

  const mains = [...html.matchAll(/<main\b[^>]*>/gi)];
  if (mains.length !== 1) {
    problems.push(`${mains.length} <main> landmarks, expected exactly 1`);
  }

  // ── Headings ───────────────────────────────────────────────────────────────
  const headings = [...html.matchAll(/<h([1-6])\b[^>]*>/gi)].map((m) => Number(m[1]));
  const h1s = headings.filter((level) => level === 1).length;
  if (h1s !== 1) problems.push(`${h1s} <h1> elements, expected exactly 1`);

  let previous = 0;
  for (const level of headings) {
    if (previous && level > previous + 1) {
      problems.push(`heading level jumps from h${previous} to h${level}`);
      break; // One report is enough; the whole outline needs fixing anyway.
    }
    previous = level;
  }

  // ── Images ─────────────────────────────────────────────────────────────────
  for (const match of html.matchAll(/<img\b[^>]*>/gi)) {
    if (!('alt' in attrs(match[0]))) {
      problems.push(`<img> without an alt attribute: ${match[0].slice(0, 90)}`);
    }
  }

  // ── Links and buttons need an accessible name ──────────────────────────────
  for (const match of html.matchAll(/<a\b[^>]*href[^>]*>/gi)) {
    const a = attrs(match[0]);
    const text = innerText(html, 'a', match.index);
    const hasImageAlt = /<img[^>]+alt\s*=\s*["'][^"']+["']/i.test(
      html.slice(match.index, html.indexOf('</a', match.index) + 1),
    );
    if (!text && !a['aria-label'] && !a['aria-labelledby'] && !hasImageAlt) {
      problems.push(`link without discernible text: ${match[0].slice(0, 90)}`);
    }
  }

  for (const match of html.matchAll(/<button\b[^>]*>/gi)) {
    const button = attrs(match[0]);
    const text = innerText(html, 'button', match.index);
    const hasSvgTitle = /<svg[\s\S]*?<title>[^<]+<\/title>/i.test(
      html.slice(match.index, html.indexOf('</button', match.index) + 1),
    );
    if (!text && !button['aria-label'] && !button['aria-labelledby'] && !hasSvgTitle) {
      problems.push(`button without an accessible name: ${match[0].slice(0, 90)}`);
    }
  }

  // ── Form controls need a label ─────────────────────────────────────────────
  const labelFor = new Set(
    [...html.matchAll(/<label\b[^>]*\bfor\s*=\s*(?:"([^"]+)"|'([^']+)')/gi)].map((m) => m[1] ?? m[2]),
  );

  // A control nested inside a <label> that has text is labelled implicitly.
  // That is valid HTML and common for checkboxes, so a checker that only looks
  // for `for=` reports a defect that is not there. The span is the whole
  // element — a nested control sits after the opening tag, not inside it.
  const implicitLabels = [...html.matchAll(/<label\b[^>]*>/gi)]
    .map((m) => ({
      start: m.index,
      end: html.indexOf('</label', m.index),
      text: innerText(html, 'label', m.index),
    }))
    .filter((label) => label.text && label.end !== -1);

  const insideLabelledLabel = (offset) =>
    implicitLabels.some((label) => label.start < offset && offset < label.end);
  for (const match of html.matchAll(/<(input|select|textarea)\b[^>]*>/gi)) {
    const control = attrs(match[0]);
    if (control.type === 'hidden' || control.type === 'submit' || control.type === 'button') continue;
    const labelled =
      control['aria-label'] ||
      control['aria-labelledby'] ||
      (control.id && labelFor.has(control.id)) ||
      control.title ||
      insideLabelledLabel(match.index);
    if (!labelled) {
      problems.push(`form control without a label: ${match[0].slice(0, 90)}`);
    }
  }

  // ── Tables ─────────────────────────────────────────────────────────────────
  for (const match of html.matchAll(/<table\b[^>]*>/gi)) {
    const table = attrs(match[0]);
    const body = html.slice(match.index, html.indexOf('</table', match.index) + 1);
    if (!/<caption\b/i.test(body) && !table['aria-label'] && !table['aria-labelledby']) {
      problems.push('table without a caption or an accessible name');
    }
    for (const cell of body.matchAll(/<th\b[^>]*>/gi)) {
      if (!attrs(cell[0]).scope) {
        problems.push(`<th> without a scope attribute: ${cell[0].slice(0, 70)}`);
        break;
      }
    }
  }

  // ── Focus order and identity ───────────────────────────────────────────────
  for (const match of html.matchAll(/\btabindex\s*=\s*["']?(\d+)["']?/gi)) {
    if (Number(match[1]) > 0) {
      problems.push(`positive tabindex="${match[1]}" overrides the natural focus order`);
      break;
    }
  }

  const ids = [...html.matchAll(/\sid\s*=\s*(?:"([^"]+)"|'([^']+)')/gi)].map((m) => m[1] ?? m[2]);
  const duplicates = [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))];
  if (duplicates.length) {
    problems.push(`duplicate id attribute(s): ${duplicates.slice(0, 5).join(', ')}`);
  }

  return problems;
}
