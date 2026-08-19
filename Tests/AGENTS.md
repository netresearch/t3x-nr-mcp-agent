<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 -->

# AGENTS.md — Tests/

## Overview

Full test pyramid: `Unit/` and `Functional/` (PHPUnit, TYPO3 testing-framework), `Architecture/` (phpat rules, executed through PHPStan), `JavaScript/` (Jest for the Lit components), `E2E/` (legacy Playwright spec — the active Playwright suite lives in `../Build/tests/playwright/specs/`), `Fixtures/` (CSV fixtures + sample documents), `Build/` (PHPUnit bootstrap files).

## Setup

- PHP suites are defined in `../Build/phpunit.xml` (testsuites: unit, functional — no `architecture` testsuite is defined there; the phpat rules run through PHPStan instead)
- Functional tests need a TYPO3 instance: DDEV (`make up`) locally; CI uses SQLite
- Jest config: `../jest.config.js` (`testMatch: **/Tests/JavaScript/**/*.test.js`, jsdom)
- Playwright config: `../Build/tests/playwright/playwright.config.ts` (`testDir: ./specs`)

## Tests

```bash
make test           # unit + functional + architecture (DDEV)
make test-unit      # or: composer ci:tests:unit
make test-func      # or: composer ci:tests:functional
make test-js        # Jest with coverage
make test-e2e       # Playwright (requires running TYPO3)
make test-mutation  # Infection, config ../infection.json.dist
../Build/Scripts/runTests.sh -s unit   # Docker-based, mirrors CI
```

## Conventions

- `Unit/` mirrors the `Classes/` directory structure — put the test where the class lives
- One resource per test: fresh instances in `setUp()`, no shared mutable fixtures
- Functional fixtures are CSV files in `Fixtures/` (`be_users.csv`, `tx_nrmcpagent_conversation.csv`)
- Architecture rules live in `Architecture/LayerDependencyTest.php` and `Architecture/DocumentExtractorArchitectureTest.php`; they are registered as `phpat.test` services in `../Build/phpstan/phpstan.neon` and fail `make phpstan`, not `make test-unit`

## Security

- Never commit real API keys or tokens in fixtures — use placeholder values
- Tests asserting error handling must verify sanitization (no secrets in `error_message`)

## Checklist

- [ ] New/changed behavior has a test in the matching suite
- [ ] Test output pristine — expected errors are asserted, not printed
- [ ] Mutation score not degraded (`make test-mutation` for touched code)
- [ ] Never weaken or delete a failing test to get green

## Examples

- Jest component test: `JavaScript/chat-panel-popout.test.js` (with `__mocks__/`)
- Functional controller test: `Functional/Controller/`
- phpat rule with rationale: `Architecture/LayerDependencyTest.php` (`->because(...)`)

## When stuck

- Test pyramid rationale: `../Documentation/Developer/Testing.rst`
- CI matrix and how suites run remotely: `../.github/workflows/AGENTS.md`
- Docker runner options: `../Build/Scripts/runTests.sh` (`-s unit|phpstan|cgl|mutation|architecture|lint|composer|clean`)
