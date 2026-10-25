<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Product page

Published at <https://netresearch.github.io/t3x-nr-mcp-agent/>.

| Path | Contents |
| --- | --- |
| `/` | Product and evaluation page, English |
| `/de/` | The same page in German |
| `/docs/` | The rendered TYPO3 documentation, unchanged |

The Pages root used to be the documentation. That is the right material for
someone already implementing the extension and the wrong first screen for
someone deciding whether to evaluate it — and it never said anywhere that this
is alpha software.

```bash
node landingpage/render.mjs          # render both product pages into .Build/site/
node landingpage/redirect-stubs.mjs  # keep old documentation links working
node landingpage/verify.mjs          # gate the result
uv run landingpage/render_og.py      # social cards, after a headline change
```

## Old documentation links

The documentation postprocessor removes docs.typo3.org proxy controls that have
no endpoint on GitHub Pages. It also guards the pinned theme's version-switcher
initializer when that control is absent. An unrecognized initializer fails the
build rather than silently changing another part of the vendor script. The
browser gate covers the documentation and product pages: JavaScript errors,
WCAG 2.2 AA, automated AAA checks, missing assets and narrow-viewport overflow.
A supplemental manual stylesheet darkens the original syntax hues, breadcrumb
and captions; native color-scheme selection controls its light/dark palette.
The generated plain-link table of contents uses native nested lists, while
unrelated application-menu semantics and the official TYPO3 layout stay intact.

Every inbound link to the old documentation root points at a path like
`/Configuration/Index.html`. GitHub Pages has no server-side redirects, so
`redirect-stubs.mjs` writes a meta-refresh stub at each of those paths pointing
into `/docs/`. The stubs carry `noindex, follow` and a canonical to the new
location.

The root is the deliberate exception: `/` is the product page now, and that is
the point of the split.

## Where the facts come from

| Fact | Source |
| --- | --- |
| `main_version` | `ext_emconf.php` on this branch |
| `latest_release`, `release_date` | the GitHub releases API |
| `typo3_versions`, `php_versions` | `composer.json` |
| maturity, owner, review date, AI capability card | `landingpage/project.json` |
| all copy | `landingpage/content/{en,de}.json` |

**Maturity is written in exactly one place**, `landingpage/project.json`. Both
pages, the manifest and the hero's alpha notice read it from there, so promoting
the project out of alpha is one edit, not a search across files.

`render.mjs` publishes `project-manifest.json` at the site root. The portfolio
aggregates it into <https://netresearch.github.io/projects.json>, so every
Netresearch page that shows a status for nr-mcp-agent reads it from there.

## Build gate

`landingpage/verify.mjs` fails on a manifest that disagrees with
`ext_emconf.php`, a version rendered that the manifest does not know, an alpha
project whose hero carries no alpha notice, a missing canonical / description /
`x-default` hreflang / `og:image` / `twitter:card` / JSON-LD block, invalid
JSON-LD, a contact link missing a UTM parameter, a logo appearing other than
once, a missing status block, a capability card without limitations, a missing
"what is not promised" list, a missing "what is not claimed" section, a
third-party asset request, or a `/docs/` tree that did not render.

The markup-specific product rules exclude the docs tree. The browser gate
checks both product pages and the postprocessed documentation without a
report-only exception.

## Page navigation and appearance

The shared Netresearch header provides the portfolio, projects, AI stack and
impact routes, DE/EN links and an appearance disclosure with native System,
Light and Dark radio buttons. The global menu uses native details on mobile;
it remains usable without JavaScript. Escape closes an enhanced disclosure and
returns focus to its summary. Product-specific links stay in the local bar below.

The operating system controls appearance by default; explicit preferences use
`nr-theme`. An explicit System choice overrides older preferences. Only appearance
is stored: no question, answer or page content is saved by these controls.
The canonical source is copied unchanged into `site-shell/`; the build publishes
only its runtime CSS, JavaScript and authentic symbol asset. The static gate
checks that the symbol is unchanged, appears once and links to Netresearch.

## URL regression checks

Run `npm run test:landingpage` to render both languages at a nested base path with
and without its final slash, and at the host root. The test substitutes only the
external release API and checks the actual generated canonical, hreflang, social
image and sitemap URLs. The artifact gate also checks metadata resources against
files in the generated site, so a present but broken URL no longer passes.
