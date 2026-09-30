<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Contributing

## Prerequisites

- PHP 8.2+, Composer 2, DDEV (optional but recommended)
- Node.js 20+ (for JS/E2E tests)

## Setup

```bash
composer install
make up   # starts DDEV and installs TYPO3
```

## Workflow

1. Create a feature branch from `main`
2. Make changes — run `make ci` to verify
3. Commit using [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, …)
4. Open a pull request against `main`

## Tests

```bash
make test          # unit + functional + architecture
make test-js       # Lit component tests
make test-e2e      # Playwright (requires running TYPO3)
make test-mutation # mutation score check
```

All tests must pass before merging. See [Testing Guide](Documentation/Developer/Testing.rst) for details.

## Code quality

```bash
make lint-fix   # PHP-CS-Fixer
make phpstan    # PHPStan level 10
```

Fix style and analysis issues in the same commit as the code change.

## Reporting issues

Use [GitHub Issues](https://github.com/netresearch/t3x-nr-mcp-agent/issues). Report vulnerabilities as described in the [Netresearch security policy](https://github.com/netresearch/.github/blob/main/SECURITY.md), not in public issues. The security expectations, trust boundaries and the checks behind them are in [docs/SECURITY-ASSURANCE.md](docs/SECURITY-ASSURANCE.md).

## Governance and policies

This extension follows the organisation-wide Netresearch policies:

- [Governance](https://github.com/netresearch/.github/blob/main/GOVERNANCE.md): ownership, roles, how decisions are made and conflicts resolved.
- [Roadmap](https://github.com/netresearch/.github/blob/main/ROADMAP.md): planned and excluded work for the next twelve months.
- [Handling of dependency and code analysis findings](https://github.com/netresearch/.github/blob/main/SECURITY.md#handling-of-dependency-and-code-analysis-findings): which vulnerability, licence and static-analysis findings must be fixed, by when, and how exceptions are recorded.
- [Secret management](https://github.com/netresearch/.github/blob/main/SECURITY.md#secret-management): where CI and release credentials are stored, who may use them, how committed secrets are detected, and when secrets are rotated.
- [Access roster](https://github.com/netresearch/.github/blob/main/docs/access-roster.md): the people and teams with administrative or write access to this repository.

Checks that run on every pull request in this repository:

- `.github/workflows/checks.yml`: Composer Audit (fails on any advisory for an installed package) and Opengrep SAST (fails on findings of severity WARNING or higher), both through `typo3-ci-workflows`' `security.yml`; Dependency Review (fails on newly added dependencies with a vulnerability of severity high or higher); PHP License Audit (`license-check.yml`, fails on an SSPL or BSL licensed Composer dependency); CodeQL for the JavaScript and the workflow files (CodeQL has no PHP analysis; PHPStan and Opengrep cover the PHP code); Betterleaks secret scanning; zizmor for the workflow files; the pull request quality check. The fuzz job finds no fuzz suite in `Build/phpunit.xml` and is skipped.
- `.github/workflows/ci.yml`: PHP lint, code style (PHP-CS-Fixer, `.php-cs-fixer.dist.php`), PHPStan (level 10, `Build/phpstan/phpstan.neon`, including the architecture rules in `Tests/Architecture/`), Rector, unit and functional (SQLite) tests on PHP 8.2 to 8.4 with TYPO3 13.4, and the documentation rendering of `Documentation/`.
- `.github/workflows/js-tests.yml`: the Jest tests in `Tests/JavaScript/`.
- `.github/workflows/e2e.yml`: the Playwright tests in `Build/tests/playwright/specs/` against TYPO3 13.4, once without and once with an nr-llm Task configured.
- `.github/workflows/mutation.yml`: Infection with the thresholds in `infection.json.dist`.
- `.github/workflows/harness-verify.yml`: `Build/Scripts/verify-harness.sh`.
- `.github/workflows/dco.yml`: the `Signed-off-by` trailer on every commit.

## Commit Signing

All commits must be cryptographically signed and carry a DCO sign-off: `git commit -S --signoff`. The `require-signed-commits` ruleset on the default branch enforces the signature (the "Verified" badge on GitHub); the DCO check enforces the `Signed-off-by` trailer — these are two different things and both are required. Quickest setup is SSH signing: register your SSH key as a *signing key* on your GitHub account, then `git config --global gpg.format ssh && git config --global user.signingkey ~/.ssh/<key>.pub`.
