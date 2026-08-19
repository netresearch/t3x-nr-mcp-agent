<!-- FOR AI AGENTS - Human readability is a side effect, not a goal -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 | Last verified: 2026-08-19 -->

# AGENTS.md

**Precedence:** The **closest AGENTS.md** to changed files wins. Root holds global defaults only.

## Overview

AI Chat for TYPO3 — integrates a conversational AI assistant into the TYPO3 backend via the Model Context Protocol (MCP). Built on [nr-llm](https://github.com/netresearch/t3x-nr-llm).

> **Proof of concept.** Explores agent-like behavior in the TYPO3 backend. Not production-ready.

- **Package**: `netresearch/nr-mcp-agent` (Composer) / `nr_mcp_agent` (extension key)
- **Namespace**: `Netresearch\NrMcpAgent\`
- **Tech stack**: PHP ^8.2, TYPO3 ^13.4 || ^14.0, nr-llm ^0.30, Lit web components (no build step)
- **Version**: see `ext_emconf.php` (single source of truth — do not pin versions in docs)

## Architecture

| Path | Purpose |
|------|---------|
| `Classes/` | PHP source (Domain, Controller, Service, Command, Mcp, Document) |
| `Tests/` | Unit, Functional, Architecture, JavaScript tests |
| `Build/` | PHPUnit config, PHPStan config, `runTests.sh`, Playwright config |
| `Configuration/` | TYPO3 TCA, backend routes/modules, Services.yaml |
| `Resources/` | Fluid templates, JS (Lit web components), CSS |
| `Documentation/` | RST docs (rendered on docs.typo3.org) |
| `.github/workflows/` | CI (PHP 8.2–8.4 × TYPO3 ^13.4 matrix) |

Component map and enforced dependency rules: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Commands

```bash
# Setup (DDEV)
make up                          # Full setup: DDEV + all TYPO3 versions + docs

# Testing
make test                        # Unit + Functional + Architecture tests
make test-unit                   # Unit tests only
make test-func                   # Functional tests (requires DDEV)
make test-js                     # Jest tests (Lit components)
make test-e2e                    # Playwright E2E tests
make test-mutation               # Mutation testing (Infection)
make test-all                    # Full test pyramid

# Quality
make lint                        # PHP-CS-Fixer dry-run
make lint-fix                    # PHP-CS-Fixer fix
make phpstan                     # PHPStan static analysis
make ci                          # lint + phpstan + test + test-js

# Without DDEV (Docker-based, mirrors CI exactly)
./Build/Scripts/runTests.sh -s unit
./Build/Scripts/runTests.sh -s phpstan
./Build/Scripts/runTests.sh -s cgl
./Build/Scripts/runTests.sh -s mutation
```

## Development

- **Commits:** Conventional Commits format, no Co-Authored-By trailer
- **Code style:** Run `make lint-fix` before every push; fix style in the same commit
- **Tests:** Run after every change — no "done" without green tests
- **Static analysis:** PHPStan Level 10; phpat architecture rules run automatically with PHPStan (`Build/phpstan/phpstan.neon` registers them)
- **Docs:** Update `Documentation/` and `README.md` when adding features or changing config

## Constraints

- **No `cd` in compound commands** — use absolute paths instead
- **Layering (phpat-enforced):** Domain must not depend on Controller, Command, or Mcp; full rule set in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- **`$GLOBALS['BE_USER']`** is set intentionally in CLI workers; do not remove without understanding the TYPO3 CLI authentication flow
- **Proof of concept:** Not production-ready — avoid adding features that assume production stability

## Index of Scoped AGENTS.md

- `./Classes/AGENTS.md` — PHP source: layering, DI, PHPStan level 10 conventions
- `./Tests/AGENTS.md` — test pyramid: unit, functional, architecture, Jest, Playwright
- `./Documentation/AGENTS.md` — RST docs for docs.typo3.org (no symlinks here!)
- `./Resources/AGENTS.md` — Fluid templates, Lit JS modules, CSS, XLIFF
- `./.github/workflows/AGENTS.md` — CI/CD: reusable-workflow callers, template drift model

## References

- [Architecture (agent-facing)](docs/ARCHITECTURE.md) — component map, dependency rules
- [Architecture (docs.typo3.org)](Documentation/Developer/Architecture.rst) — data flow, domain model
- [Architecture Decision Records](Documentation/Developer/ADR/Index.rst) — ADR-001 through ADR-014
- [Testing Guide](Documentation/Developer/Testing.rst) — test pyramid details
- [CI Workflow](.github/workflows/ci.yml) — matrix build configuration
- [Exec plans](docs/exec-plans/README.md) — multi-session task plans

## Commit Signing

Signed commits are required: `git commit -S --signoff`. The `require-signed-commits` ruleset on the default branch rejects unsigned commits at merge time, and the DCO check additionally requires the `Signed-off-by` trailer. Quickest setup is SSH signing — register your SSH key as a *signing key* on your GitHub account, then `git config --global gpg.format ssh && git config --global user.signingkey ~/.ssh/<key>.pub`.

## When Instructions Conflict

Nearest AGENTS.md wins. Explicit user prompts override files.
