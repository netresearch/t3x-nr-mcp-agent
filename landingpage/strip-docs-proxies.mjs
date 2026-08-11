/**
 * Removes the docs.typo3.org proxy controls from the rendered docs tree.
 *
 * render-guides emits two controls that only work on docs.typo3.org:
 *
 *   - <all-documentations-menu>, which fetches its entries from
 *     `_resources/js/menu-proxy.php`, and
 *   - a version <select>, which fetches `_resources/js/versions-proxy.php` and
 *     carries a hardcoded fallback URL into an unrelated TYPO3 manual.
 *
 * Neither endpoint exists here, so every one of the 28 docs pages issues a
 * request that 404s — confirmed against the live site, not inferred. Neither
 * control has anything to show either: this site hosts one project at one
 * version.
 *
 * Removing the elements is preferable to dropping only the attributes: without
 * an override the scripts fall back to their own origin, and this site's gate
 * forbids third-party requests.
 *
 *   node landingpage/strip-docs-proxies.mjs [docs-dir]
 */

import { readdirSync, statSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const DOCS = process.argv[2] ?? '.Build/site/docs';

// The mobile variant is matched first: its name has the plain name as a prefix,
// so a plain-name pattern would otherwise match its opening tag. End tags are
// matched the way a browser closes an element — `</name` followed by anything
// up to the first `>`, not a literal `</name>`.
const ELEMENTS = [
  /<all-documentations-menu-mobile\b[^>]*>[\s\S]*?<\/all-documentations-menu-mobile\b[^>]*>/gi,
  /<all-documentations-menu\b(?!-)[^>]*>[\s\S]*?<\/all-documentations-menu\b(?!-)[^>]*>/gi,
  // The version switcher and the label that names it.
  /<label[^>]*\bfor="versionSelect"[^>]*>[\s\S]*?<\/label\b[^>]*>/gi,
  /<select\b[^>]*\bid="versionSelect"[^>]*>[\s\S]*?<\/select\b[^>]*>/gi,
];

function htmlFiles(dir) {
  return readdirSync(dir).flatMap((entry) => {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) return htmlFiles(full);
    return full.endsWith('.html') ? [full] : [];
  });
}

let changedFiles = 0;
let removed = 0;

for (const file of htmlFiles(DOCS)) {
  const original = readFileSync(file, 'utf-8');
  let html = original;
  for (const pattern of ELEMENTS) {
    html = html.replace(pattern, () => {
      removed += 1;
      return '';
    });
  }
  if (html !== original) {
    writeFileSync(file, html);
    changedFiles += 1;
  }
}

// The removal is only as good as its own check: a pattern that silently stops
// matching would leave the 404s in place and still print a success line.
const leftover = htmlFiles(DOCS).filter((file) =>
  /-proxy\.php/.test(readFileSync(file, 'utf-8')),
);
if (leftover.length) {
  console.error(
    `strip-docs-proxies: ${leftover.length} file(s) still reference a *-proxy.php endpoint: ${leftover
      .slice(0, 3)
      .join(', ')}`,
  );
  process.exit(1);
}

console.log(`strip-docs-proxies: removed ${removed} element(s) from ${changedFiles} file(s)`);
