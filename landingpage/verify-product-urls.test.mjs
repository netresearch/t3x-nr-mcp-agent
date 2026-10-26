// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdir, mkdtemp, rm, writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';
import {tmpdir} from 'node:os';
import {verifyProductUrls} from './verify-product-urls.mjs';

function markup({origin = 'https://pages.example', image = '../assets/preview.png'} = {}) {
    return `<link rel="canonical" href="${origin}/nested/project/de/">
<link rel="alternate" hreflang="en" href="${origin}/nested/project/">
<link rel="alternate" hreflang="de" href="${origin}/nested/project/de/">
<link rel="alternate" hreflang="x-default" href="${origin}/nested/project/">
<link rel="sitemap" href="../sitemap.xml">
<meta property="og:image" content="${image}">`;
}

async function withArtifacts(run) {
    const output = await mkdtemp(resolve(tmpdir(), 'nr-page-url-check-'));
    try {
        await mkdir(resolve(output, 'assets'));
        await writeFile(resolve(output, 'assets/preview.png'), 'preview');
        await writeFile(resolve(output, 'sitemap.xml'), '<urlset/>');
        return await run(output);
    } finally {
        await rm(output, {recursive: true, force: true});
    }
}

const verify = (html, output) => verifyProductUrls(
    html, 'de/index.html', output, '/nested/project/', 'https://pages.example',
);

test('German relative metadata resources resolve from the page URL', async () => {
    await withArtifacts(async (output) => {
        assert.deepEqual(await verify(markup(), output), []);
    });
});

test('German metadata does not incorrectly reuse a matching resource at the site root', async () => {
    await withArtifacts(async (output) => {
        const errors = await verify(markup({image: 'assets/preview.png'}), output);
        assert.ok(errors.some((error) => error.includes('does not exist')), errors.join('\n'));
    });
});

test('matching canonical and alternate paths on a foreign host cannot authenticate that host', async () => {
    await withArtifacts(async (output) => {
        const errors = await verify(markup({origin: 'https://wrong.example'}), output);
        assert.ok(errors.some((error) => error.includes('canonical')), errors.join('\n'));
        assert.ok(errors.some((error) => error.includes('hreflang')), errors.join('\n'));
    });
});

test('an existing directory is not an image resource', async () => {
    await withArtifacts(async (output) => {
        const errors = await verify(markup({image: '../assets/'}), output);
        assert.ok(errors.some((error) => error.includes('regular file')), errors.join('\n'));
    });
});

for (const image of ['https://wrong.example/nested/project/assets/preview.png', '/outside/preview.png']) {
    test(`metadata resources must remain on the deployment origin and project path: ${image}`, async () => {
        await withArtifacts(async (output) => {
            assert.deepEqual(await verify(markup({image}), output), [
                `metadata resource leaves the project base path: ${image}`,
            ]);
        });
    });
}

for (const [name, pattern, diagnostic] of [
    ['canonical', /<link rel="canonical"[^>]*>/g, 'canonical'],
    ['all language alternates', /<link rel="alternate"[^>]*>/g, 'hreflang'],
    ['x-default alternate', /<link rel="alternate" hreflang="x-default"[^>]*>/g, 'hreflang x-default'],
]) {
    test(`${name} in the body cannot satisfy head metadata`, async () => {
        await withArtifacts(async output => {
            const source = markup();
            const moved = source.match(pattern).join('');
            const html = `<html><head>${source.replace(pattern, '')}</head><body>${moved}</body></html>`;
            const errors = await verify(html, output);
            assert.ok(errors.some(error => error.includes(diagnostic) && error.includes('document head')), errors.join('\n'));
        });
    });
}

test('one real head canonical and each language alternate are required', async () => {
    await withArtifacts(async output => {
        const source = markup();
        assert.deepEqual(await verify(`<html><head>${source}</head><body></body></html>`, output), []);
        const canonical = source.match(/<link rel="canonical"[^>]*>/)[0];
        assert.ok((await verify(`<html><head>${source}${canonical}</head><body></body></html>`, output)).some(error => error.includes('canonical')));
        assert.ok((await verify('<html><head></head><body></body></html>', output)).some(error => error.includes('canonical')));
    });
});
