# Extension Root Restructuring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the TYPO3 extension from `packages/nr_mcp_agent/` to the repository root, matching the structure of nr-llm for TER publishing.

**Architecture:** The git repository root becomes the extension root directly (like nr-llm). All extension files (Classes/, Configuration/, composer.json, etc.) live at the top level. Quality-tool configs are reorganized into `Build/` subdirectories. DDEV mounts the repo root at `/var/www/nr_mcp_agent`, so mount paths stay unchanged — only `EXTENSION_PATH` in install scripts changes.

**Tech Stack:** git mv (preserves history), PHP/Composer, PHPStan, PHPUnit, PHP-CS-Fixer, Rector, CaptainHook, DDEV

---

## File Map

After completing this plan, the repository root will contain:

```
Classes/                        ← moved from packages/nr_mcp_agent/Classes/
Configuration/                  ← moved from packages/nr_mcp_agent/Configuration/
Documentation/                  ← moved from packages/nr_mcp_agent/Documentation/
Resources/                      ← moved from packages/nr_mcp_agent/Resources/
Tests/                          ← moved from packages/nr_mcp_agent/Tests/
Build/
  captainhook.json              ← new (git hooks config)
  phpstan/
    phpstan.neon                ← moved + updated from packages/nr_mcp_agent/phpstan.neon
  phpunit.xml                   ← moved + updated from packages/nr_mcp_agent/phpunit.xml
  rector/
    rector.php                  ← moved + updated from packages/nr_mcp_agent/rector.php
  Scripts/
    runTests.sh                 ← new (adapted from nr-llm)
    check-tag-version.sh        ← new (copied from nr-llm)
  tests/                        ← moved from packages/nr_mcp_agent/Build/tests/
    playwright/
      playwright.config.ts
      specs/
composer.json                   ← moved + updated from packages/nr_mcp_agent/
composer.lock                   ← moved from packages/nr_mcp_agent/
ext_emconf.php                  ← moved from packages/nr_mcp_agent/
ext_conf_template.txt           ← moved from packages/nr_mcp_agent/
ext_tables.sql                  ← moved from packages/nr_mcp_agent/
.php-cs-fixer.dist.php          ← moved (unchanged — __DIR__ refs still valid at root)
package.json                    ← moved from packages/nr_mcp_agent/
package-lock.json               ← moved from packages/nr_mcp_agent/
README.md                       ← moved from packages/nr_mcp_agent/
.gitignore                      ← moved + updated from packages/nr_mcp_agent/
.gitattributes                  ← new (TER export excludes)
.editorconfig                   ← already at root (keep as-is)
Makefile                        ← already at root (update test/lint paths)
.ddev/                          ← already at root (update install scripts)
```

**Deleted:**
- `packages/` directory (empty after moves)
- `Documentation-GENERATED-temp/` (generated artifact)
- `docs/superpowers/` contents won't be deleted but will be excluded via `.gitattributes`

---

### Task 1: Move extension directories to repo root

**Files:**
- Move: `packages/nr_mcp_agent/Classes/` → `Classes/`
- Move: `packages/nr_mcp_agent/Configuration/` → `Configuration/`
- Move: `packages/nr_mcp_agent/Documentation/` → `Documentation/`
- Move: `packages/nr_mcp_agent/Resources/` → `Resources/`
- Move: `packages/nr_mcp_agent/Tests/` → `Tests/`

- [ ] **Step 1: Move directories with git mv**

```bash
cd /srv/projects/nr-mcp-agent
git mv packages/nr_mcp_agent/Classes Classes
git mv packages/nr_mcp_agent/Configuration Configuration
git mv packages/nr_mcp_agent/Documentation Documentation
git mv packages/nr_mcp_agent/Resources Resources
git mv packages/nr_mcp_agent/Tests Tests
```

- [ ] **Step 2: Verify moves**

```bash
ls /srv/projects/nr-mcp-agent/
```

Expected: `Classes/`, `Configuration/`, `Documentation/`, `Resources/`, `Tests/` visible at root.

- [ ] **Step 3: Commit**

```bash
git -C /srv/projects/nr-mcp-agent add -A
git -C /srv/projects/nr-mcp-agent commit -m "refactor: move extension dirs to repo root"
```

---

### Task 2: Move root extension files to repo root

