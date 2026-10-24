// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {cp, mkdir, mkdtemp, readFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

for (const [basePath, expected] of [
    ['/nested/project', 'https://pages.example/nested/project/'],
    ['/nested/project/', 'https://pages.example/nested/project/'],
    ['', 'https://pages.example/'],
    ['/', 'https://pages.example/'],
]) {
    test(`rendered locale and metadata URLs resolve with base path ${JSON.stringify(basePath)}`, async () => {
        const fixture = await mkdtemp(resolve(tmpdir(), 'nr-pages-'));
        try {
            await cp(resolve(root, 'landingpage'), resolve(fixture, 'landingpage'), {recursive: true});
            for (const file of ['composer.json', 'ext_emconf.php', 'Resources/Public/Icons/Extension.svg']) {
                await mkdir(dirname(resolve(fixture, file)), {recursive: true});
                await cp(resolve(root, file), resolve(fixture, file));
            }
            execFileSync(process.execPath, [
                resolve(root, 'landingpage/render-fixture.mjs'),
                resolve(fixture, 'landingpage/render.mjs'),
            ], {
                env: {...process.env, PAGES_ORIGIN: 'https://pages.example/', PAGES_BASE_PATH: basePath},
                stdio: 'pipe',
            });
            for (const [locale, path] of [['en', ''], ['de', 'de/']]) {
                const html = await readFile(resolve(fixture, '.Build/site', path, 'index.html'), 'utf8');
                assert.match(html, new RegExp(`<html lang="${locale}">`));
                assert.ok(html.includes(`<link rel="canonical" href="${expected}${path}">`));
                assert.ok(html.includes(`hreflang="en" href="${expected}"`));
                assert.ok(html.includes(`hreflang="de" href="${expected}de/"`));
                assert.ok(html.includes(`content="${expected}assets/og-mcp-agent-${locale}.png"`));
                assert.ok(html.includes(`rel="sitemap" href="${expected}sitemap.xml"`));
                for (const [, url] of html.matchAll(/(?:href|content)="(https:\/\/pages\.example[^"#]*)"/g)) {
                    assert.ok(url.startsWith(expected), `URL leaves project base path: ${url}`);
                }
                assert.ok(html.includes(`href="${expected}docs/"`));
            }
            const sitemap = await readFile(resolve(fixture, '.Build/site/sitemap.xml'), 'utf8');
            assert.ok(sitemap.includes(`<loc>${expected}</loc>`));
            assert.ok(sitemap.includes(`<loc>${expected}de/</loc>`));
        } finally {
            await rm(fixture, {recursive: true, force: true});
        }
    });
}
