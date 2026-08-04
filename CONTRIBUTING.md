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

Use [GitHub Issues](https://github.com/netresearch/t3x-nr-mcp-agent/issues).

## Commit Signing

All commits must be cryptographically signed and carry a DCO sign-off: `git commit -S --signoff`. The `require-signed-commits` ruleset on the default branch enforces the signature (the "Verified" badge on GitHub); the DCO check enforces the `Signed-off-by` trailer — these are two different things and both are required. Quickest setup is SSH signing: register your SSH key as a *signing key* on your GitHub account, then `git config gpg.format ssh && git config user.signingkey ~/.ssh/<key>.pub`.