**Files:**
- Move: `packages/nr_mcp_agent/composer.json` → `composer.json`
- Move: `packages/nr_mcp_agent/composer.lock` → `composer.lock`
- Move: `packages/nr_mcp_agent/ext_emconf.php` → `ext_emconf.php`
- Move: `packages/nr_mcp_agent/ext_conf_template.txt` → `ext_conf_template.txt`
- Move: `packages/nr_mcp_agent/ext_tables.sql` → `ext_tables.sql`
- Move: `packages/nr_mcp_agent/README.md` → `README.md`
- Move: `packages/nr_mcp_agent/package.json` → `package.json`
- Move: `packages/nr_mcp_agent/package-lock.json` → `package-lock.json`
- Move: `packages/nr_mcp_agent/.gitignore` → `.gitignore`
- Move: `packages/nr_mcp_agent/.php-cs-fixer.dist.php` → `.php-cs-fixer.dist.php`

- [ ] **Step 1: Move files with git mv**

```bash
cd /srv/projects/nr-mcp-agent
git mv packages/nr_mcp_agent/composer.json composer.json
git mv packages/nr_mcp_agent/composer.lock composer.lock
git mv packages/nr_mcp_agent/ext_emconf.php ext_emconf.php
git mv packages/nr_mcp_agent/ext_conf_template.txt ext_conf_template.txt
git mv packages/nr_mcp_agent/ext_tables.sql ext_tables.sql
git mv packages/nr_mcp_agent/README.md README.md
git mv packages/nr_mcp_agent/package.json package.json
git mv packages/nr_mcp_agent/package-lock.json package-lock.json
git mv packages/nr_mcp_agent/.gitignore .gitignore
git mv packages/nr_mcp_agent/.php-cs-fixer.dist.php .php-cs-fixer.dist.php
```

- [ ] **Step 2: Commit**

```bash
git -C /srv/projects/nr-mcp-agent add -A
git -C /srv/projects/nr-mcp-agent commit -m "refactor: move extension root files to repo root"
```

---

### Task 3: Reorganize Build/ directory

**Files:**
- Move + restructure: `packages/nr_mcp_agent/phpstan.neon` → `Build/phpstan/phpstan.neon`
- Move + restructure: `packages/nr_mcp_agent/phpunit.xml` → `Build/phpunit.xml`
- Move + restructure: `packages/nr_mcp_agent/rector.php` → `Build/rector/rector.php`
- Move: `packages/nr_mcp_agent/Build/tests/` → `Build/tests/`

- [ ] **Step 1: Create Build/ subdirectories and move files**

```bash
cd /srv/projects/nr-mcp-agent
mkdir -p Build/phpstan Build/rector Build/Scripts
git mv packages/nr_mcp_agent/phpstan.neon Build/phpstan/phpstan.neon
git mv packages/nr_mcp_agent/phpunit.xml Build/phpunit.xml
git mv packages/nr_mcp_agent/rector.php Build/rector/rector.php
git mv packages/nr_mcp_agent/Build/tests Build/tests
```

- [ ] **Step 2: Update `Build/phpstan/phpstan.neon` — fix includes and paths**

Replace the content of `/srv/projects/nr-mcp-agent/Build/phpstan/phpstan.neon`:

```yaml
includes:
    - %currentWorkingDirectory%/.Build/vendor/saschaegerer/phpstan-typo3/extension.neon
    - %currentWorkingDirectory%/.Build/vendor/phpat/phpat/extension.neon

parameters:
    level: 10
    paths:
        - ../../Classes
        - ../../Tests/Architecture
    treatPhpDocTypesAsCertain: false
    scanDirectories:
        - ../../Tests/Architecture

services:
    -
        class: Netresearch\NrMcpAgent\Tests\Architecture\LayerDependencyTest
        tags:
            - phpat.test
```

- [ ] **Step 3: Update `Build/phpunit.xml` — fix bootstrap and directory paths**

