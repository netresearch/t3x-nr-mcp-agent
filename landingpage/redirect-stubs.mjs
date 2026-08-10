/**
 * Keeps old documentation links working after the move to /docs/.
 *
 * The Pages root used to be the rendered documentation, so every inbound link
 * points at a path like /Configuration/Index.html. Those paths now belong to
 * nothing. GitHub Pages has no server-side redirects, so each one gets a
 * meta-refresh stub pointing at its new location under /docs/.
 *
 * The root is the deliberate exception: / is the product page now, and that is
 * the whole point of the split.
 *
 *   node landingpage/redirect-stubs.mjs
 */

import {mkdir, readdir, writeFile} from 'node:fs/promises';
import {dirname, join, relative, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const output = resolve(here, '..', '.Build/site');
const docs = resolve(output, 'docs');

// Same base path the render uses, so a repo rename or a custom domain needs no
// change here either.
const BASE = process.env.PAGES_BASE_PATH || '/t3x-nr-mcp-agent/';

async function htmlFiles(dir) {
    const entries = await readdir(dir, {withFileTypes: true});
    const files = [];
    for (const entry of entries) {
        const full = resolve(dir, entry.name);
        if (entry.isDirectory()) files.push(...await htmlFiles(full));
        else if (entry.name.endsWith('.html')) files.push(full);
    }
    return files;
}

function stub(target) {
    return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="0; url=${target}">
<link rel="canonical" href="${target}">
<meta name="robots" content="noindex, follow">
<title>Moved to /docs/</title>
</head>
<body>
<p>The documentation moved to <a href="${target}">${target}</a>.</p>
</body>
</html>
`;
}

let written = 0;
for (const page of await htmlFiles(docs)) {
    const path = relative(docs, page).split('\\').join('/');
    // The old root is now the product page; everything else keeps its path.
    if (path === 'index.html' || path === 'Index.html') continue;

    const target = `${BASE}docs/${path}`;
    const destination = join(output, path);
    await mkdir(dirname(destination), {recursive: true});
    await writeFile(destination, stub(target), 'utf8');
    written += 1;
}

process.stdout.write(`Wrote ${written} redirect stubs for the old documentation paths\n`);
