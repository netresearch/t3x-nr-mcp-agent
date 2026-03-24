# nr-mcp-agent

AI Chat for TYPO3 — integrates a conversational AI assistant into the TYPO3 backend via the Model Context Protocol (MCP). Built on [nr-llm](https://github.com/netresearch/t3x-nr-llm).

> **Proof of concept.** Explores agent-like behavior in the TYPO3 backend. Not production-ready.

## Repo Structure

- `Classes/` — PHP source (Domain, Controller, Service, Command)
- `Tests/` — Unit, Functional, Architecture tests
- `Build/` — PHPUnit config, PHPStan config, `runTests.sh`
- `Configuration/` — TYPO3 TCA, routing, services.yaml
- `Resources/` — Fluid templates, JS (Lit web components), CSS
- `Documentation/` — RST docs (rendered on docs.typo3.org)
- `docs/` — Architecture docs, execution plans
- `.github/workflows/` — CI (PHP 8.2–8.4 × TYPO3 ^13.4 matrix)

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

# Verify harness consistency
bash /home/the/.claude/plugins/cache/netresearch-claude-code-marketplace/agent-harness/1.0.0/skills/agent-harness/scripts/verify-harness.sh --status
```

## Rules

- **No `cd` in compound commands** — use absolute paths instead
- **Commits:** Conventional Commits format, no Co-Authored-By trailer
- **Code style:** Run `make lint-fix` before every push; fix style in the same commit
- **Tests:** Run after every change. Use `superpowers:verification-before-completion` — no "done" without green tests
- **Static analysis:** PHPStan Level 9 minimum; architecture tests run automatically with PHPStan
- **Docs:** Update `Documentation/` and `README.md` when adding features or changing config
- **Architecture:** Domain layer must not depend on Controller layer (enforced via PHPAt)
- **Plans:** Multi-file changes use exec-plans in `docs/exec-plans/active/`

## References

- [Architecture](docs/ARCHITECTURE.md)
- [Active Execution Plans](docs/exec-plans/active/)
- [Full Documentation](Documentation/)
- [Testing Guide](Documentation/Developer/Testing.rst)
- [CI Workflow](.github/workflows/ci.yml)
