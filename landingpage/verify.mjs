/**
 * Build gate for the product page.
 *
 * Checks the rendered artifact, not the sources: the point is what a visitor and
 * a crawler actually receive. Exit code 1 fails the build.
 *
 *   node landingpage/verify.mjs
 */

import {readFile, readdir, stat} from 'node:fs/promises';
import {dirname, relative, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

import {checkAccessibility} from './check-accessibility.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const output = resolve(root, '.Build/site');

const errors = [];
const fail = (message) => errors.push(message);

const PLACEHOLDERS = ['Loading…', 'Loading...', 'TBD', 'Lorem ipsum'];
const UNRESOLVED = /\{(VERSION|LATEST_RELEASE|TYPO3_VERSIONS|PHP_VERSIONS)\}/;

const REQUIRED_META = [
    [/<link rel="canonical" href="[^"]+"/, 'canonical'],
    [/<meta name="description" content="[^"]+"/, 'meta description'],
    [/hreflang="x-default"/, 'x-default hreflang'],
    [/<meta property="og:image" content="[^"]+"/, 'og:image'],
    [/<meta name="twitter:card"/, 'twitter:card'],
    [/<script type="application\/ld\+json">/, 'JSON-LD'],
];

function stripMarkup(html) {
    return html
        .replace(/<script\b[^>]*>[\s\S]*?<\/script\b[^>]*>/gi, ' ')
        .replace(/<style\b[^>]*>[\s\S]*?<\/style\b[^>]*>/gi, ' ')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ');
}

/**
 * The two product pages. The docs tree under /docs/ is rendered by render-guides
 * and is deliberately out of scope here — gating someone else's output on this
 * page's rules would fail on markup this repository does not write.
 */
async function htmlFiles(dir) {
    const entries = await readdir(dir, {withFileTypes: true});
    const files = [];
    for (const entry of entries) {
        if (entry.name === 'docs') continue;
        const full = resolve(dir, entry.name);
        if (entry.isDirectory()) files.push(...await htmlFiles(full));
        else if (entry.name.endsWith('.html')) files.push(full);
    }
    return files;
}

async function exists(path) {
    try {
        await stat(path);
        return true;
    } catch {
        return false;
    }
}

const manifestPath = resolve(output, 'project-manifest.json');
let manifest = null;
if (!await exists(manifestPath)) {
    fail('project-manifest.json was not published — the portfolio cannot read this project');
} else {
    manifest = JSON.parse(await readFile(manifestPath, 'utf8'));

    for (const field of ['manifest_version', 'name', 'slug', 'stage', 'main_version',
        'last_verified', 'owner', 'license', 'repository']) {
        if (!manifest[field]) fail(`project-manifest.json: ${field} is missing`);
    }

    // The manifest must agree with the repository it ships from.
    const emconf = await readFile(resolve(root, 'ext_emconf.php'), 'utf8');
    const version = emconf.match(/'version'\s*=>\s*'([^']+)'/)?.[1];
    if (version && manifest.main_version !== version) {
        fail(`project-manifest.json: main_version ${manifest.main_version} does not match ext_emconf.php ${version}`);
    }

    if (!['concept', 'poc', 'alpha', 'beta', 'stable', 'maintenance'].includes(manifest.stage)) {
        fail(`project-manifest.json: ${manifest.stage} is not a known maturity stage`);
    }

    for (const field of ['intended_purpose', 'excluded_uses', 'processing_location',
        'human_oversight', 'known_limitations']) {
        if (!manifest.ai?.[field]) fail(`project-manifest.json: ai.${field} is missing`);
    }
}

const pages = await htmlFiles(output);
let stubs = 0;

