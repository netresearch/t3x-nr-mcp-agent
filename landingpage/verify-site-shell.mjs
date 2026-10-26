// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {createHash} from 'node:crypto';
import {documentElements, isInDocumentHead} from './document-markup.mjs';
import {checkAccessibility} from './check-accessibility.mjs';

// Unmodified symbol-only asset from Netresearch branding 3.1.0.
const SYMBOL_SHA256 = 'ca88779a6d008b4606bbe8d45f4640d6967f82b66b302d95b9153dcf2cfb9e29';
const FAMILY_DESTINATIONS = {
    en: ['https://netresearch.github.io/', 'https://netresearch.github.io/projects/', 'https://netresearch.github.io/modularer-ki-stack-page/en/', 'https://netresearch.github.io/claude-code-marketplace/en/', 'https://netresearch.github.io/maint/'],
    de: ['https://netresearch.github.io/de/', 'https://netresearch.github.io/de/projekte/', 'https://netresearch.github.io/modularer-ki-stack-page/', 'https://netresearch.github.io/claude-code-marketplace/de/', 'https://netresearch.github.io/maint/de/'],
};
function classTokens(element) {
    return (element?.attributes.class ?? '').split(/[\t\n\f\r ]+/);
}

/** Match real AST ancestry; template fragments never enter this collection. */
function isDescendant(element, ancestor) {
    if (!ancestor) return false;
    for (let parent = element.node.parentNode; parent; parent = parent.parentNode) {
        if (parent === ancestor.node) return true;
    }
    return false;
}

function verifyFamilyNavigation(elements, header, lang) {
    const errors = [];
    const expected = FAMILY_DESTINATIONS[lang];
    const menus = elements.filter(element => element.name === 'nav' && isDescendant(element, header));
    for (const className of ['nr-shell-desktop', 'nr-shell-menu-panel']) {
        const matching = menus.filter(menu => classTokens(menu).includes(className));
        const links = elements.filter(element => element.name === 'a'
            && isDescendant(element, matching[0]) && classTokens(element).includes('nr-shell-link'))
            .map(element => element.attributes.href);
        if (!expected || matching.length !== 1 || links.length !== expected.length || links.some((href, index) => href !== expected[index])) {
            errors.push(`${className} must retain all five ${lang} family destinations`);
        }
    }
    return errors;
}

/** Verify the authentic, linked brand symbol and real family navigation. */
export function verifySiteShell(html, symbol, lang = 'en') {
    const errors = [];
    const elements = documentElements(html);
    const headers = elements.filter(element => element.name === 'header' && Object.hasOwn(element.attributes, 'data-nr-shell'));
    const logos = elements.filter(element => element.name === 'img'
        && (element.attributes.src ?? '').endsWith('netresearch-symbol-only.svg'));
    if (headers.length !== 1 || logos.length !== 1 || !isDescendant(logos[0], headers[0])) {
        errors.push('exactly one canonical symbol must appear in the shared header');
    }
    const logo = logos[0];
    const owner = logo?.node.parentNode;
    if (!logo || !isDescendant(logo, headers[0]) || owner?.tagName !== 'a'
        || !owner.attrs.some(attribute => attribute.name === 'href' && attribute.value === 'https://www.netresearch.de/')) {
        errors.push('header symbol must link to Netresearch DTT GmbH');
    }
    if (logo?.attributes.width !== '44' || logo?.attributes.height !== '44' || logo?.attributes.alt !== 'Netresearch DTT GmbH') {
        errors.push('header symbol must retain its dimensions and accessible name');
    }
    if (createHash('sha256').update(symbol).digest('hex') !== SYMBOL_SHA256) errors.push('header symbol differs from the authentic brand asset');
    return [...errors, ...verifyFamilyNavigation(elements, headers[0], lang)];
}