Replace the content of `/srv/projects/nr-mcp-agent/Build/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11/phpunit.xsd"
         backupGlobals="true"
         bootstrap="../Tests/Build/Bootstrap.php"
         cacheResult="false"
         colors="true"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>../Tests/Unit</directory>
        </testsuite>
        <testsuite name="functional">
            <directory>../Tests/Functional</directory>
        </testsuite>
        <testsuite name="architecture">
            <directory>../Tests/Architecture</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="typo3DatabaseDriver" value="pdo_sqlite"/>
    </php>
    <source>
        <include>
            <directory suffix=".php">../Classes</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 4: Update `Build/rector/rector.php` — fix __DIR__ paths**

Replace content of `/srv/projects/nr-mcp-agent/Build/rector/rector.php`:

```php
<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../../Classes',
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(codeQuality: true, deadCode: true)
    ->withSkip([
        // crdate hydrated from DB, kept for completeness
        RemoveUnusedPrivatePropertyRector::class => [
            __DIR__ . '/../../Classes/Domain/Model/Conversation.php',
        ],
        // Verbose instanceof checks not preferred over null checks
        FlipTypeControlToUseExclusiveTypeRector::class,
    ]);
```

- [ ] **Step 5: Commit**

```bash
git -C /srv/projects/nr-mcp-agent add -A
git -C /srv/projects/nr-mcp-agent commit -m "refactor: reorganize quality configs into Build/ subdirectories"
```

---

### Task 4: Update composer.json

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Update `composer.json`**

Replace with updated content that:
- Fixes script paths to use `Build/` prefixes
- Adds `authors`, `keywords`, `homepage`, `support` (required for TER)
- Adds `sort-packages: true` and `captainhook` extra config

```json
{
    "name": "netresearch/nr-mcp-agent",
    "description": "AI chat assistant for the TYPO3 backend using nr-llm and MCP server",
    "license": "GPL-2.0-or-later",
    "type": "typo3-cms-extension",
    "keywords": [
        "TYPO3",
        "extension",
        "AI",
        "chat",
        "MCP",
        "LLM",
        "backend"
    ],
    "authors": [
        {
            "name": "Netresearch DTT GmbH",
            "homepage": "https://www.netresearch.de/",
            "role": "Developer"
        }
    ],
    "homepage": "https://github.com/netresearch/t3x-nr-mcp-agent",
    "support": {
        "issues": "https://github.com/netresearch/t3x-nr-mcp-agent/issues",
        "source": "https://github.com/netresearch/t3x-nr-mcp-agent"
    },
    "require": {
        "php": "^8.2",
        "typo3/cms-core": "^13.4 || ^14.0",
        "typo3/cms-backend": "^13.4 || ^14.0",
        "netresearch/nr-llm": "dev-main"
    },
    "require-dev": {
        "captainhook/captainhook": "^5.0",
        "captainhook/hook-installer": "^1.0",
        "friendsofphp/php-cs-fixer": "^3.0",
        "phpat/phpat": "^0.11",
        "phpstan/phpstan": "^2.0",
        "phpunit/phpunit": "^10.5 || ^11.0",
        "rector/rector": "^2.0",
        "saschaegerer/phpstan-typo3": "^2.0",
        "typo3/testing-framework": "^8.0 || ^9.0"
    },
    "suggest": {
        "netresearch/nr-vault": "For secure API key storage (^0.4)",
        "hn/typo3-mcp-server": "For TYPO3 content management tools (^1.0)"
    },
    "autoload": {
        "psr-4": {
            "Netresearch\\NrMcpAgent\\": "Classes/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Netresearch\\NrMcpAgent\\Tests\\": "Tests/"
        }
    },
    "config": {
        "allow-plugins": {
            "captainhook/hook-installer": true,
            "phpstan/extension-installer": true,
            "typo3/class-alias-loader": true,
            "typo3/cms-composer-installers": true
        },
        "bin-dir": ".Build/bin",
        "sort-packages": true,
        "vendor-dir": ".Build/vendor"
    },
    "extra": {
        "captainhook": {
            "config": "Build/captainhook.json"
        },
        "typo3/cms": {
            "extension-key": "nr_mcp_agent",
            "web-dir": ".Build/Web"
        }
    },
    "scripts": {
        "ci": [
            "@ci:phpstan",
            "@ci:cgl",
            "@ci:tests"
        ],
        "ci:phpstan": ".Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon --no-progress",
        "ci:cgl": ".Build/bin/php-cs-fixer fix --dry-run --diff",
        "ci:tests": ".Build/bin/phpunit -c Build/phpunit.xml",
        "ci:tests:unit": ".Build/bin/phpunit -c Build/phpunit.xml --testsuite unit",
        "ci:tests:functional": ".Build/bin/phpunit -c Build/phpunit.xml --testsuite functional",
        "ci:tests:architecture": ".Build/bin/phpunit -c Build/phpunit.xml --testsuite architecture",
        "ci:rector": ".Build/bin/rector process --config Build/rector/rector.php --dry-run",
        "fix:cgl": ".Build/bin/php-cs-fixer fix",
        "rector": ".Build/bin/rector process --config Build/rector/rector.php"
    }
}
```

- [ ] **Step 2: Commit**

```bash
git -C /srv/projects/nr-mcp-agent add composer.json
git -C /srv/projects/nr-mcp-agent commit -m "chore: update composer.json — TER metadata, Build/ paths, captainhook"
```

---

### Task 5: Update Tests/Build/Bootstrap.php

The bootstrap's autoload path must be verified. After the move, `Tests/Build/Bootstrap.php` is two levels from the repo root, so `../../.Build/vendor/autoload.php` is correct. No change needed — verify only.

**Files:**
- Verify: `Tests/Build/Bootstrap.php`

- [ ] **Step 1: Verify bootstrap path is correct**

Read `/srv/projects/nr-mcp-agent/Tests/Build/Bootstrap.php`. The path `__DIR__ . '/../../.Build/vendor/autoload.php'` from `Tests/Build/` resolves to `<repo>/.Build/vendor/autoload.php` — correct. No change needed.

---

### Task 6: Add Build/Scripts and Build/captainhook.json

**Files:**
- Create: `Build/Scripts/runTests.sh`
- Create: `Build/Scripts/check-tag-version.sh`
- Create: `Build/captainhook.json`

- [ ] **Step 1: Create `Build/Scripts/check-tag-version.sh`**

Copy from nr-llm verbatim (extension-key independent):

```bash
#!/usr/bin/env bash
# Validates that ext_emconf.php version matches any semver tag pointing at HEAD.
# Used as a CaptainHook pre-push hook to prevent pushing mismatched versions.
set -euo pipefail

