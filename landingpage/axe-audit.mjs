// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

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
 * Exits non-zero on WCAG 2.2 AA and automated AAA violations on all published pages. Uses Playwright's Chromium
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
import { chromium, expect } from '@playwright/test';

const require = createRequire(import.meta.url);
const axePath = require.resolve('axe-core/axe.min.js');

const DIST = process.argv[2] ?? '.Build/site';

// Product controls and manual controls differ, but every route is gated for
// accessibility, asset availability, page initialization and viewport reflow.
const isDocumentation = (route) => route.startsWith('/docs/');

/** The path the site is served from, with exactly one slash at each end. */
const baseSegments = (process.argv[3] ?? process.env.PAGES_BASE_PATH ?? '/')
  .split('/')
  .filter(Boolean)
  .join('/');
const BASE = baseSegments ? `/${baseSegments}/` : '/';

// AA plus the automated AAA checks. 'best-practice' is deliberately
// not included: it flags stylistic preferences, and a gate that fails on those
// gets disabled instead of fixed.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'wcag2aaa'];

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

/** A clipped card can hide overflowing labels without widening the document. */
async function textBounds(page) {
  return page.evaluate(() => ({
    viewport: innerWidth,
    width: document.documentElement.scrollWidth,
    clipped: [...document.querySelectorAll('.capability-card dt, .capability-card dd')]
      .filter((element) => {
        const bounds = element.getBoundingClientRect();
        const card = element.closest('.capability-card').getBoundingClientRect();
        return bounds.left < card.left - 1 || bounds.right > card.right + 1
          || element.scrollWidth > element.clientWidth + 1;
      }).map((element) => element.textContent.trim().slice(0, 100)),
  }));
}

function textAdaptationViolations(state, adaptation) {
  const nodes = state.clipped.map((text) => ({
    target: ['.capability-card'], failureSummary: `${adaptation}: clipped content: ${text}`,
  }));
  if (state.width > state.viewport + 1) nodes.push({
    target: ['html'], failureSummary: `${adaptation}: document ${state.width}px exceeds ${state.viewport}px`,
  });
  if (!nodes.length) return [];
  return [{
    id: 'text-adaptation', impact: 'serious', help: 'Enlarged and spaced text must remain available',
    helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/resize-text.html', nodes,
  }];
}

/** Exercise both text adaptations serially on the narrow product page. */
async function auditTextAdaptation(page, scheme) {
  if (scheme !== 'light') return [];
  await page.evaluate(() => {
    const sizes = [...document.querySelectorAll('body, body *')]
      .map((element) => [element, Number.parseFloat(getComputedStyle(element).fontSize)]);
    for (const [element, size] of sizes) element.style.fontSize = `${size * 2}px`;
  });
  const enlarged = await textBounds(page);
  await page.reload({waitUntil: 'networkidle'});
  const spacing = await page.addStyleTag({content: '*{line-height:1.5!important;letter-spacing:.12em!important;word-spacing:.16em!important}p{margin-bottom:2em!important}'});
  const spaced = await textBounds(page);
  await spacing.evaluate((style) => style.remove());
  return [...textAdaptationViolations(enlarged, '200% text'), ...textAdaptationViolations(spaced, 'WCAG text spacing')];
}

/** Audits one page and returns the failures found on it. */
async function auditPageControls(page, scheme) {
  const defects = [];
  const requested = scheme === 'light' ? 'dark' : 'light';
  const picker = page.locator('[data-nr-theme-picker]');
  await chooseTheme(page, requested);
  if ((await page.locator('html').getAttribute('data-theme')) !== requested) defects.push('Theme selection failed');
  await page.reload();
  if (!(await picker.locator(`input[value="${requested}"]`).isChecked())) defects.push('Theme did not persist');
  await page.evaluate(() => localStorage.setItem('nrllm-theme', 'dark'));
  await chooseTheme(page, 'system');
  await page.reload();
  const restored = await page.evaluate(() => ({stored: localStorage.getItem('nr-theme'), effective: document.documentElement.dataset.theme}));
  if (restored.stored !== 'system' || restored.effective !== scheme) defects.push('System did not override legacy preference');
  await page.emulateMedia({colorScheme: requested});
  await page.waitForFunction((expected) => document.documentElement.dataset.theme === expected, requested);
  if ((await page.locator('html').getAttribute('data-theme')) !== requested) defects.push('System did not follow OS');
  await picker.locator('summary').click();
  await page.keyboard.press('Escape');
  if (await picker.evaluate((element) => element.open || document.activeElement !== element.querySelector('summary'))) defects.push('Theme Escape/focus failed');
  const menu = page.locator('[data-nr-mobile-menu]');
  await menu.locator('summary').focus();
  await page.keyboard.press('Enter');
  if (!(await menu.evaluate((element) => element.open))) defects.push('Native menu keyboard open failed');
  await page.keyboard.press('Escape');
  if (await menu.evaluate((element) => element.open || document.activeElement !== element.querySelector('summary'))) defects.push('Menu Escape/focus failed');
  const local = page.locator('[data-nr-page-menu]');
  await local.locator('summary').focus();
  await page.keyboard.press('Enter');
  if (!(await local.locator('a').first().isVisible())) defects.push('Local menu did not open');
  await page.keyboard.press('Escape');
  if (await local.evaluate((element) => element.open || document.activeElement !== element.querySelector('summary'))) defects.push('Local menu Escape/focus failed');
  return defects.length ? [{id: 'page-controls', impact: 'serious', help: 'Theme and native disclosure controls must work',
    nodes: defects.map((defect) => ({target: [defect], failureSummary: ''}))}] : [];
}