for (const page of pages) {
    const name = relative(output, page);
    const html = await readFile(page, 'utf8');

    // Redirect stubs keep the old documentation paths working. They are
    // meta-refresh only and carry no content of their own.
    if (/<meta[^>]+http-equiv=["']refresh["']/i.test(html)) {
        stubs += 1;
        continue;
    }

    const text = stripMarkup(html);

    for (const placeholder of PLACEHOLDERS) {
        if (text.includes(placeholder)) fail(`${name}: placeholder text in the initial HTML: ${placeholder}`);
    }

    const unresolved = html.match(UNRESOLVED);
    if (unresolved) fail(`${name}: unresolved content placeholder ${unresolved[0]}`);

    for (const [pattern, label] of REQUIRED_META) {
        if (!pattern.test(html)) fail(`${name}: no ${label}`);
    }

    for (const block of html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)) {
        try {
            JSON.parse(block[1]);
        } catch (error) {
            fail(`${name}: invalid JSON-LD: ${error.message}`);
        }
    }

    const contactLinks = [...html.matchAll(/href="([^"]*netresearch\.de\/kontakt\/[^"]*)"/g)].map((m) => m[1]);
    if (contactLinks.length === 0) fail(`${name}: no business CTA to the contact form`);
    for (const href of contactLinks) {
        for (const param of ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content']) {
            if (!href.includes(`${param}=`)) fail(`${name}: contact link without ${param}`);
        }
    }

    const logos = html.match(/<title>Netresearch DTT GmbH<\/title>/g) ?? [];
    if (logos.length !== 1) fail(`${name}: the logo appears ${logos.length} times, expected exactly once`);

    // Accessibility and semantics decidable from the markup alone.
    for (const problem of checkAccessibility(html)) fail(`${name}: ${problem}`);

    // The three things this page must never lose.
    if (!html.includes('class="status-facts"')) fail(`${name}: no status block`);
    if (!html.includes('capability-card__limits')) fail(`${name}: the capability card renders no known limitations`);
    if (!html.includes('bullets--no')) fail(`${name}: no "what is not promised" list`);

    // Alpha has to be stated in the hero, not only in a table further down.
    if (manifest?.stage === 'alpha' && !html.includes('class="alpha-banner"')) {
        fail(`${name}: the project is alpha but the hero carries no alpha notice`);
    }

    // A page about an agent that does not name its limits is a brochure.
    if (!html.includes('id="not-claimed"')) fail(`${name}: the "what is not claimed" section is missing`);

    // Everything the page loads comes from this origin.
    for (const asset of [...html.matchAll(/<(?:script|link|img)[^>]+(?:src|href)="(https?:\/\/[^"]+)"/g)].map((m) => m[1])) {
        if (!asset.startsWith('https://netresearch.github.io/')) {
            fail(`${name}: loads a third-party asset: ${asset}`);
        }
    }

    if (manifest) {
        const known = new Set([manifest.main_version, manifest.latest_release, manifest.docs_version]
            .filter(Boolean)
            .flatMap((v) => [v, String(v).replace(/^v/, '')]));
        for (const [, version] of text.matchAll(/\bv?(\d+\.\d+\.\d+)\b/g)) {
            if (!known.has(version) && !known.has(`v${version}`)) {
                fail(`${name}: version ${version} is rendered but is not in the manifest`);
            }
        }
    }
}

for (const required of ['sitemap.xml', 'robots.txt', 'assets/product.css',
    'assets/og-mcp-agent-en.png', 'assets/og-mcp-agent-de.png']) {
    if (!await exists(resolve(output, required))) fail(`missing .Build/site/${required}`);
}

// The documentation moved to /docs/; the product page is the new root. If the
// docs render is skipped the split is broken, not merely incomplete.
if (!await exists(resolve(output, 'docs/index.html'))) {
    fail('missing .Build/site/docs/index.html — the documentation did not render');
}

const productPages = pages.length - stubs;
if (productPages !== 2) fail(`expected 2 product pages, found ${productPages}`);

for (const message of errors) process.stderr.write(`ERROR ${message}\n`);
process.stdout.write(
    `\nverify: ${productPages} product pages and ${stubs} redirect stubs checked, ${errors.length} errors\n`,
);
process.exit(errors.length > 0 ? 1 : 0);
