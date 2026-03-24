# nr-mcp-agent

<!-- Last Updated: 2026-03-24 -->

## Overview

AI Chat for TYPO3 — integrates a conversational AI assistant into the TYPO3 backend via the Model Context Protocol (MCP). Built on [nr-llm](https://github.com/netresearch/t3x-nr-llm).

> **Proof of concept.** Explores agent-like behavior in the TYPO3 backend. Not production-ready.

## Architecture

| Path | Purpose |
|------|---------|
| `Classes/` | PHP source (Domain, Controller, Service, Command) |
| `Tests/` | Unit, Functional, Architecture tests |
| `Build/` | PHPUnit config, PHPStan config, `runTests.sh` |
| `Configuration/` | TYPO3 TCA, routing, services.yaml |
| `Resources/` | Fluid templates, JS (Lit web components), CSS |
| `Documentation/` | RST docs (rendered on docs.typo3.org) |
| `docs/` | Architecture docs, execution plans |
| `.github/workflows/` | CI (PHP 8.2–8.4 × TYPO3 ^13.4 matrix) |

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
- **Static analysis:** PHPStan Level 10; architecture tests run automatically with PHPStan
- **Docs:** Update `Documentation/` and `README.md` when adding features or changing config
- **Plans:** Multi-file changes use exec-plans in `docs/exec-plans/active/`

## Constraints

- **No `cd` in compound commands** — use absolute paths instead
- **Architecture:** Domain layer must not depend on Controller layer (enforced via PHPAt)
- **`$GLOBALS['BE_USER']`** is set intentionally in CLI workers; do not remove without understanding the TYPO3 CLI authentication flow
- **Proof of concept:** Not production-ready — avoid adding features that assume production stability

## References

- [Architecture](docs/ARCHITECTURE.md) — component map, data flow, dependency rules
- [Active Execution Plans](docs/exec-plans/active/) — multi-file change plans
- [Full Documentation](Documentation/) — RST docs (docs.typo3.org)
- [Testing Guide](Documentation/Developer/Testing.rst) — test pyramid details
- [CI Workflow](.github/workflows/ci.yml) — matrix build configuration