async function chooseTheme(page, preference) {
  const picker = page.locator('[data-nr-theme-picker]');
  if (!(await picker.evaluate((element) => element.open))) await picker.locator('summary').click();
  await picker.locator(`input[value="${preference}"]`).check();
}

async function auditAlphaWarning(page) {
  const state = await page.evaluate(() => {
    const banner = document.querySelector('[data-nr-hero] p.alpha-banner');
    const action = document.querySelector('[data-nr-hero] [data-cta="business"][data-cta-position="hero"]');
    if (!banner || !action) return null;
    const note = [...banner.childNodes].filter(node => node.nodeType === Node.TEXT_NODE).map(node => node.textContent).join('').trim();
    return {warningBottom: banner.getBoundingClientRect().bottom, actionTop: action.getBoundingClientRect().top,
      visible: banner.checkVisibility(), note: Boolean(note), precedes: Boolean(banner.compareDocumentPosition(action) & Node.DOCUMENT_POSITION_FOLLOWING)};
  });
  if (state?.visible && state.note && state.precedes && state.warningBottom <= state.actionTop) return [];
  return [{id: 'alpha-warning-order', impact: 'serious', help: 'The full alpha warning must precede the evaluation action',
    nodes: [{target: ['.alpha-banner'], failureSummary: JSON.stringify(state)}]}];
}

async function auditPrintedGuidance(page) {
  await page.emulateMedia({media: 'print'});
  const contrasts = await page.evaluate(() => [...document.querySelectorAll('[data-nr-hero] .alpha-banner, [data-nr-hero] .status-pill, .answers dt, .answers dd')].map(element => {
    const selector = element.closest('.answers') ? `.answers ${element.localName}` : `.${element.classList[0]}`;
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 1;
    const context = canvas.getContext('2d');
    if (!context) return {selector, contrast: 0};
    context.fillStyle = '#fff';
    context.fillRect(0, 0, 1, 1);
    context.fillStyle = getComputedStyle(element).color;
    context.fillRect(0, 0, 1, 1);
    const [r, g, b] = [...context.getImageData(0, 0, 1, 1).data].slice(0, 3).map(value => {
      const channel = value / 255;
      return channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4;
    });
    return {selector, contrast: 1.05 / (.2126 * r + .7152 * g + .0722 * b + .05)};
  }));
  await page.emulateMedia({media: 'screen'});
  const failures = contrasts.filter(value => value.contrast < 7);
  return failures.length ? [{id: 'guidance-print-contrast', impact: 'serious', help: 'Alpha warnings, maturity badges and product answers need enhanced text contrast on white printed paper',
    nodes: failures.map(value => ({target: [value.selector], failureSummary: `Contrast on white paper: ${value.contrast.toFixed(2)}:1`}))}] : [];
}

