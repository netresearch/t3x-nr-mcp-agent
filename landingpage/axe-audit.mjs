/**
 * Runs axe-core against every rendered product page, in a real browser.
 *
 * landingpage/check-accessibility.mjs covers what is decidable from markup
 * alone. The defects it cannot see — colour contrast above all, because that
 * needs resolved CSS and a compositing model — are what this file is for. It
 * found four contrast failures on the live page that the static gate passed.
 *
 *   node landingpage/axe-audit.mjs [output-dir]
 *
 * Exits non-zero on any WCAG 2.1 AA violation. Uses Playwright's Chromium
 * because the repository already installs it for the end-to-end tests; adding a
 * second browser stack for one gate would not buy anything.
 *
 * Serves the output over loopback rather than opening file:// URLs: the pages
 * reference their stylesheet by path, and an unstyled page has no contrast
 * failures at all — it would pass for the wrong reason.
 */

import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { readdirSync, statSync, readFileSync } from 'node:fs';
import { join, extname, relative, sep } from 'node:path';
import { createRequire } from 'node:module';
import { chromium } from '@playwright/test';

const require = createRequire(import.meta.url);
const axePath = require.resolve('axe-core/axe.min.js');

const DIST = process.argv[2] ?? '.Build/site';

/**
 * Route prefixes this gate reports on but does not fail for.
 *
 * /docs/ is rendered by the pinned typo3-documentation/render-guides image, so
 * its markup is not this repository's to fix: the findings there are a syntax
 * highlighter's comment colour at 4.49:1 and list markup in the theme's own
 * navigation. Failing the deploy on a third-party theme would mean disabling
 * the gate, so the findings are printed and the exit code ignores them. The
 * product pages this repository writes are gated normally.
 */
const REPORT_ONLY = ['/docs/'];

const reportOnly = (route) => REPORT_ONLY.some((prefix) => route.startsWith(prefix));

/** The path the site is served from, with exactly one slash at each end. */
const baseSegments = (process.argv[3] ?? process.env.PAGES_BASE_PATH ?? '/')
  .split('/')
  .filter(Boolean)
  .join('/');
const BASE = baseSegments ? `/${baseSegments}/` : '/';

// WCAG 2.1 AA, which is what the pages claim. 'best-practice' is deliberately
// not included: it flags stylistic preferences, and a gate that fails on those
// gets disabled instead of fixed.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.webp': 'image/webp',
  '.woff2': 'font/woff2',
  '.xml': 'application/xml; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8',
};

/**
 * Every servable file below the output directory, as URL path → absolute file.
 *
 * The tree is indexed once and the request handler only looks paths up. Nothing
 * is joined with, or stat'ed from, a request path: a URL cannot name a file the
 * index does not already hold, so `..` has nothing to escape into.
 */
function indexFiles(dir, base = dir, into = new Map()) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      indexFiles(full, base, into);
    } else {
      const url = `/${relative(base, full).split(sep).join('/')}`;
      into.set(url, full);
      if (entry === 'index.html') into.set(url.replace(/index\.html$/, ''), full);
    }
  }
  return into;
}

/**
 * The routes a visitor can open: every directory with an index.html, plus every
 * other .html file. Named pages matter — a discovery that looks for index.html
 * alone silently skips whole sections and still reports success.
 */
