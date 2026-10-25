// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {cp, mkdir, mkdtemp, readFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';
import {verifyNotFoundPage} from './verify-site-shell.mjs';

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
                const contactPath = locale === 'de' ? '/kontakt/' : '/en/contact/';
                const contactLinks = [...html.matchAll(/href="(https:\/\/www\.netresearch\.de\/(?:kontakt|en\/contact)\/[^"]*)"/g)];
                assert.ok(contactLinks.length >= 4, 'header, hero, final CTA and footer retain contact links');
                for (const [, href] of contactLinks) {
                    assert.equal(new URL(href).pathname, contactPath, `contact link changes language: ${href}`);
                }
                const imprintUrl = locale === 'de' ? 'https://www.netresearch.de/impressum/' : 'https://www.netresearch.de/en/imprint/';
                const privacyUrl = locale === 'de' ? 'https://www.netresearch.de/datenschutz/' : 'https://www.netresearch.de/en/privacy-policy/';
                assert.ok(html.includes(`href="${imprintUrl}" lang="${locale}"`));
                assert.ok(html.includes(`href="${privacyUrl}" lang="${locale}"`));
                for (const [, url] of html.matchAll(/(?:href|content)="(https:\/\/pages\.example[^"#]*)"/g)) {
                    assert.ok(url.startsWith(expected), `URL leaves project base path: ${url}`);
                }
                assert.ok(html.includes(`href="${expected}docs/"`));
            }
            const notFound = await readFile(resolve(fixture, '.Build/site/404.html'), 'utf8');
            const symbol = await readFile(resolve(root, 'landingpage/site-shell/netresearch-symbol-only.svg'));
            assert.deepEqual(verifyNotFoundPage(notFound, symbol, expected), []);
            assert.notDeepEqual(verifyNotFoundPage(notFound.replace('noindex,follow', 'index,follow'), symbol, expected), []);
            assert.notDeepEqual(verifyNotFoundPage(notFound.replace(`${new URL(expected).pathname}assets/product.css`, 'assets/product.css'), symbol, expected), []);
            if (new URL(expected).pathname !== '/') {
                for (const invalid of [`${new URL(expected).pathname}../assets/product.css`, `${expected}%2e%2e/assets/product.css`, '//wrong.test/assets/product.css']) {
                    assert.notDeepEqual(verifyNotFoundPage(notFound.replace(`${new URL(expected).pathname}assets/product.css`, invalid), symbol, expected), []);
                }
            }
            const sitemap = await readFile(resolve(fixture, '.Build/site/sitemap.xml'), 'utf8');
            assert.ok(sitemap.includes(`<loc>${expected}</loc>`));
            assert.ok(sitemap.includes(`<loc>${expected}de/</loc>`));
            assert.ok(!sitemap.includes('404.html'));
        } finally {
            await rm(fixture, {recursive: true, force: true});
        }
    });
}
