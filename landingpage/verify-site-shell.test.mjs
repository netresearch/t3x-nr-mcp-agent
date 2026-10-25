// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {renderSiteHeader} from './site-shell/render-site-shell.mjs';
import {verifySiteShell} from './verify-site-shell.mjs';
const symbol = readFileSync(new URL('./site-shell/netresearch-symbol-only.svg', import.meta.url));
const header = renderSiteHeader({lang: 'en', assetBase: 'assets/site-shell', languageUrls: {en: './', de: 'de/'}, active: null, contactHref: 'https://www.netresearch.de/kontakt/'});
test('shared header carries exactly one authentic, linked brand symbol', () => {
    assert.deepEqual(verifySiteShell(header, symbol), []);
});
test('logo gate rejects duplicates, wrong company targets and altered assets', () => {
    const img = header.match(/<img[^>]*>/)[0];
    assert.notDeepEqual(verifySiteShell(header + img, symbol), []);
    assert.notDeepEqual(verifySiteShell(header.replace('https://www.netresearch.de/', 'https://example.com/'), symbol), []);
    assert.notDeepEqual(verifySiteShell(header, Buffer.from(symbol.toString().replace('#2F99A4', '#000000'))), []);
});

for (const [lang, expected] of Object.entries({
    en: ['https://netresearch.github.io/', 'https://netresearch.github.io/projects/', 'https://netresearch.github.io/modularer-ki-stack-page/en/', 'https://netresearch.github.io/claude-code-marketplace/en/', 'https://netresearch.github.io/maint/'],
    de: ['https://netresearch.github.io/de/', 'https://netresearch.github.io/de/projekte/', 'https://netresearch.github.io/modularer-ki-stack-page/', 'https://netresearch.github.io/claude-code-marketplace/de/', 'https://netresearch.github.io/maint/de/'],
})) {
    test(`desktop and mobile family navigation retain every ${lang} destination`, () => {
        const markup = renderSiteHeader({lang, assetBase: 'assets/site-shell', languageUrls: {en: './', de: 'de/'}, active: null, contactHref: 'https://www.netresearch.de/kontakt/'});
        const links = [...markup.matchAll(/class="nr-shell-link" href="([^"]+)"/g)].map(match => match[1]);
        assert.deepEqual(links, [...expected, ...expected]);
        assert.deepEqual(verifySiteShell(markup, symbol, lang), []);
        assert.notDeepEqual(verifySiteShell(markup.replace(expected[3], 'https://wrong.test/skills/'), symbol, lang), []);
        assert.notDeepEqual(verifySiteShell(markup.replace('nr-shell-menu-panel', 'missing-menu'), symbol, lang), []);
    });
}
