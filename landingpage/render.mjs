/**
 * Renders the product page and publishes the project manifest.
 *
 * The Pages root used to be the rendered TYPO3 documentation. That is the right
 * material for someone already implementing the extension and the wrong first
 * screen for someone deciding whether to evaluate it at all — and it never said
 * anywhere that this is alpha software.
 *
 * The split:
 *
 *   /        this product and evaluation page, English
 *   /de/     the same page in German
 *   /docs/   the rendered technical documentation, unchanged
 *
 * Maturity is written in exactly one place, landingpage/project.json, and every
 * page reads it from there. main_version comes from ext_emconf.php, the latest
 * release from the releases API, and the TYPO3 and PHP ranges from composer.json.
 *
 *   node landingpage/render.mjs
 */

import {readFile, writeFile, mkdir, cp} from 'node:fs/promises';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..');
const output = resolve(root, '.Build/site');

const SITE_URL = process.env.PAGES_ORIGIN
    ? `${process.env.PAGES_ORIGIN.replace(/\/$/, '')}${process.env.PAGES_BASE_PATH || '/'}`
    : 'https://netresearch.github.io/t3x-nr-mcp-agent/';

const CONTACT_BASE = 'https://www.netresearch.de/kontakt/';
const LANGS = ['en', 'de'];
const PATHS = {en: '', de: 'de/'};

function contactUrl(position) {
    const params = new URLSearchParams({
        utm_source: 'github-pages',
        utm_medium: 'referral',
        utm_campaign: 'nr-mcp-agent',
        utm_content: position,
    });
    return `${CONTACT_BASE}?${params}`;
}

/**
 * A release tag from the API is network data. It ends up in the manifest, in the
 * page and in a file path, so it is validated rather than trusted: anything that
 * is not a plain semantic-version tag is treated as no release at all.
 */
const TAG_PATTERN = /^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/;
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

const ESCAPES = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};
const e = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ESCAPES[c]);

const readJson = async (path) => JSON.parse(await readFile(path, 'utf8'));

async function buildManifest() {
    const editorial = await readJson(resolve(here, 'project.json'));
    delete editorial._comment;

    const emconf = await readFile(resolve(root, 'ext_emconf.php'), 'utf8');
    const mainVersion = emconf.match(/'version'\s*=>\s*'([^']+)'/)?.[1];
    if (!mainVersion) throw new Error('render: no version in ext_emconf.php');

    const composer = await readJson(resolve(root, 'composer.json'));
    const versions = (constraint) => [...String(constraint || '').matchAll(/(\d+\.\d+)/g)].map((m) => m[1]);

    let latestRelease = null;
    let releaseDate = null;
    try {
        const headers = {Accept: 'application/vnd.github+json'};
        if (process.env.GITHUB_TOKEN) headers.Authorization = `Bearer ${process.env.GITHUB_TOKEN}`;
        const response = await fetch(
            'https://api.github.com/repos/netresearch/t3x-nr-mcp-agent/releases/latest',
            {headers, signal: AbortSignal.timeout(15000)},
        );
        if (response.ok) {
            const payload = await response.json();
            const tag = String(payload.tag_name ?? '');
            const published = String(payload.published_at ?? '').slice(0, 10);
            latestRelease = TAG_PATTERN.test(tag) ? tag : null;
            releaseDate = latestRelease && DATE_PATTERN.test(published) ? published : null;
            if (tag && !latestRelease) {
                process.stderr.write(`render: ignoring release tag that is not a version: ${JSON.stringify(tag)}\n`);
            }
        }
    } catch (error) {
        process.stderr.write(`render: latest release unavailable (${error.message})\n`);
    }

    return {
        manifest_version: 1,
        name: 'nr-mcp-agent',
        slug: new URL(SITE_URL).pathname,
        main_version: mainVersion,
        latest_release: latestRelease,
        release_date: releaseDate,
        docs_version: mainVersion,
        typo3_versions: versions(composer.require?.['typo3/cms-core']),
        php_versions: versions(composer.require?.php),
        ...editorial,
    };
}