function htmlRoutes(files) {
  return [...files.keys()]
    .filter((url) => url.endsWith('/') || (url.endsWith('.html') && !url.endsWith('/index.html')))
    .filter((url) => {
      // A meta-refresh stub carries no content and the browser leaves it at
      // once; auditing it measures whatever it redirected to, a second time.
      const html = readFileSync(files.get(url), 'utf-8');
      return !/<meta[^>]+http-equiv=["']refresh["']/i.test(html);
    })
    .sort((a, b) => a.localeCompare(b));
}

/**
 * The route shape a page belongs to. Segments that name one repository, one
 * date or one document collapse to '*': those pages are the same template with
 * different data, and auditing all of them costs deploy time to re-find what
 * the first one already found.
 */
function routeShape(route) {
  return route
    .replace(/(\/(?:repo|snapshot|adr)\/)[^/]+(\/|$)/g, '$1*$2')
    .replace(/\/[^/]+\.html$/, '/*.html');
}

/** One representative route per shape, and how many that left out. */
function sampleRoutes(routes) {
  const groups = new Map();
  for (const route of routes) {
    const shape = routeShape(route);
    if (!groups.has(shape)) groups.set(shape, []);
    groups.get(shape).push(route);
  }
  return [...groups.entries()].map(([shape, members]) => ({
    shape,
    route: members[0],
    skipped: members.length - 1,
  }));
}

function serve(files) {
  const server = createServer(async (req, res) => {
    const path = new URL(req.url, 'http://localhost').pathname;
    const file = path.startsWith(BASE) ? files.get(`/${path.slice(BASE.length)}`) : undefined;
    if (!file) {
      res.writeHead(404).end('not found');
      return;
    }
    res.writeHead(200, { 'content-type': MIME[extname(file)] ?? 'application/octet-stream' });
    res.end(await readFile(file));
  });
  return new Promise((resolve_) => {
    server.listen(0, '127.0.0.1', () => resolve_({ server, port: server.address().port }));
  });
}

/**
 * Both colour schemes. The dark palette is a separate set of colour pairs, so a
 * light-only audit says nothing about it — and the pages ship dark mode.
 */
const SCHEMES = ['light', 'dark'];

/** Prints one violation. Extracted so main() stays readable. */
function report({ route, scheme, violation }) {
  console.error(`\n✗ ${route} [${scheme}] — ${violation.id} (${violation.impact})`);
  console.error(`  ${violation.help}`);
  console.error(`  ${violation.helpUrl}`);
  for (const node of violation.nodes.slice(0, 4)) {
    console.error(`    ${node.target.join(' ')}`);
    for (const line of (node.failureSummary ?? '').split('\n').filter(Boolean).slice(1)) {
      console.error(`      ${line}`);
    }
  }
  if (violation.nodes.length > 4) {
    console.error(`    … and ${violation.nodes.length - 4} more element(s)`);
  }
}

/** Audits one page and returns the failures found on it. */
async function auditPage(browser, port, route, scheme) {
  const context = await browser.newContext({
    colorScheme: scheme,
    viewport: { width: 1280, height: 900 },
  });
  const page = await context.newPage();

  // A page whose stylesheet 404s has no contrast failures at all, so a silent
  // asset error turns this gate into a rubber stamp. Any request that does not
  // succeed is therefore a failure in its own right.
  const broken = [];
  page.on('response', (response) => {
    if (response.status() >= 400) broken.push(`${response.status()} ${response.url()}`);
  });

  await page.goto(`http://127.0.0.1:${port}${BASE}${route.slice(1)}`, { waitUntil: 'networkidle' });
  await page.addScriptTag({ path: axePath });

  const results = await page.evaluate(
    async (tags) => await window.axe.run(document, { runOnly: { type: 'tag', values: tags } }),
    TAGS,
  );
  await context.close();

  const found = results.violations.map((violation) => ({ route, scheme, violation }));
  if (broken.length) {
    found.unshift({
      route,
      scheme,
      violation: {
        id: 'asset-not-served',
        impact: 'critical',
        help: 'Every asset the page requests must be served — an unstyled page passes for the wrong reason',
        helpUrl: 'https://github.com/netresearch/t3x-nr-mcp-agent/blob/main/landingpage/axe-audit.mjs',
        nodes: broken.map((entry) => ({ target: [entry], failureSummary: '' })),
      },
    });
  }
  return found;
}

async function main() {
  const files = indexFiles(DIST);
  const routes = htmlRoutes(files);
  if (routes.length === 0) throw new Error(`no pages found in ${DIST} — run landingpage/render.mjs first`);
  const { server, port } = await serve(files);

  // A gate that silently covers a subset reads as if it covered everything.
  const sampled = sampleRoutes(routes);
  for (const { shape, route, skipped } of sampled) {
    if (skipped) {
      console.log(`  ${shape} → auditing ${route} (${skipped} further page(s) of this shape not audited)`);
    }
  }

  const browser = await chromium.launch();

  const failures = [];
  let checked = 0;

  for (const { route } of sampled) {
    for (const scheme of SCHEMES) {
      failures.push(...(await auditPage(browser, port, route, scheme)));
      checked += 1;
    }
  }

  await browser.close();
  server.close();

  const gated = failures.filter(({ route }) => !reportOnly(route));
  const informational = failures.filter(({ route }) => reportOnly(route));

  for (const failure of gated) report(failure);

  if (informational.length) {
    console.log(
      `\nnot gated — ${informational.length} finding(s) under ${REPORT_ONLY.join(', ')}, which render-guides generates:`,
    );
    const byRule = new Map();
    for (const { violation } of informational) {
      byRule.set(violation.id, (byRule.get(violation.id) ?? 0) + violation.nodes.length);
    }
    for (const [rule, nodes] of [...byRule].sort((a, b) => b[1] - a[1])) {
      console.log(`  ${rule}: ${nodes} element(s)`);
    }
  }

  if (gated.length) {
    console.error(
      `\naxe: ${gated.length} violation(s) across ${checked} page renders (${sampled.length} route shapes × ${SCHEMES.length} colour schemes, sampled from ${routes.length} pages)`,
    );
    process.exit(1);
  }

  console.log(
    `axe: no WCAG 2.1 AA violations in ${checked} page renders (${sampled.length} route shapes × ${SCHEMES.length} colour schemes, sampled from ${routes.length} pages) served from ${BASE}`,
  );
}

await main();