# Find semver tags (with or without v prefix) pointing at HEAD, normalize to bare version
TAGS=$(git tag --points-at HEAD | sed -nE 's/^v?([0-9]+\.[0-9]+\.[0-9]+)$/\1/p' || true)

if [[ -z "${TAGS}" ]]; then
    # No semver tag at HEAD — nothing to validate
    exit 0
fi

# Extract version from ext_emconf.php (portable sed instead of grep -P)
EMCONF_VERSION=$(sed -nE "s/.*'version'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\1/p" ext_emconf.php)

if [[ -z "${EMCONF_VERSION}" ]]; then
    echo "ERROR: Could not extract version from ext_emconf.php" >&2
    exit 1
fi

# Check if ext_emconf.php version matches any of the tags at HEAD
if ! echo "${TAGS}" | grep -qFx -e "${EMCONF_VERSION}"; then
    echo "ERROR: ext_emconf.php version (${EMCONF_VERSION}) does not match any semver tag at HEAD." >&2
    echo "Tags found at HEAD:" >&2
    echo "${TAGS}" >&2
    echo "Update ext_emconf.php version to match the tag and amend your commit before pushing." >&2
    exit 1
fi

echo "Version check passed: ext_emconf.php (${EMCONF_VERSION}) matches tag(s)"
```

Make it executable: `chmod +x Build/Scripts/check-tag-version.sh`

- [ ] **Step 2: Create `Build/Scripts/runTests.sh`**

A minimal DDEV-aware test runner (full Docker-based runner can be added later):

```bash
#!/usr/bin/env bash
# Test runner for nr_mcp_agent — wraps PHPUnit with suite selection.
set -euo pipefail

SUITE="${1:-unit}"
CONFIG="Build/phpunit.xml"

case "$SUITE" in
    unit|functional|architecture)
        .Build/bin/phpunit -c "$CONFIG" --testsuite "$SUITE"
        ;;
    all)
        .Build/bin/phpunit -c "$CONFIG"
        ;;
    *)
        echo "Usage: $0 [unit|functional|architecture|all]" >&2
        exit 1
        ;;