const logo = `<svg class="brand__logo" viewBox="-75 -75 440 440" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Netresearch">
        <title>Netresearch DTT GmbH</title>
        <g>
          <path fill="#2F99A4" d="M209.6,0V31.62h32.77a26.38,26.38,0,0,1,26.44,26.43V242a26.38,26.38,0,0,1-26.44,26.44H209.6V300h47.93a42.77,42.77,0,0,0,42.86-42.86V42.89A42.76,42.76,0,0,0,257.53,0ZM43.25,0A42.76,42.76,0,0,0,.39,42.89V257.18A42.76,42.76,0,0,0,43.25,300H91.18V268.46H58.4A26.38,26.38,0,0,1,32,242v-184A26.37,26.37,0,0,1,58.4,31.62H91.18V0Z" transform="translate(-0.39 -0.04)"/>
          <path fill="#585961" d="M221.44,120.41c0-34.48-13.94-57.82-48.93-57.82-26.62,0-48.54,7.74-64.17,26.56l-.7-22.06-28.31.06V232.94h31.59V124.69c7.14-18.38,32.14-34.8,53-34.5,27.38.4,25.2,26.24,26,45.81v96.94h31.58" transform="translate(-0.39 -0.04)"/>
        </g>
      </svg>`;

function capabilityCard(c, manifest) {
    const card = c.capabilityCard;
    const list = (items) => `<ul>${items.map((item) => `<li>${e(item)}</li>`).join('')}</ul>`;
    const rows = [
        [card.labels.intended_purpose, e(manifest.ai.intended_purpose)],
        [card.labels.excluded_uses, list(manifest.ai.excluded_uses)],
        [card.labels.stage, e(c.status.stages[manifest.stage] ?? manifest.stage)],
        [card.labels.models, list(manifest.ai.models)],
        [card.labels.data, list(manifest.ai.data)],
        [card.labels.processing_location, e(manifest.ai.processing_location.map((l) => card.locations[l] ?? l).join(', '))],
        [card.labels.human_oversight, e(manifest.ai.human_oversight)],
        [card.labels.permissions, e(manifest.ai.permissions)],
        [card.labels.logging, e(manifest.ai.logging)],
        [card.labels.retention, e(manifest.ai.retention)],
        [card.labels.cost_control, e(manifest.ai.cost_control)],
        [card.labels.security_controls, list(manifest.ai.security_controls)],
    ];
    return `<dl class="capability-card">
      ${rows.map(([label, value]) => `<div><dt>${e(label)}</dt><dd>${value}</dd></div>`).join('\n      ')}
      <div class="capability-card__limits"><dt>${e(card.labels.known_limitations)}</dt><dd>${list(manifest.ai.known_limitations)}</dd></div>
      <div><dt>${e(card.labels.last_verified)}</dt><dd><time datetime="${e(manifest.last_verified)}">${e(manifest.last_verified)}</time> · ${e(manifest.owner)}</dd></div>
    </dl>`;
}

function jsonLd(c, manifest, canonical) {
    const graph = [
        {
            '@type': 'Organization',
            '@id': `${SITE_URL}#organization`,
            name: 'Netresearch DTT GmbH',
            url: 'https://www.netresearch.de/',
        },
        {
            '@type': 'SoftwareApplication',
            '@id': `${SITE_URL}#software`,
            name: 'nr-mcp-agent',
            url: canonical,
            applicationCategory: 'DeveloperApplication',
            applicationSubCategory: 'TYPO3 CMS Extension',
            description: c.meta.description,
            softwareVersion: manifest.main_version,
            operatingSystem: `TYPO3 ${manifest.typo3_versions.join(' / ')}, PHP ${manifest.php_versions.join(' / ')}+`,
            license: 'https://spdx.org/licenses/GPL-2.0-or-later.html',
            codeRepository: manifest.repository,
            publisher: {'@id': `${SITE_URL}#organization`},
        },
        {
            '@type': 'BreadcrumbList',
            itemListElement: [{'@type': 'ListItem', position: 1, name: c.meta.title, item: canonical}],
        },
    ];
    return JSON.stringify({'@context': 'https://schema.org', '@graph': graph}).replaceAll('<', '\\u003c');
}

