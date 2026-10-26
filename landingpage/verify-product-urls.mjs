// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {stat} from 'node:fs/promises';
import {resolve} from 'node:path';
import {documentElements, isInDocumentHead} from './document-markup.mjs';

/** Verify published URLs against the deployment origin and real build files. */
export async function verifyProductUrls(html, pageName, output, slug, deploymentOrigin) {
    const errors = [];
    if (!slug.startsWith('/') || slug.startsWith('//') || !slug.endsWith('/')) {
        errors.push('manifest slug must be an absolute project path ending with a slash');
    }
    const origin = new URL(deploymentOrigin).origin;
    const site = new URL(slug, origin);
    if (site.origin !== origin) errors.push('manifest slug must stay on the deployment origin');
    const path = pageName.replace(/index\.html$/, '').replaceAll('\\', '/');
    const expected = new URL(path, site).href;
    const links = documentElements(html).filter(element => element.name === 'link' && isInDocumentHead(element));
    const hasRel = (link, value) => (link.attributes.rel ?? '').toLowerCase().split(/[\t\n\f\r ]+/).includes(value);
    const canonicals = links.filter(link => hasRel(link, 'canonical'));
    if (canonicals.length !== 1 || canonicals[0].attributes.href !== expected) {
        errors.push(`exactly one canonical in the document head must identify ${expected}`);
    }
    for (const [lang, target] of [['en', site.href], ['de', new URL('de/', site).href], ['x-default', site.href]]) {
        const matching = links.filter(link => hasRel(link, 'alternate') && link.attributes.hreflang?.toLowerCase() === lang);
        if (matching.length !== 1 || matching[0].attributes.href !== target) {
            errors.push(`exactly one hreflang ${lang} in the document head must identify ${target}`);
        }
    }
    const resources = [...html.matchAll(/<(?:link[^>]*rel="sitemap"[^>]*href|meta[^>]*(?:property="og:image"|name="twitter:image")[^>]*content)="([^"]+)"/g)];
    const resourceErrors = await Promise.all(resources.map(async ([, value]) => {
        // A relative URL on /de/ is relative to that page, not the site root.
        const url = new URL(value, expected);
        if (url.origin !== origin || !url.pathname.startsWith(site.pathname)) {
            return `metadata resource leaves the project base path: ${value}`;
        }
        try {
            const resource = await stat(resolve(output, url.pathname.slice(site.pathname.length)));
            if (!resource.isFile()) return `metadata resource is not a regular file: ${value}`;
        } catch {
            return `metadata resource does not exist in the build: ${value}`;
        }
        return null;
    }));
    errors.push(...resourceErrors.filter(Boolean));
    return errors;
}