esac
```

Make it executable: `chmod +x Build/Scripts/runTests.sh`

- [ ] **Step 3: Create `Build/captainhook.json`**

```json
{
    "config": {
        "bootstrap": ".Build/vendor/autoload.php",
        "verbosity": "normal",
        "fail-on-first-error": true,
        "ansi-colors": true
    },
    "commit-msg": {
        "enabled": true,
        "actions": [
            {
                "action": "\\CaptainHook\\App\\Hook\\Message\\Action\\Regex",
                "options": {
                    "regex": "#^(feat|fix|chore|docs|test|refactor|style|ci|perf|build|revert)(\\([^)]+\\))?!?: .{1,72}$#"
                },
                "conditions": []
            }
        ]
    },
    "pre-commit": {
        "enabled": true,
        "actions": [
            {
                "action": "composer ci:cgl",
                "conditions": []
            },
            {
                "action": "composer ci:phpstan",
                "conditions": []
            }
        ]
    },
    "pre-push": {
        "enabled": true,
        "actions": [
            {
                "action": "Build/Scripts/check-tag-version.sh",
                "conditions": []
            },
            {
                "action": "composer ci:tests:unit",
                "conditions": []
            }
        ]
    },
    "post-merge": {
        "enabled": true,
        "actions": [
            {
                "action": "composer install"
            }
        ]
    },
    "post-checkout": {
        "enabled": true,
        "actions": [
            {
                "action": "composer install"
            }
        ]
    },
    "prepare-commit-msg": {
        "enabled": false,
        "actions": []
    },
    "post-rewrite": {
        "enabled": false,
        "actions": []
    },
    "post-change": {
        "enabled": false,
        "actions": []
    }
}
```

- [ ] **Step 4: Commit**

```bash
git -C /srv/projects/nr-mcp-agent add Build/Scripts/ Build/captainhook.json
git -C /srv/projects/nr-mcp-agent commit -m "chore: add Build/Scripts and captainhook config"
```

---

### Task 7: Update DDEV install scripts

**Files:**
- Modify: `.ddev/commands/web/install-v13`
- Modify: `.ddev/commands/web/install-v14`

Both scripts have `EXTENSION_PATH="/var/www/nr_mcp_agent/packages/nr_mcp_agent"`. After the restructure, the extension is directly at `/var/www/nr_mcp_agent`.

- [ ] **Step 1: Fix `EXTENSION_PATH` in install-v13**

In `/srv/projects/nr-mcp-agent/.ddev/commands/web/install-v13`, change line 9:

```bash
# Before:
EXTENSION_PATH="/var/www/nr_mcp_agent/packages/nr_mcp_agent"
# After:
EXTENSION_PATH="/var/www/nr_mcp_agent"
```

- [ ] **Step 2: Fix `EXTENSION_PATH` in install-v14**

In `/srv/projects/nr-mcp-agent/.ddev/commands/web/install-v14`, change line 9:

```bash
# Before:
EXTENSION_PATH="/var/www/nr_mcp_agent/packages/nr_mcp_agent"
# After:
EXTENSION_PATH="/var/www/nr_mcp_agent"
```

- [ ] **Step 3: Commit**

```bash
git -C /srv/projects/nr-mcp-agent add .ddev/commands/web/install-v13 .ddev/commands/web/install-v14
git -C /srv/projects/nr-mcp-agent commit -m "chore: fix EXTENSION_PATH in DDEV install scripts"
```

---

### Task 8: Update Makefile paths

**Files:**
- Modify: `Makefile`

The Makefile has test/lint targets that reference paths which assumed the current working directory inside DDEV is the repo root. But paths like `.Build/bin/phpunit -c .Build/phpunit.xml` were pointing to `packages/nr_mcp_agent/.Build/` — now `.Build/` is at the repo root, and phpunit.xml is at `Build/phpunit.xml`.

Also the playwright config path needs to be updated.

- [ ] **Step 1: Update test targets in Makefile**

Change these lines in `/srv/projects/nr-mcp-agent/Makefile`:

```makefile
# Before:
test-unit:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --testsuite unit

test-func:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --testsuite functional

test-arch:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --testsuite architecture

coverage:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --coverage-html=.Build/coverage

lint:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/php-cs-fixer fix --dry-run --diff

lint-fix:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/php-cs-fixer fix

phpstan:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpstan analyse

test-e2e:
	ddev exec -d /var/www/nr_mcp_agent npx playwright test --config=Build/playwright.config.ts

# After:
test-unit:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c Build/phpunit.xml --testsuite unit

test-func:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c Build/phpunit.xml --testsuite functional

test-arch:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c Build/phpunit.xml --testsuite architecture

coverage:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c Build/phpunit.xml --coverage-html=.Build/coverage

lint:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/php-cs-fixer fix --dry-run --diff