function page(c, manifest, lang) {
    const base = lang === 'en' ? '' : '../';
    const canonical = `${SITE_URL}${PATHS[lang]}`;
    const docsUrl = `${SITE_URL}docs/`;
    const stage = c.status.stages[manifest.stage] ?? manifest.stage;

    const cards = (items) => items
        .map((item) => `<article class="card"><h3>${e(item.title)}</h3><p>${e(item.body)}</p></article>`)
        .join('\n        ');

    // Each item carries its own href; the link used to be picked out of a
    // positional array, so a fourth evidence entry would have rendered
    // href="undefined".
    const cardLinks = (items) => items
        .map((item) => `<a class="card card--link" href="${e(item.href)}">`
            + `<h3>${e(item.title)}</h3><p>${e(item.body)}</p>`
            + `<span class="card__meta">${e(item.label)}</span></a>`)
        .join('\n        ');

    return `<!doctype html>
<html lang="${e(c.htmlLang)}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>${e(c.meta.title)}</title>
<meta name="description" content="${e(c.meta.description)}">
<link rel="canonical" href="${e(canonical)}">
<link rel="alternate" hreflang="en" href="${e(SITE_URL)}">
<link rel="alternate" hreflang="de" href="${e(SITE_URL)}de/">
<link rel="alternate" hreflang="x-default" href="${e(SITE_URL)}">

<meta property="og:type" content="website">
<meta property="og:site_name" content="Netresearch DTT GmbH">
<meta property="og:locale" content="${e(c.ogLocale)}">
<meta property="og:title" content="${e(c.meta.title)}">
<meta property="og:description" content="${e(c.meta.description)}">
<meta property="og:url" content="${e(canonical)}">
<meta property="og:image" content="${e(SITE_URL)}assets/og-mcp-agent-${e(c.lang)}.png">
<meta property="og:image:alt" content="${e(c.meta.ogImageAlt)}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="${e(c.meta.title)}">
<meta name="twitter:description" content="${e(c.meta.description)}">
<meta name="twitter:image" content="${e(SITE_URL)}assets/og-mcp-agent-${e(c.lang)}.png">

<link rel="icon" href="${base}assets/icon.svg" type="image/svg+xml">
<link rel="sitemap" href="${e(SITE_URL)}sitemap.xml">
<link rel="stylesheet" href="${base}assets/product.css">

<script type="application/ld+json">${jsonLd(c, manifest, canonical)}</script>
</head>
<body>

<a class="skip-link" href="#main">${e(c.skipToContent)}</a>

<header class="site-header">
  <div class="wrap site-header__inner">
    <!-- Brand rule: the logo appears exactly once, here. -->
    <a class="brand" href="https://www.netresearch.de/" aria-label="Netresearch DTT GmbH">
      ${logo}
    </a>
    <nav class="site-nav" aria-label="${e(c.nav.label)}">
      ${c.nav.items.map((item) => `<a href="${e(item.href)}">${e(item.label)}</a>`).join('\n      ')}
      <a href="${e(docsUrl)}">${e(c.nav.docs)}</a>
      <a href="${e(`${SITE_URL}${PATHS[c.otherLang]}`)}" hreflang="${e(c.otherLang)}" lang="${e(c.otherLang)}">${e(c.otherLabel)}</a>
      <a class="site-nav__repo" href="${e(manifest.repository)}">${e(c.nav.repo)}</a>
    </nav>
  </div>
</header>

<main id="main">

  <section class="hero">
    <div class="wrap">
      <p class="eyebrow">${e(c.hero.eyebrow)}</p>
      <h1>${e(c.hero.title)}</h1>

      <p class="alpha-banner" role="note">
        <span class="status-pill status-pill--${e(manifest.stage)}">${e(stage)}</span>
        ${e(c.hero.alphaNote)}
      </p>

      <p class="hero__lead">${e(c.hero.lead)}</p>
      <p class="hero__cta">
        <a class="btn btn--primary" href="${e(contactUrl('hero'))}" data-cta="business" data-cta-position="hero">${e(c.hero.ctaBusiness)}</a>
        <a class="btn btn--secondary" href="${e(docsUrl)}">${e(c.hero.ctaTechnical)}</a>
      </p>

      <dl class="status-facts" aria-label="${e(c.status.heading)}">
        <div><dt>${e(c.status.stageLabel)}</dt><dd><span class="status-pill status-pill--${e(manifest.stage)}">${e(stage)}</span></dd></div>
        ${manifest.latest_release ? `<div><dt>${e(c.status.releaseLabel)}</dt><dd><a href="${e(manifest.repository)}/releases/tag/${e(manifest.latest_release)}">${e(manifest.latest_release)}</a></dd></div>` : ''}
        <div><dt>${e(c.status.mainLabel)}</dt><dd>${e(manifest.main_version)}</dd></div>
        <div><dt>${e(c.status.verifiedLabel)}</dt><dd><time datetime="${e(manifest.last_verified)}">${e(manifest.last_verified)}</time></dd></div>
        <div><dt>${e(c.status.requirementsLabel)}</dt><dd>TYPO3 ${e(manifest.typo3_versions.join(' / '))} · PHP ${e(manifest.php_versions.join(' / '))}+ · nr-llm</dd></div>
      </dl>
      <p class="status-facts__note">${e(c.status.note)}</p>

      <h2 class="sr-only">${e(c.answers.heading)}</h2>
      <dl class="answers">
        ${c.answers.items.map((item) => `<div><dt>${e(item.q)}</dt><dd>${e(item.a)}</dd></div>`).join('\n        ')}
      </dl>
    </div>
  </section>

  <section id="works" class="section">
    <div class="wrap">
      <h2>${e(c.works.heading)}</h2>
      <p class="section__lead">${e(c.works.lead)}</p>
      <div class="cards">
        ${cards(c.works.items)}
      </div>
    </div>
  </section>

  <section id="not-promised" class="section section--alt">
    <div class="wrap">
      <h2>${e(c.notPromised.heading)}</h2>
      <p class="section__lead">${e(c.notPromised.lead)}</p>
      <ul class="bullets bullets--no">
        ${c.notPromised.items.map((item) => `<li>${e(item)}</li>`).join('\n        ')}
      </ul>
    </div>
  </section>

  <section id="scenarios" class="section">
    <div class="wrap">
      <h2>${e(c.scenarios.heading)}</h2>
      <p class="section__lead">${e(c.scenarios.lead)}</p>
      <ol class="scenario-list">
        ${c.scenarios.items.map((item) => `<li>
          <span class="scenario__step" aria-hidden="true">${e(item.step)}</span>
          <div>
            <h3>${e(item.title)}</h3>
            <p>${e(item.body)}</p>
            <p class="scenario__control">${e(item.control)}</p>
          </div>
        </li>`).join('\n        ')}
      </ol>
    </div>
  </section>

  <section id="permissions" class="section section--alt">
    <div class="wrap">
      <h2 id="permissions-heading">${e(c.permissions.heading)}</h2>
      <p class="section__lead">${e(c.permissions.lead)}</p>
      <div class="table-scroll" tabindex="0">
        <table class="data-table" aria-labelledby="permissions-heading">
          <thead><tr><th scope="col">${e(c.permissions.columns.layer)}</th><th scope="col">${e(c.permissions.columns.effect)}</th></tr></thead>
          <tbody>
          ${c.permissions.rows.map((row) => `<tr><th scope="row">${e(row.layer)}</th><td>${e(row.effect)}</td></tr>`).join('\n          ')}
          </tbody>
        </table>
      </div>
      <h3>${e(c.permissions.cancelHeading)}</h3>
      <p>${e(c.permissions.cancel)}</p>
    </div>
  </section>

  <section id="stack" class="section">
    <div class="wrap">
      <h2>${e(c.stack.heading)}</h2>
      <p class="section__lead">${e(c.stack.lead)}</p>
      <div class="cards">
        ${cards(c.stack.items)}
      </div>
      <p><a class="link-strong" href="${e(c.stack.linkHref)}">${e(c.stack.linkLabel)} &rarr;</a></p>
    </div>
  </section>

  <section id="security" class="section section--alt">
    <div class="wrap">
      <h2>${e(c.security.heading)}</h2>
      <p class="section__lead">${e(c.security.lead)}</p>
      <div class="cards">
        ${cards(c.security.items)}
      </div>
    </div>
  </section>

  <section id="capability-card" class="section">
    <div class="wrap">
      <h2>${e(c.capabilityCard.heading)}</h2>
      <p class="section__lead">${e(c.capabilityCard.intro)}</p>
      ${capabilityCard(c, manifest)}
    </div>
  </section>

  <section id="install" class="section section--alt">
    <div class="wrap">
      <h2>${e(c.install.heading)}</h2>
      <p class="section__lead">${e(c.install.lead)}</p>
      <ol class="flow-list">
        ${c.install.steps.map((step) => `<li><strong>${e(step.title)}</strong><span>${e(step.body)}</span></li>`).join('\n        ')}
      </ol>
    </div>
  </section>

  <section id="evidence" class="section">
    <div class="wrap">
      <h2>${e(c.evidence.heading)}</h2>
      <p class="section__lead">${e(c.evidence.lead)}</p>
      <div class="cards">
        ${cardLinks(c.evidence.items)}
      </div>
      <div class="limits" id="not-claimed">
        <h3>${e(c.evidence.limitsHeading)}</h3>
        <ul>
          ${c.evidence.limits.map((limit) => `<li>${e(limit)}</li>`).join('\n          ')}
        </ul>
      </div>
    </div>
  </section>

  <section id="contact" class="section section--alt cta-band">
    <div class="wrap cta-band__inner">
      <div>
        <h2>${e(c.cta.heading)}</h2>
        <p>${e(c.cta.body)}</p>
      </div>
      <div class="cta-band__actions">
        <a class="btn btn--primary" href="${e(contactUrl('cta-band'))}" data-cta="business" data-cta-position="cta-band">${e(c.cta.business)}</a>
        <a class="btn btn--secondary" href="${e(docsUrl)}">${e(c.cta.technical)}</a>
        <a class="btn btn--secondary" href="${e(manifest.repository)}">${e(c.cta.source)}</a>
      </div>
    </div>
  </section>

</main>

<footer class="site-footer">
  <div class="wrap site-footer__inner">
    <p>
      <a href="https://www.netresearch.de/">${e(c.footer.company)}</a> &middot;
      <a href="${e(contactUrl('footer'))}" data-cta="business" data-cta-position="footer">${e(c.footer.contact)}</a> &middot;
      <a href="${e(docsUrl)}">${e(c.footer.docs)}</a> &middot;
      <a href="https://www.netresearch.de/impressum/" lang="de">${e(c.footer.imprint)}</a> &middot;
      <a href="https://www.netresearch.de/datenschutz/" lang="de">${e(c.footer.privacy)}</a> &middot;
      <a href="${e(manifest.repository)}">${e(c.footer.source)}</a>
    </p>
    <p class="site-footer__meta">
      ${e(c.footer.licence)}
      ${e(c.footer.reviewed)}: <time datetime="${e(manifest.last_verified)}">${e(manifest.last_verified)}</time>.
    </p>
  </div>
</footer>

</body>
</html>
`;
}

