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
node landingpage/render.mjs   # render both product pages into .Build/site/
node landingpage/verify.mjs   # gate the result
uv run landingpage/render_og.py   # social cards, after a headline change
```

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

The docs tree itself is out of scope for the gate: render-guides writes it, and
gating someone else's output on this page's rules would fail on markup this
repository does not produce.