lint-fix:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/php-cs-fixer fix

phpstan:
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon

test-e2e:
	ddev exec -d /var/www/nr_mcp_agent npx playwright test --config=Build/tests/playwright/playwright.config.ts
```

- [ ] **Step 2: Commit**

```bash
git -C /srv/projects/nr-mcp-agent add Makefile
git -C /srv/projects/nr-mcp-agent commit -m "chore: fix Makefile paths after extension root restructure"
```

---

### Task 9: Update .gitignore and add .gitattributes

**Files:**
- Modify: `.gitignore`
- Create: `.gitattributes`

- [ ] **Step 1: Update `.gitignore`**

The current `.gitignore` (moved from `packages/nr_mcp_agent/`) covers extension-local ignores. Add repository-level ignores:

Append to `/srv/projects/nr-mcp-agent/.gitignore`:

```gitignore
# Repository-level ignores
.Build/
.php-cs-fixer.cache
node_modules/
package-lock.json
playwright-report/
test-results/
Build/tests/playwright/.auth/
Documentation-GENERATED-temp/
```

- [ ] **Step 2: Create `.gitattributes`** for TER export exclusions

Create `/srv/projects/nr-mcp-agent/.gitattributes`:

```
# Exclude development-only directories from TER export (composer archive / git archive)
/.ddev export-ignore
/Build/Scripts export-ignore
/Build/captainhook.json export-ignore
/Build/tests export-ignore
/docs export-ignore
/Tests export-ignore
/.editorconfig export-ignore
/.gitattributes export-ignore
/.gitignore export-ignore
/Makefile export-ignore
```

- [ ] **Step 3: Delete `Documentation-GENERATED-temp/`**

```bash
rm -rf /srv/projects/nr-mcp-agent/Documentation-GENERATED-temp
git -C /srv/projects/nr-mcp-agent rm -rf Documentation-GENERATED-temp 2>/dev/null || true
```

- [ ] **Step 4: Commit**

```bash
git -C /srv/projects/nr-mcp-agent add .gitignore .gitattributes
git -C /srv/projects/nr-mcp-agent commit -m "chore: update .gitignore, add .gitattributes for TER export"
```

---

### Task 10: Remove packages/ directory

**Files:**
- Delete: `packages/` (should be empty after all moves)

- [ ] **Step 1: Verify packages/ is empty**

```bash
find /srv/projects/nr-mcp-agent/packages -not -path '*/.Build/*' | sort
```

The only remaining items should be `.Build/` (compiled output, gitignored) and possibly `.php-cs-fixer.cache`.

- [ ] **Step 2: Remove packages/ from git**

```bash
git -C /srv/projects/nr-mcp-agent rm -rf packages/
```

If `.Build/` is gitignored, only gitignored files remain — `git rm -rf` won't touch them. Remove them manually if needed:

```bash
rm -rf /srv/projects/nr-mcp-agent/packages
```

- [ ] **Step 3: Commit**

```bash
git -C /srv/projects/nr-mcp-agent commit -m "chore: remove packages/ wrapper directory"
```

---

### Task 11: Run verification

- [ ] **Step 1: Verify structure matches nr-llm**

```bash
find /srv/projects/nr-mcp-agent -maxdepth 2 -not -path '*/.Build/*' -not -path '*/.git/*' -not -path '*/.ddev/*' -not -path '*/.claude/*' | sort
```

Expected: `Classes/`, `Configuration/`, `Documentation/`, `Resources/`, `Tests/`, `Build/`, `composer.json`, `ext_emconf.php`, etc. at root level. No `packages/` directory.

- [ ] **Step 2: Run PHPStan**

```bash
cd /srv/projects/nr-mcp-agent && .Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon --no-progress
```

Expected: no errors (or same baseline as before).

- [ ] **Step 3: Run PHPUnit**

```bash
cd /srv/projects/nr-mcp-agent && .Build/bin/phpunit -c Build/phpunit.xml --testsuite unit
```

Expected: all tests pass.

- [ ] **Step 4: Run PHP-CS-Fixer**

```bash
cd /srv/projects/nr-mcp-agent && .Build/bin/php-cs-fixer fix --dry-run --diff
```

Expected: no findings.

- [ ] **Step 5: Final commit if any fixups needed, then push**

```bash
git -C /srv/projects/nr-mcp-agent log --oneline -10
```