const manifest = await buildManifest();

for (const lang of LANGS) {
    const content = await readJson(resolve(here, 'content', `${lang}.json`));
    const target = resolve(output, PATHS[lang], 'index.html');
    await mkdir(dirname(target), {recursive: true});
    await writeFile(target, page(content, manifest, lang), 'utf8');
}

await mkdir(resolve(output, 'assets'), {recursive: true});
await cp(resolve(here, 'assets'), resolve(output, 'assets'), {recursive: true});
await cp(resolve(root, 'Resources/Public/Icons/Extension.svg'), resolve(output, 'assets/icon.svg'));

await writeFile(
    resolve(output, 'project-manifest.json'),
    `${JSON.stringify(manifest, null, 2)}\n`,
    'utf8',
);

const urls = [...LANGS.map((lang) => `${SITE_URL}${PATHS[lang]}`), `${SITE_URL}docs/`];
await writeFile(
    resolve(output, 'sitemap.xml'),
    `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${
        urls.map((url) => `  <url><loc>${url}</loc><lastmod>${manifest.last_verified}</lastmod></url>`).join('\n')
    }\n</urlset>\n`,
    'utf8',
);
await writeFile(
    resolve(output, 'robots.txt'),
    'User-agent: *\nAllow: /\n\nUser-agent: Googlebot\nAllow: /\n\n'
    + 'User-agent: Bingbot\nAllow: /\n\nUser-agent: OAI-SearchBot\nAllow: /\n\n'
    + `Sitemap: ${SITE_URL}sitemap.xml\n`,
    'utf8',
);

process.stdout.write(
    `Rendered 2 product pages into ${output}\n`
    + `Manifest: ${manifest.name} ${manifest.stage}, main ${manifest.main_version}, `
    + `release ${manifest.latest_release ?? 'unknown'}\n`,
);
