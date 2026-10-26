// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {renderSiteHeader} from './site-shell/render-site-shell.mjs';
import {hasStatusFacts, verifyBusinessContacts, verifyNotFoundPage, verifySiteShell} from './verify-site-shell.mjs';
import {attrs} from './check-accessibility.mjs';
const symbol = readFileSync(new URL('./site-shell/netresearch-symbol-only.svg', import.meta.url));
const contextFixtures = JSON.parse(readFileSync(new URL('./site-shell-context-fixtures.json', import.meta.url), 'utf8'));
test('header identity and family navigation require real document elements', () => {
    assert.deepEqual(verifySiteShell(contextFixtures.positive, symbol), []);
    assert.notDeepEqual(verifySiteShell(contextFixtures.positive.replace('data-nr-shell', 'data-note="data-nr-shell"'), symbol), []);
    for (const [name, markup] of Object.entries(contextFixtures.negative)) {
        assert.notDeepEqual(verifySiteShell(markup, symbol), [], name);
        assert.deepEqual(verifySiteShell(contextFixtures.positive + markup, symbol), [], name);
    }
});
const recoveryRoot = 'https://netresearch.github.io/example/';
const realRecoveryMetadata = '<meta name="robots" content="noindex,follow"><meta name="viewport" content="width=device-width, initial-scale=1">';
const recoveryHomes = `<a href="${recoveryRoot}" lang="en" hreflang="en">English</a><a href="${recoveryRoot}de/" lang="de" hreflang="de">Deutsch</a>`;
function recoveryFixture(metadata = realRecoveryMetadata, homes = recoveryHomes) {
    const shell = renderSiteHeader({lang: 'en', assetBase: '/example/assets/site-shell',
        languageUrls: {en: recoveryRoot, de: recoveryRoot + 'de/'}, active: null,
        contactHref: 'https://www.netresearch.de/en/contact/?utm_source=fixture&utm_medium=fixture&utm_campaign=fixture&utm_content=header'});
    return `<!doctype html><html lang="en"><head>${metadata}</head><body>${shell}<main><h1>Page not found</h1>${homes}</main>
        <footer><a data-cta="business" data-cta-position="footer" href="https://www.netresearch.de/en/contact/?utm_source=fixture&amp;utm_medium=fixture&amp;utm_campaign=fixture&amp;utm_content=footer">Contact</a></footer></body></html>`;
}
test('recovery metadata must be real and home links must be inside the actual main', () => {
    assert.deepEqual(verifyNotFoundPage(recoveryFixture(), symbol, recoveryRoot), []);
    for (const [name, {markup, error}] of Object.entries(contextFixtures.recovery_metadata)) {
        const found = verifyNotFoundPage(recoveryFixture(markup), symbol, recoveryRoot);
        if (error) assert.ok(found.includes(error), name);
        else assert.deepEqual(found, [], name);
    }
    const noEnglishHome = recoveryFixture(realRecoveryMetadata, `<a href="${recoveryRoot}de/" lang="de" hreflang="de">Deutsch</a>`);
    assert.ok(verifyNotFoundPage(noEnglishHome, symbol, recoveryRoot).includes('404 recovery needs its en project home'));
});
test('status facts remain mandatory when a layout adds a class token', () => {
    assert.ok(hasStatusFacts('<dl class="status-facts nr-hero-facts"></dl>'));
    assert.ok(hasStatusFacts("<dl class='nr-hero-facts status-facts'></dl>"));
    assert.ok(hasStatusFacts('<dl class="status-facts\tnr-hero-facts"></dl>'));
    assert.ok(hasStatusFacts('<dl class="status-facts&#9;nr-hero-facts"></dl>'));
    for (const invalid of ['', '<dl class="nr-hero-facts"></dl>', '<dl class="old-status-facts"></dl>',
        '<!-- <dl class="status-facts"></dl> -->', '<script><dl class="status-facts"></dl></script>',
        '<dl-status class="status-facts"></dl-status>', '<dl data_class="status-facts"></dl>',
        '<dl data-note=" status-facts class " data_class="status-facts"></dl>',
        '<dl class="status-facts\u00a0nr-hero-facts"></dl>', '<dl class="status-facts&#160;nr-hero-facts"></dl>',
        '<dl class="status-facts\u2003nr-hero-facts"></dl>', '<dl class="status-facts\vnr-hero-facts"></dl>']) {
        assert.equal(hasStatusFacts(invalid), false);
    }
});
test('maturity requires real document elements, not quoted or inert examples', () => {
    const cases = [
        [
            "real_dl",
            "<dl class=\"status-facts nr-hero-facts\"></dl>",
            true
        ],
        [
            "quoted_parent_double",
            "<div data-example=\"<dl class='status-facts'>\"></div>",
            false
        ],
        [
            "quoted_parent_single",
            "<div data-example='<dl class=\"status-facts\">'></div>",
            false
        ],
        [
            "quoted_parent_unquoted_class",
            "<div data-example=\"<dl class=status-facts>\"></div>",
            false
        ],
        [
            "real_after_quoted_fake_dl",
            "<div data-example=\"<dl class='status-facts'>\"></div><dl class=\"status-facts\"></dl>",
            true
        ],
        [
            "real_after_fake_script_attr",
            "<div data-example=\"<script>\"></div><dl class=\"status-facts\"></dl></script>",
            true
        ],
        [
            "real_after_fake_style_attr",
            "<div data-example=\"<style>\"></div><dl class=\"status-facts\"></dl></style>",
            true
        ],
        [
            "real_after_fake_comment_attr",
            "<div data-example=\"<!--\"></div><dl class=\"status-facts\"></dl>-->",
            true
        ],
        [
            "textarea_inert",
            "<textarea><dl class=\"status-facts\"></dl></textarea>",
            false
        ],
        [
            "title_inert",
            "<title><dl class=\"status-facts\"></dl></title>",
            false
        ],
        [
            "template_inert",
            "<template><dl class=\"status-facts\"></dl></template>",
            false
        ],
        [
            "template_then_real",
            "<template><dl class=\"other\"></dl></template><dl class=\"status-facts\"></dl>",
            true
        ],
        [
            "script_inert",
            "<script type=\"application/json\"><dl class=\"status-facts\"></dl></script>",
            false
        ],
        [
            "style_inert",
            "<style><dl class=\"status-facts\"></dl></style>",
            false
        ],
        [
            "comment_inert",
            "<!-- <dl class=\"status-facts\"></dl> -->",
            false
        ],
        [
            "textarea_then_real",
            "<textarea><dl class=\"other\"></dl></textarea><dl class=\"status-facts\"></dl>",
            true
        ]
    ];
    for (const [name, markup, expected] of cases) assert.equal(hasStatusFacts(markup), expected, name);
});
test('recovery resource checks distinguish actual elements from quoted examples', () => {
    const root = 'https://netresearch.github.io/example/';
    const invalidAsset = markup => verifyNotFoundPage(markup, symbol, root)
        .some(error => error.includes('404 recovery asset must be absolute within the project'));
    assert.equal(invalidAsset('<img src="https://outside.test/x.svg" alt="">'), true);
    assert.equal(invalidAsset(`<div data-example="<img src='https://outside.test/x.svg' alt=''>"></div>`), false);
    assert.equal(invalidAsset('<template><img src="https://outside.test/x.svg" alt=""></template>'), false);
    assert.equal(invalidAsset('<div data-example="<script>"></div><img src="https://outside.test/x.svg" alt=""></script>'), true);
    assert.equal(invalidAsset('<div data-example="<!--"></div><img src="https://outside.test/x.svg" alt="">-->'), true);
});
test('attribute parsing consumes complete names and quoted values atomically', () => {
    const parsed = attrs('<input data-note=" disabled hidden " data_class="status-facts" aria-label="Name" required disabled=false>');
    assert.equal(parsed.class, undefined);
    assert.equal(parsed.hidden, undefined);
    assert.equal(parsed.data_class, 'status-facts');
    assert.equal(parsed['aria-label'], 'Name');
    assert.equal(parsed.required, '');
    assert.equal(parsed.disabled, 'false');
    assert.equal(attrs('<a href="first" href="second">').href, 'first');
    assert.equal(attrs('<input title="adjacent"class="real" required>').class, 'real');
    assert.equal(attrs('<input =class="fake">').class, undefined);
    assert.equal(attrs('<input src=asset.svg/>').src, 'asset.svg/');
});
// Static examples keep real contact nodes after script-shaped attribute text.
const quotedScriptFixtures = {
    en: `<div data-example="<script>"></div><header data-nr-shell>
        <a class="nr-shell-contact" href="https://www.netresearch.de/en/contact/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=header">Contact</a>
        <a class="nr-shell-menu-contact" href="https://www.netresearch.de/en/contact/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=menu">Contact</a>
        </header><a data-cta="business" href="https://www.netresearch.de/en/contact/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=hero">Hero</a></script>`,
    de: `<div data-example="<script>"></div><header data-nr-shell>
        <a class="nr-shell-contact" href="https://www.netresearch.de/kontakt/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=header">Kontakt</a>
        <a class="nr-shell-menu-contact" href="https://www.netresearch.de/kontakt/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=menu">Kontakt</a>
        </header><a data-cta="business" href="https://www.netresearch.de/kontakt/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=hero">Hero</a></script>`,
};
const inertScriptFixtures = {
    en: `<script type="application/json"><header>
        <a class="nr-shell-contact" href="https://www.netresearch.de/en/contact/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=header">Contact</a>
        <a class="nr-shell-menu-contact" href="https://www.netresearch.de/en/contact/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=menu">Contact</a>
        </header><a data-cta="business" href="https://www.netresearch.de/en/contact/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=hero">Hero</a></script>`,
    de: `<script type="application/json"><header>
        <a class="nr-shell-contact" href="https://www.netresearch.de/kontakt/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=header">Kontakt</a>
        <a class="nr-shell-menu-contact" href="https://www.netresearch.de/kontakt/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=menu">Kontakt</a>
        </header><a data-cta="business" href="https://www.netresearch.de/kontakt/?utm_source=x&amp;utm_medium=x&amp;utm_campaign=x&amp;utm_content=hero">Hero</a></script>`,
};
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
    test(`all marked ${lang} business CTAs are checked even while a valid footer remains`, () => {
        const base = lang === 'de' ? 'https://www.netresearch.de/kontakt/' : 'https://www.netresearch.de/en/contact/';
        const contact = `${base}?utm_source=github-pages&amp;utm_medium=referral&amp;utm_campaign=fixture&amp;utm_content=`;
        const shell = renderSiteHeader({lang, assetBase: 'assets/site-shell', languageUrls: {en: './', de: 'de/'}, active: null, contactHref: contact.replaceAll('&amp;', '&') + 'header'});
        const fixture = href => shell + `<a data-cta="business"${href === null ? '' : ` href="${href}"`}>Hero</a><a data-cta="business" href="${contact}footer">Footer</a>`;
        const valid = fixture(contact + 'hero');
        assert.deepEqual(verifyBusinessContacts(valid, lang), []);
        const unmarked = valid.replaceAll('nr-shell-contact', 'unmarked-contact').replaceAll('nr-shell-menu-contact', 'unmarked-menu');
        const outsideContacts = `<a class="nr-shell-contact" href="${contact}header">Contact</a><a class="nr-shell-menu-contact" href="${contact}menu">Contact</a>`;
        assert.notDeepEqual(verifyBusinessContacts(unmarked + outsideContacts, lang), []);
        assert.deepEqual(verifyBusinessContacts(quotedScriptFixtures[lang], lang), []);
        assert.deepEqual(verifyBusinessContacts(`<div data-example="<!--"></div>${valid}-->`, lang), []);
        const quotedFake = `<div data-example="<a data-cta='business' href='https://wrong.test/'>Example</a>"></div>`;
        assert.deepEqual(verifyBusinessContacts(valid + quotedFake, lang), []);
        assert.deepEqual(verifyBusinessContacts(valid + "<template><a data-cta='business' href='https://wrong.test/'>Example</a></template>", lang), []);
        assert.deepEqual(verifyBusinessContacts(valid.replaceAll('&amp;', '&#38;'), lang), []);
        assert.deepEqual(verifyBusinessContacts(valid.replaceAll('&amp;', '&#x26;').replaceAll('"', "'"), lang), []);
        assert.deepEqual(verifyBusinessContacts(valid.replaceAll('&amp;', '&AMP;'), lang), []);
        assert.notDeepEqual(verifyBusinessContacts(valid.replaceAll('&amp;', '&aMp;'), lang), []);
        assert.notDeepEqual(verifyBusinessContacts(fixture('https://wrong.test/contact/').replace('data-cta="business"', 'data-cta="bu&#115;iness"'), lang), []);
        assert.notDeepEqual(verifyBusinessContacts(fixture(contact.replaceAll('&amp;', '&amp;amp;') + 'hero'), lang), []);
        for (const href of [null, 'https://wrong.test/contact/', contact.replace('https:', 'http:') + 'hero', contact.replace('utm_medium=referral', 'unused=referral') + 'hero', base + '#utm_source=x&utm_medium=x&utm_campaign=x&utm_content=x']) {
            assert.notDeepEqual(verifyBusinessContacts(fixture(href), lang), []);
        }
        assert.notDeepEqual(verifyBusinessContacts(inertScriptFixtures[lang], lang), []);
        assert.notDeepEqual(verifyBusinessContacts(`<!--${valid}-->`, lang), []);
    });
}

test('the unique recovery viewport must be in the head and body robots remain effective', () => {
    const viewport = '<meta name="viewport" content="width=device-width, initial-scale=1">';
    const robots = '<meta name="robots" content="noindex,follow">';
    const source = recoveryFixture();
    const bodyViewport = source.replace(viewport, '').replace('</body>', viewport + '</body>');
    assert.ok(verifyNotFoundPage(bodyViewport, symbol, recoveryRoot).includes('404 recovery requires a responsive viewport'));
    const duplicateViewport = source.replace('</body>', viewport + '</body>');
    assert.ok(verifyNotFoundPage(duplicateViewport, symbol, recoveryRoot).includes('404 recovery requires a responsive viewport'));
    const bodyRobots = source.replace(robots, '').replace('</body>', robots + '</body>');
    assert.deepEqual(verifyNotFoundPage(bodyRobots, symbol, recoveryRoot), []);
});
