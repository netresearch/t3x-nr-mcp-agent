<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 -->

# AGENTS.md — Documentation/

## Overview

RST documentation rendered on docs.typo3.org via `guides.xml`. Structure: `Introduction/`, `Installation/`, `Configuration/`, `Usage/`, `Developer/` (`Architecture.rst`, `AgentLoop.rst`, `Commands.rst`, `Testing.rst`, `ADR/` with ADR-001…014), `Changelog.rst`, `Images/`.

## Setup

- Local render: `make docs` (runs the `ghcr.io/typo3-documentation/render-guides` container; needs Docker)
- CI render: `.github/workflows/docs.yml` calls the shared `netresearch/typo3-ci-workflows` docs reusable — it runs only when `Documentation/**` or the workflow file changes
- **No symlinks inside `Documentation/`** — the TYPO3 docs renderer (Flysystem) aborts on symbolic links; that is why `Documentation/CLAUDE.md` is a regular file, not a symlink

## Build

- `make docs` must render without new warnings before a docs PR is done
- Shared include header: every page starts with `..  include:: /Includes.rst.txt`

## Conventions

- TYPO3 RST directives (`.. list-table::`, `.. code-block::`, `::` literal blocks) — follow the style of existing pages
- One ADR per file in `Developer/ADR/`, named `ADR-NNN-slug.rst`, indexed in `Developer/ADR/Index.rst`
- Do not pin the extension version in prose — `ext_emconf.php` is the single source of truth
- Keep `Changelog.rst` and the root `README.md` consistent when documenting releases

## Security

- Use placeholder values in configuration examples (`your-api-key`, `example.com`) — never real endpoints, keys, or internal hostnames

## Checklist

- [ ] `make docs` renders cleanly
- [ ] New pages are referenced from the relevant `Index.rst` toctree
- [ ] Behavior described matches the code in `../Classes/` (verify, do not assume)
- [ ] Architectural decisions get an ADR, not just prose

## Examples

- Well-formed architecture page: `Developer/Architecture.rst`
- ADR pattern to copy: `Developer/ADR/ADR-002-cli-based-message-processing.rst`

## When stuck

- TYPO3 docs authoring guide: https://docs.typo3.org/m/typo3/docs-how-to-document/main/en-us/
- Rendering config: `guides.xml` in this directory
- Repo-wide rules: `../AGENTS.md`
