.PHONY: up start down restart install install-all install-v13 install-v14 sync test test-unit test-func test-arch test-js test-e2e test-mutation test-all coverage lint lint-fix phpstan ci docs

# === Environment ===
up: start install-all docs  ## Full setup: DDEV + all TYPO3 versions + docs
	@echo ""
	@echo "Ready: https://nr-mcp-agent.ddev.site"

start:  ## Start DDEV
	ddev start

down:  ## Stop DDEV
	ddev stop

restart:  ## Restart DDEV
	ddev restart

install: install-v14  ## Install default TYPO3 version (v14)

install-all:  ## Install all TYPO3 versions (v13 + v14)
	ddev install-all

install-v13:  ## Install TYPO3 v13
	ddev install-v13

install-v14:  ## Install TYPO3 v14
	ddev install-v14

sync:  ## Re-sync extension after code changes
	ddev exec -d /var/www/html/v13 vendor/bin/typo3 extension:setup 2>/dev/null || true
	ddev exec -d /var/www/html/v13 vendor/bin/typo3 cache:flush 2>/dev/null || true
	ddev exec -d /var/www/html/v14 vendor/bin/typo3 extension:setup
	ddev exec -d /var/www/html/v14 vendor/bin/typo3 cache:flush

# === Testing (full pyramid) ===
test: test-unit test-func test-arch  ## Run all PHP tests

test-unit:  ## Run unit tests
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c Build/phpunit.xml --testsuite unit

test-func:  ## Run functional tests
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c Build/phpunit.xml --testsuite functional

test-arch:  ## Run architecture tests (phpat)
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c Build/phpunit.xml --testsuite architecture

test-js:  ## Run Jest tests (Lit Elements)
	ddev exec -d /var/www/nr_mcp_agent npx jest --coverage

test-e2e:  ## Run Playwright E2E tests
	ddev exec -d /var/www/nr_mcp_agent npx playwright test --config=Build/tests/playwright/playwright.config.ts

test-mutation:  ## Run mutation testing (Infection)
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/infection --min-msi=70 --threads=4

test-all: test test-js test-e2e  ## Run entire test pyramid

coverage:  ## Generate HTML coverage report
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c Build/phpunit.xml --coverage-html=.Build/coverage

# === Quality ===
lint:  ## Check code style
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/php-cs-fixer fix --dry-run --diff

lint-fix:  ## Fix code style
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/php-cs-fixer fix

phpstan:  ## Static analysis
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon

ci: lint phpstan test test-js  ## Run CI checks (without E2E — those run separately)

# === Documentation ===
docs:  ## Render documentation
	ddev exec -d /var/www/nr_mcp_agent docker run --rm -v .:/project ghcr.io/typo3-documentation/render-guides:latest --config=Documentation 2>/dev/null || echo "Docs render requires Docker-in-Docker or local render"