function isProjectAsset(asset, siteRoot) {
    if (asset.startsWith('//') || (!asset.startsWith('/') && !/^https?:\/\//i.test(asset))) return false;
    try {
        const root = new URL(siteRoot);
        const target = new URL(asset, root);
        return target.origin === root.origin && target.pathname.startsWith(root.pathname);
    } catch {
        return false;
    }
}

/** Require a real document definition list carrying the HTML class token. */
export function hasStatusFacts(html) {
    return documentElements(html).some(element => element.name === 'dl'
        && (element.attributes.class ?? '').split(/[\t\n\f\r ]+/).includes('status-facts'));
}

function verifyRecoveryAssets(html, siteRoot) {
    const errors = [];
    for (const tag of documentElements(html).filter(element => ['script', 'link', 'img'].includes(element.name))) {
        const asset = tag.attributes[tag.name === 'link' ? 'href' : 'src'];
        if (asset === undefined) continue;
        if (!isProjectAsset(asset, siteRoot)) errors.push(`404 recovery asset must be absolute within the project: ${asset}`);
    }
    return errors;
}

function hasResponsiveViewport(element) {
    const widths = (element.attributes.content ?? '').split(/[;,]/)
        .filter(directive => /^[\t\n\f\r ]*width[\t\n\f\r ]*=/i.test(directive));
    return widths.length === 1
        && /^[\t\n\f\r ]*width[\t\n\f\r ]*=[\t\n\f\r ]*device-width[\t\n\f\r ]*$/i.test(widths[0]);
}

export function verifyNotFoundPage(html, symbol, siteRoot) {
    const errors = [...verifySiteShell(html, symbol, 'en'), ...checkAccessibility(html), ...verifyBusinessContacts(html, 'en', ['footer'])];
    const elements = documentElements(html);
    const metadata = elements.filter(element => element.name === 'meta');
    const robots = metadata.filter(element => element.attributes.name?.toLowerCase() === 'robots');
    if (robots.length !== 1 || robots[0].attributes.content !== 'noindex,follow') errors.push('404 recovery must be noindex');
    const viewport = metadata.filter(element => element.attributes.name?.toLowerCase() === 'viewport');
    if (viewport.length !== 1 || !isInDocumentHead(viewport[0]) || !hasResponsiveViewport(viewport[0])) {
        errors.push('404 recovery requires a responsive viewport');
    }
    const counterparts = elements.filter(element => element.name === 'link'
        && (element.attributes.rel ?? '').toLowerCase().split(/[\t\n\f\r ]+/).some(token => ['canonical', 'alternate'].includes(token)));
    if (counterparts.length) errors.push('404 recovery must not declare an indexable page counterpart');
    const mains = elements.filter(element => element.name === 'main');
    for (const [locale, path] of [['en', ''], ['de', 'de/']]) {
        const matching = elements.filter(element => element.name === 'a' && isDescendant(element, mains[0])
            && element.attributes.href === `${siteRoot}${path}` && element.attributes.lang === locale && element.attributes.hreflang === locale);
        if (mains.length !== 1 || matching.length !== 1) errors.push(`404 recovery needs its ${locale} project home`);
    }
    return [...errors, ...verifyRecoveryAssets(html, siteRoot)];
}


function contactIssues(link, lang) {
    const errors = [];
    let target;
    try {
        target = new URL(link.href ?? '');
    } catch {
        return ['business contact link has a missing or invalid href'];
    }
    const expectedPath = lang === 'de' ? '/kontakt/' : '/en/contact/';
    if (target.origin !== 'https://www.netresearch.de' || target.pathname !== expectedPath) {
        errors.push(`business contact link has the wrong destination or language: ${link.href}`);
    }
    for (const param of ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content']) {
        const values = target.searchParams.getAll(param);
        if (values.length !== 1 || !values[0].trim()) errors.push(`business contact link needs one nonempty ${param}`);
    }
    return errors;
}

export function verifyBusinessContacts(html, lang, requiredPositions = []) {
    const elements = documentElements(html);
    const headers = elements.filter(element => element.name === 'header' && Object.hasOwn(element.attributes, 'data-nr-shell'));
    const anchors = elements.filter(element => element.name === 'a');
    const links = anchors.map(element => element.attributes);
    const isContact = link => ['nr-shell-contact', 'nr-shell-menu-contact'].some(name => (link.class ?? '').split(/[\t\n\f\r ]+/).includes(name));
    const selected = links.filter(link => link['data-cta'] === 'business' || isContact(link));
    const errors = [];
    for (const name of ['nr-shell-contact', 'nr-shell-menu-contact']) {
        const matching = anchors.filter(element => classTokens(element).includes(name));
        if (headers.length !== 1 || matching.length !== 1 || !isDescendant(matching[0], headers[0])) errors.push(`expected one ${name} business contact link`);
    }
    if (!links.some(link => link['data-cta'] === 'business')) errors.push('no marked business CTA remains');
    for (const position of requiredPositions) {
        if (links.filter(link => link['data-cta'] === 'business' && link['data-cta-position'] === position).length !== 1) errors.push(`expected one marked business contact link at ${position}`);
    }
    return [...errors, ...selected.flatMap(link => contactIssues(link, lang))];
}
