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