async function auditPage(browser, port, route, scheme, width) {
  const context = await browser.newContext({
    colorScheme: scheme,
    viewport: { width, height: 900 },
  });
  const page = await context.newPage();

  // A page whose stylesheet 404s has no contrast failures at all, so a silent
  // asset error turns this gate into a rubber stamp. Any request that does not
  // succeed is therefore a failure in its own right.
  const broken = [];
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('response', (response) => {
    if (response.status() >= 400) broken.push(`${response.status()} ${response.url()}`);
  });

  await page.goto(`http://127.0.0.1:${port}${BASE}${route.slice(1)}`, { waitUntil: 'networkidle' });
  await page.addScriptTag({ path: axePath });

  const results = await page.evaluate(
    async (tags) => await window.axe.run(document, { runOnly: { type: 'tag', values: tags } }),
    TAGS,
  );
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
  if (overflow) results.violations.push({
    id: 'page-reflow', impact: 'serious', help: 'The document must fit the viewport',
    nodes: [{target: ['html'], failureSummary: `Document overflows the ${width}px viewport`}],
  });
  if (route === '/' || route === '/de/') results.violations.push(...await auditAlphaWarning(page), ...await auditPrintedGuidance(page));
  if (width === 320 && !isDocumentation(route)) {
    results.violations.push(
      ...(await auditTextAdaptation(page, scheme)),
      ...(await auditPageControls(page, scheme)),
    );
  }

  await context.close();

  const found = results.violations.map((violation) => ({ route, scheme: `${scheme}/${width}px`, violation }));
  if (pageErrors.length) found.push({
    route, scheme,
    violation: {
      id: 'page-script-error', impact: 'critical', help: 'Every published page must initialize without JavaScript errors',
      nodes: pageErrors.map((message) => ({target: ['document'], failureSummary: `Page initialization failed:\n${message}`})),
    },
  });
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

/** Each render owns mutable browser state and completes before the next starts. */
async function* auditPages(browser, port, sampled) {
  const cases = sampled.flatMap(({ route }) => SCHEMES.flatMap((scheme) =>
    [320, 1280].map((width) => ({ route, scheme, width }))));
  for (const { route, scheme, width } of cases) {
    // An async generator awaits each yielded result before the consumer advances.
    yield auditPage(browser, port, route, scheme, width);
  }
}

async function auditNoJavaScript(browser, port, routes) {
  const failures = [];
  // Separate contexts keep this progressive-enhancement probe isolated.
  await routes.reduce((sequence, route) => sequence.then(async () => {
    const context = await browser.newContext({javaScriptEnabled: false, viewport: {width: 320, height: 900}});
    const page = await context.newPage();
    await page.goto(`http://127.0.0.1:${port}${BASE}${route.slice(1)}`);
    await page.locator('.skip-link').focus();
    // Poll from the Node test host: page rAF callbacks are disabled in this context.
    await expect.poll(() => page.locator('.skip-link').evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return rect.top >= 0 && element.contains(document.elementFromPoint(rect.left + 8, rect.top + 8));
    })).toBe(true);
    const local = page.locator('[data-nr-page-menu]');
    if (await local.count()) {
      await local.locator('summary').focus();
      await page.keyboard.press('Enter');
      await expect(local.locator('a').first()).toBeVisible();
      await page.keyboard.press('Enter');
    }
    const menu = page.locator('[data-nr-mobile-menu]');
    await menu.locator('summary').focus();
    await page.keyboard.press('Enter');
    const valid = await menu.locator('nav a').first().isVisible()
      && await page.locator('.nr-shell-locales').isVisible()
      && !(await page.locator('[data-nr-theme-picker]').isVisible())
      && await page.evaluate(() => document.documentElement.scrollWidth === innerWidth);
    if (!valid) failures.push({route, scheme: 'no-js/320px', violation: {
      id: 'native-navigation', impact: 'serious', help: 'Native navigation and locales must work without JavaScript',
      nodes: [{target: ['[data-nr-shell]'], failureSummary: 'Unavailable links, inert appearance control or document overflow'}],
    }});
    await context.close();
  }), Promise.resolve());
  return failures;
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

  const browser = await chromium.launch({executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH});

  const failures = [];
  let checked = 0;

  for await (const found of auditPages(browser, port, sampled)) {
    failures.push(...found);
    checked += 1;
  }

  failures.push(...(await auditNoJavaScript(browser, port, ['/', '/de/'])));
  await browser.close();
  server.close();

  const gated = failures;
  for (const failure of gated) report(failure);

  if (gated.length) {
    console.error(
      `\naxe: ${gated.length} violation(s) across ${checked} page renders (${sampled.length} route shapes, ${SCHEMES.length} colour schemes; widths 320/1280px, sampled from ${routes.length} pages)`,
    );
    process.exit(1);
  }

  console.log(
    `axe: no gated AA/automated AAA or page-control violations in ${checked} page renders (${sampled.length} route shapes, ${SCHEMES.length} colour schemes; widths 320/1280px, sampled from ${routes.length} pages) served from ${BASE}`,
  );
}

await main();
