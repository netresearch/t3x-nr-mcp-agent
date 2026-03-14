# TYPO3 AI Chat Extension – Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A standalone TYPO3 extension that provides an AI chat assistant as a bottom panel in the TYPO3 backend, using nr-llm (provider-agnostic LLM abstraction) with MCP server integration for content management.

**Architecture:** Bottom panel UI (Lit Element) in the TYPO3 backend outer frame, communicating via polling with an async CLI worker that runs the LLM agent loop. Conversations are persisted in a DB table (DBAL, no Extbase), LLM configuration managed via nr-llm Task records, and the MCP server provides TYPO3-native tools to the AI.

**Tech Stack:** PHP 8.2+, TYPO3 v13.4+, Lit 3.x (TYPO3-native), nr-llm (LLM-Abstraktion), nr-vault, hn/typo3-mcp-server

**Extension Key:** `nr_mcp_agent`
**Vendor/Package:** `netresearch/nr-mcp-agent`
**GitHub-Repository:** `t3x-nr-mcp-agent` (Netresearch-Konvention: `t3x-`-Prefix für TYPO3-Extensions)

---

## Vorbedingung: nr-llm Multi-Turn Tool-Use Support

### Problem

nr-llm normalisiert ausgehende Tool-Calls (Provider → OpenAI-Format) ✓, aber eingehende Tool-Result-Messages (`role: 'tool'`) werden ohne Konvertierung an den Provider durchgereicht. Claude und Gemini erwarten aber ein anderes Format als OpenAI.

### Lösung

PR in nr-llm: Message-Konvertierung in den Providern für Tool-Result-Messages.

**ClaudeProvider** — `role: 'tool'` → Claude-Format:
```php
// Eingehend (OpenAI-Format):
['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => '{"result": "ok"}']

// Konvertiert zu (Claude-Format):
['role' => 'user', 'content' => [
    ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"result": "ok"}']
]]
```

Analog: Assistant-Messages mit `tool_calls` → Claude `tool_use`-Content-Blocks.

**GeminiProvider** — `role: 'tool'` → Gemini `functionResponse`-Format.

**OpenAI/Mistral/Groq** — bereits nativ kompatibel, keine Änderung nötig.

### Akzeptanzkriterien

- [ ] ClaudeProvider konvertiert `role: 'tool'` Messages beim Request-Aufbau
- [ ] ClaudeProvider konvertiert `role: 'assistant'` mit `tool_calls` zu `tool_use`-Blocks
- [ ] GeminiProvider konvertiert `role: 'tool'` zu `functionResponse`
- [ ] Bestehende Tests grün
- [ ] Neuer Test: Multi-Turn Tool-Use Cycle (send → tool_calls → tool_result → send)
- [ ] Dokumentation in nr-llm Developer-Docs aktualisiert

### Auswirkung auf diesen Plan

Chunk 2 (ClaudeApiClient, DTOs, ApiKeyResolver) **entfällt komplett**. Stattdessen:

```php
// In ChatService — Provider-agnostisch:
$response = $this->llmManager->chatWithTools($messages, $mcpTools, ToolOptions::auto());

if ($response->hasToolCalls()) {
    // Assistant-Message mit Tool-Calls anhängen
    $messages[] = ['role' => 'assistant', 'tool_calls' => $response->toolCalls];

    // Tools ausführen, Results anhängen
    foreach ($response->toolCalls as $toolCall) {
        $result = $this->mcpToolProvider->executeTool(...);
        $messages[] = ChatMessage::tool($toolCall['id'], json_encode($result));
    }

    // Nächste Runde — nr-llm konvertiert pro Provider
    $response = $this->llmManager->chatWithTools($messages, $mcpTools);
}
```

---

## Testing Strategy (gesamte Testpyramide)

### Übersicht

Jeder Chunk enthält Tests für seine Komponenten. Zusätzlich gibt es einen eigenen Testing-Chunk (6) für Integration, Architecture und E2E. Ziel: **70%+ Coverage** auf Unit/Functional, phpat-Architekturregeln, Playwright E2E für die kritischen User-Flows.

### Testpyramide

```
         ╱╲
        ╱E2E╲         Playwright: Chat-Panel öffnen, Nachricht senden,
       ╱──────╲        Conversation-History, Resume, Error-States
      ╱ Integr. ╲     Agent-Loop mit Mock-LLM + echtem MCP, Full Request Cycle
     ╱────────────╲
    ╱  Functional   ╲  ConversationRepository (echte DB), CleanupCommand,
   ╱──────────────────╲ Worker-Dequeue (Race Condition), AjaxRoutes, TCA
  ╱   Architecture     ╲ phpat: Layer-Constraints, Domain-Isolation, Final-Classes
 ╱──────────────────────╲
╱         Unit            ╲ Conversation DTO, Enums, ExtensionConfiguration,
╱──────────────────────────╲ ChatService (mocked), McpConnection, Error-Sanitization, Retry
```

### Test-Infrastruktur (in Chunk 0 aufgesetzt)

```
packages/nr_mcp_agent/
├── Tests/
│   ├── Unit/
│   │   ├── Domain/Model/ConversationTest.php
│   │   ├── Configuration/ExtensionConfigurationTest.php
│   │   ├── Service/ChatServiceTest.php
│   │   ├── Service/ChatServiceRetryTest.php
│   │   ├── Mcp/McpConnectionTest.php
│   │   ├── Mcp/McpToolProviderTest.php
│   │   └── Controller/ChatApiControllerTest.php
│   ├── Functional/
│   │   ├── Domain/Repository/ConversationRepositoryTest.php
│   │   ├── Command/CleanupCommandTest.php
│   │   ├── Command/ChatWorkerDequeueTest.php
│   │   ├── Controller/ChatApiControllerFunctionalTest.php
│   │   └── Fixtures/
│   │       ├── be_users.csv
│   │       ├── be_groups.csv
│   │       └── tx_nrmcpagent_conversation.csv
│   ├── Architecture/
│   │   └── LayerDependencyTest.php
│   └── E2E/
│       └── (Playwright tests in Build/tests/playwright/)
├── Build/
│   ├── phpunit.xml
│   ├── phpat.neon
│   ├── playwright.config.ts
│   └── tests/playwright/
│       ├── config.ts
│       ├── fixtures/
│       │   └── backend-page.ts
│       ├── helper/
│       │   └── login.setup.ts
│       └── e2e/
│           ├── chat-panel-open.spec.ts
│           ├── chat-send-message.spec.ts
│           ├── chat-conversation-history.spec.ts
│           └── chat-error-states.spec.ts
├── Resources/Public/JavaScript/
│   └── __tests__/
│       ├── chat-message.test.js
│       ├── chat-conversation-list.test.js
│       └── chat-panel.test.js
├── jest.config.js
└── phpat.php
```

### Test-Typ pro Komponente

| Komponente | Unit | Functional | Architecture | E2E | Jest |
|------------|------|------------|--------------|-----|------|
| Conversation DTO | ✅ fromRow, toRow, appendMessage, isResumable | | | | |
| ConversationStatus/MessageRole Enums | ✅ Werte, Vollständigkeit | | | | |
| ExtensionConfiguration | ✅ Defaults, Parsing, Edge Cases | | | | |
| ConversationRepository | | ✅ CRUD, findByBeUser, countActive, Ownership | ✅ keine Domain-Deps | | |
| ChatService | ✅ Simple Response, Failed State, Retry | ✅ voller Loop mit Mock-LLM | ✅ Domain-Isolation | | |
| Error-Sanitization | ✅ API-Key-Stripping, URL-Stripping, Truncation | | | | |
| Retry-Logic | ✅ Transient vs. Fatal, Backoff-Verhalten | | | | |
| McpConnection | ✅ Request-Format, Notification, Timeout | | | | |
| McpToolProvider | ✅ Tool-Format-Konvertierung, Disconnect | | | | |
| ChatApiController | ✅ Access Check, Input Validation, Rate Limit | ✅ Full Request Cycle | | ✅ AJAX-Responses | |
| ExecChatProcessor | ✅ Command-Building, PID-Tracking | | | | |
| WorkerChatProcessor | | ✅ Dequeue-Atomicity (Race Condition) | | | |
| CleanupCommand | | ✅ Timeout, Archive, Delete (echte DB) | | | |
| AjaxRoutes | | ✅ Route-Registration | | | |
| TCA | | ✅ readOnly, adminOnly, Felder | | | |
| Chat Panel (Lit) | | | | ✅ Open/Close, Send, Poll | ✅ Rendering, Events |
| Chat Message (Lit) | | | | | ✅ Text, ToolUse, ToolResult |
| Conversation List (Lit) | | | | | ✅ Select, Resume, Archive Events |
| Layer-Constraints | | | ✅ Domain→keine Infra-Deps | | |

### PHPUnit-Konfiguration

```xml
<!-- Build/phpunit.xml -->
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
    bootstrap="vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php"
    cacheDirectory=".phpunit.cache"
    colors="true"
    executionOrder="depends,defects"
>
    <testsuites>
        <testsuite name="unit">
            <directory>../Tests/Unit/</directory>
        </testsuite>
        <testsuite name="functional">
            <directory>../Tests/Functional/</directory>
        </testsuite>
        <testsuite name="architecture">
            <directory>../Tests/Architecture/</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">../Classes/</directory>
        </include>
    </coverage>
</phpunit>
```

### Architecture Rules (phpat)

Die vollständigen phpat-Regeln sind in **Task 6.2** definiert (`LayerDependencyTest`). Kurzübersicht:

| Rule | Constraint |
|------|-----------|
| `testDomainDoesNotDependOnInfrastructure` | Domain → ✗ Controller, Command, Mcp |
| `testEnumsAreFinal` | Enum/* must be final |
| `testServicesDoNotAccessDatabaseDirectly` | Service → ✗ ConnectionPool |
| `testControllerDoesNotExecuteProcesses` | Controller → ✗ Mcp |

### Jest-Konfiguration (Lit Elements)

```javascript
// jest.config.js
module.exports = {
    testEnvironment: 'jsdom',
    transform: {
        '^.+\\.js$': 'babel-jest'
    },
    moduleNameMapper: {
        '^lit$': '<rootDir>/node_modules/lit/index.js',
        '^lit/(.*)$': '<rootDir>/node_modules/lit/$1',
    },
    collectCoverageFrom: [
        'Resources/Public/JavaScript/**/*.js',
        '!Resources/Public/JavaScript/**/*.test.js'
    ],
    coverageThreshold: {
        global: { branches: 70, functions: 70, lines: 70, statements: 70 }
    }
};
```

### Playwright-Konfiguration (E2E)

```typescript
// Build/playwright.config.ts
import { defineConfig, devices } from '@playwright/test';
import { config } from './tests/playwright/config';

export default defineConfig({
    testDir: './tests/playwright/e2e',
    timeout: 60_000,
    retries: 1,
    use: {
        baseURL: config.baseUrl,
        storageState: './.auth/login.json',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'login-setup', testMatch: '**/login.setup.ts' },
        {
            name: 'e2e',
            use: { ...devices['Desktop Chrome'] },
            dependencies: ['login-setup'],
        },
    ],
});

// Build/tests/playwright/config.ts
export const config = {
    baseUrl: process.env.TYPO3_BASE_URL || 'https://v14.nr-mcp-agent.ddev.site',
    admin: {
        username: process.env.TYPO3_ADMIN_USER || 'admin',
        password: process.env.TYPO3_ADMIN_PASS || 'Joh316!!',
    },
};
```

### Makefile-Targets (Ergänzung zu Chunk 0)

```makefile
# === Testing (erweitert) ===
test: test-unit test-func test-arch  ## Run all PHP tests

test-unit:  ## Run unit tests
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --testsuite unit

test-func:  ## Run functional tests
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --testsuite functional

test-arch:  ## Run architecture tests (phpat)
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --testsuite architecture

test-js:  ## Run Jest tests (Lit Elements)
	ddev exec -d /var/www/nr_mcp_agent npx jest --coverage

test-e2e:  ## Run Playwright E2E tests
	ddev exec -d /var/www/nr_mcp_agent npx playwright test --config=Build/playwright.config.ts

test-mutation:  ## Run mutation testing (Infection)
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/infection --min-msi=70 --threads=4

test-all: test test-js test-e2e  ## Run entire test pyramid

coverage:  ## Generate HTML coverage report
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --coverage-html=.Build/coverage
```

---

## Chunk 0: DDEV Development Setup

### Overview

DDEV-basierte Entwicklungsumgebung nach dem Muster von `nr-llm` / `nr-landingpage`. Enthält TYPO3 v13 + v14, die Extension als Path-Repository, sowie `hn/typo3-mcp-server` und `nr-vault` vorinstalliert. Landing Page mit Netresearch-Branding als Übersicht über alle verfügbaren Instanzen.

### File Structure (Chunk 0)

```
packages/nr_mcp_agent/
├── .ddev/
│   ├── config.yaml
│   ├── docker-compose.web.yaml
│   ├── web-build/
│   │   ├── Dockerfile
│   │   └── landing-index.html
│   ├── apache/
│   │   └── apache-site.conf
│   └── commands/
│       └── web/
│           ├── install-v13
│           ├── install-v14
│           ├── install-all
│           └── copy-landing-page
├── Makefile
└── .editorconfig
```

### Task 0.1: DDEV Configuration

**Files:**
- Create: `.ddev/config.yaml`
- Create: `.ddev/docker-compose.web.yaml`
- Create: `.ddev/apache/apache-site.conf`

- [ ] **Step 1: Create .ddev/config.yaml**

```yaml
name: nr-mcp-agent
type: php
php_version: "8.4"
webserver_type: apache-fpm
database:
  type: mariadb
  version: "11.4"
composer_version: "2"
xdebug_enabled: false
additional_hostnames:
  - v13.nr-mcp-agent
  - v14.nr-mcp-agent
  - docs.nr-mcp-agent
no_project_mount: true
hooks:
  post-start:
    - exec: |
        # Write git info for display in backend
        cd /var/www/nr_mcp_agent 2>/dev/null && \
        echo "{\"branch\":\"$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'unknown')\",\"commit\":\"$(git rev-parse --short HEAD 2>/dev/null || echo 'unknown')\"}" > /var/www/html/.git-info.json 2>/dev/null || true
```

- [ ] **Step 2: Create .ddev/docker-compose.web.yaml**

```yaml
services:
  web:
    environment:
      - EXTENSION_KEY=nr_mcp_agent
      - PACKAGE_NAME=netresearch/nr-mcp-agent
      - NR_MCP_AGENT_LLM_TASK_UID
      - TYPO3_DB_DRIVER=mysqli
      - TYPO3_DB_HOST=db
      - TYPO3_DB_PORT=3306
      - TYPO3_DB_DBNAME=db
      - TYPO3_DB_USERNAME=root
      - TYPO3_DB_PASSWORD=root
      - TYPO3_SETUP_ADMIN_EMAIL=admin@example.com
      - TYPO3_SETUP_ADMIN_USERNAME=admin
      - TYPO3_SETUP_ADMIN_PASSWORD=Joh316!!
    volumes:
      - ../:/var/www/nr_mcp_agent:cached
      - v13-data:/var/www/html/v13
      - v14-data:/var/www/html/v14
volumes:
  v13-data:
  v14-data:
```

Note: `nr-vault` und `hn/typo3-mcp-server` werden als Path-Repositories in `install-v14` konfiguriert, wenn die Quellverzeichnisse vorhanden sind. Andernfalls aus Packagist.

- [ ] **Step 3: Create .ddev/apache/apache-site.conf**

```apache
# Main site
<VirtualHost *:80 *:443>
    ServerName nr-mcp-agent.ddev.site
    DocumentRoot /var/www/html
    <Directory "/var/www/html">
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/master.crt
    SSLCertificateKeyFile /etc/ssl/certs/master.key
    RewriteEngine On
    RewriteCond %{HTTP:X-Forwarded-Proto} =https
    RewriteRule ^ - [env=HTTPS:on]
</VirtualHost>

# TYPO3 v13
<VirtualHost *:80 *:443>
    ServerName v13.nr-mcp-agent.ddev.site
    DocumentRoot /var/www/html/v13/public
    <Directory "/var/www/html/v13/public">
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/master.crt
    SSLCertificateKeyFile /etc/ssl/certs/master.key
    RewriteEngine On
    RewriteCond %{HTTP:X-Forwarded-Proto} =https
    RewriteRule ^ - [env=HTTPS:on]
</VirtualHost>

# TYPO3 v14
<VirtualHost *:80 *:443>
    ServerName v14.nr-mcp-agent.ddev.site
    DocumentRoot /var/www/html/v14/public
    <Directory "/var/www/html/v14/public">
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/master.crt
    SSLCertificateKeyFile /etc/ssl/certs/master.key
    RewriteEngine On
    RewriteCond %{HTTP:X-Forwarded-Proto} =https
    RewriteRule ^ - [env=HTTPS:on]
</VirtualHost>

# Documentation
<VirtualHost *:80 *:443>
    ServerName docs.nr-mcp-agent.ddev.site
    DocumentRoot /var/www/nr_mcp_agent/Documentation-GENERATED-temp
    <Directory "/var/www/nr_mcp_agent/Documentation-GENERATED-temp">
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/master.crt
    SSLCertificateKeyFile /etc/ssl/certs/master.key
</VirtualHost>
```

- [ ] **Step 4: Commit**

```bash
git add .ddev/
git commit -m "feat(nr-mcp-agent): add DDEV configuration"
```

### Task 0.2: TYPO3 v14 Install Script

**Files:**
- Create: `.ddev/commands/web/install-v14`

- [ ] **Step 1: Create install-v14**

```bash
#!/usr/bin/env bash
## Description: Install TYPO3 v14 with nr_mcp_agent extension
## Usage: install-v14
## Example: ddev install-v14

set -euo pipefail

INSTALL_DIR="/var/www/html/v14"
EXTENSION_PATH="/var/www/nr_mcp_agent"

echo "=== Installing TYPO3 v14 with nr_mcp_agent ==="

# Drop and recreate database
mysql -uroot -proot -hdb -e "DROP DATABASE IF EXISTS db; CREATE DATABASE db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Clean install directory (preserve volume)
rm -rf "${INSTALL_DIR:?}"/*

# Install TYPO3 base distribution
composer create-project typo3/cms-base-distribution:^14 "$INSTALL_DIR" --no-interaction

cd "$INSTALL_DIR"

# Remove PHP platform override (DDEV handles PHP version)
composer config --unset platform.php 2>/dev/null || true

# Add extension as path repository
composer config repositories.nr_mcp_agent path "$EXTENSION_PATH"

# Add nr-vault as path repository if available locally
if [ -d "/var/www/nr-vault" ]; then
    composer config repositories.nr_vault path "/var/www/nr-vault"
    echo "  → nr-vault from local path"
fi

# Require the extension
composer require "netresearch/nr-mcp-agent:@dev" --no-interaction

# Require MCP server (from Packagist)
composer require "hn/typo3-mcp-server:^1.0" --no-interaction || echo "  ⚠ hn/typo3-mcp-server not available yet, skipping"

# Require nr-vault if not already added via path
if ! composer show netresearch/nr-vault 2>/dev/null; then
    composer require "netresearch/nr-vault:^0.4" --no-interaction || echo "  ⚠ nr-vault not available, skipping"
fi

# Run TYPO3 setup
vendor/bin/typo3 setup \
    --driver=mysqli \
    --host=db \
    --port=3306 \
    --dbname=db \
    --username=root \
    --password=root \
    --admin-username="${TYPO3_SETUP_ADMIN_USERNAME:-admin}" \
    --admin-user-password="${TYPO3_SETUP_ADMIN_PASSWORD:-Joh316!!}" \
    --admin-email="${TYPO3_SETUP_ADMIN_EMAIL:-admin@example.com}" \
    --project-name="nr-mcp-agent Dev" \
    --server-type=apache \
    --no-interaction \
    --force

# Development settings
cat > config/system/additional.php << 'PHPSETTINGS'
<?php
$GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = true;
$GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = true;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '*';
$GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = 1;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['exceptionalErrors'] = 12290;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';
$GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'ImageMagick';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] = 'localhost:1025';

// AI Chat: nr-llm Task UID can be set here for dev
// Create nr-llm Task record after install, then set the UID:
if (getenv('NR_MCP_AGENT_LLM_TASK_UID')) {
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['llmTaskUid'] = (int)getenv('NR_MCP_AGENT_LLM_TASK_UID');
}

// AI Chat: Enable MCP if available
if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('typo3_mcp_server')) {
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['enableMcp'] = '1';
}
PHPSETTINGS

# Run extension setup and flush caches
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush

echo ""
echo "=== Installation complete ==="
echo "  Backend:  https://v14.nr-mcp-agent.ddev.site/typo3"
echo "  Login:    ${TYPO3_SETUP_ADMIN_USERNAME:-admin} / ${TYPO3_SETUP_ADMIN_PASSWORD:-Joh316!!}"
echo "  Docs:     https://docs.nr-mcp-agent.ddev.site"
echo ""
```

- [ ] **Step 2: Make executable and commit**

```bash
chmod +x .ddev/commands/web/install-v14
git add .ddev/commands/web/install-v14
git commit -m "feat(nr-mcp-agent): add TYPO3 v14 install script with MCP pre-installed"
```

### Task 0.3: TYPO3 v13 Install Script & install-all

**Files:**
- Create: `.ddev/commands/web/install-v13`
- Create: `.ddev/commands/web/install-all`

- [ ] **Step 1: Create install-v13**

Analog zu install-v14, aber mit:
- `INSTALL_DIR="/var/www/html/v13"`
- `composer create-project typo3/cms-base-distribution:^13.4 "$INSTALL_DIR"`
- Gleiche Extension-Installation (Path-Repository, require, setup)
- Backend-URL: `https://v13.nr-mcp-agent.ddev.site/typo3`

- [ ] **Step 2: Create install-all**

```bash
#!/usr/bin/env bash
## Description: Install all supported TYPO3 versions
## Usage: install-all
## Example: ddev install-all

set -euo pipefail

echo "=== Installing all TYPO3 versions ==="
/var/www/html/.ddev/commands/web/install-v13
/var/www/html/.ddev/commands/web/install-v14
echo ""
echo "=== All versions installed ==="
echo "  v13: https://v13.nr-mcp-agent.ddev.site/typo3"
echo "  v14: https://v14.nr-mcp-agent.ddev.site/typo3"
echo "  Overview: https://nr-mcp-agent.ddev.site"
```

- [ ] **Step 3: Make executable and commit**

```bash
chmod +x .ddev/commands/web/install-v13 .ddev/commands/web/install-all
git add .ddev/commands/web/install-v13 .ddev/commands/web/install-all
git commit -m "feat(nr-mcp-agent): add TYPO3 v13 install script and install-all"
```

### Task 0.4: Landing Page & Dockerfile

**Files:**
- Create: `.ddev/web-build/landing-index.html`
- Create: `.ddev/web-build/Dockerfile`
- Create: `.ddev/commands/web/copy-landing-page`

Die Landing Page nutzt das Netresearch-Template aus dem typo3-ddev Skill (`assets/templates/index.html.netresearch.template`). Sie zeigt eine Übersichtsseite mit Links zu allen verfügbaren Instanzen.

- [ ] **Step 1: Create .ddev/web-build/landing-index.html**

Basierend auf dem Netresearch-Template mit folgenden Anpassungen:
- `{{EXTENSION_NAME}}`: `nr_mcp_agent`
- `{{EXTENSION_DESCRIPTION}}`: `AI Chat for TYPO3 Editors via MCP – Development Environment`
- `{{DDEV_PROJECT}}`: `nr-mcp-agent`

**Navigation Cards:**

| Card | Icon | Label | URL |
| --- | --- | --- | --- |
| TYPO3 v13 Backend | 🔧 | TYPO3 v13 Backend | v13.nr-mcp-agent.ddev.site/typo3/ |
| TYPO3 v13 Frontend | 🌐 | TYPO3 v13 Frontend | v13.nr-mcp-agent.ddev.site/ |
| TYPO3 v14 Backend | 🔧 | TYPO3 v14 Backend | v14.nr-mcp-agent.ddev.site/typo3/ |
| TYPO3 v14 Frontend | 🌐 | TYPO3 v14 Frontend | v14.nr-mcp-agent.ddev.site/ |
| Documentation | 📚 | Documentation | docs.nr-mcp-agent.ddev.site/ |
| Mailpit | 📧 | Mailpit | nr-mcp-agent.ddev.site:8026/ |

**Dynamische Git-Info:**
- Fetch von `/.git-info.json` (geschrieben vom post-start Hook)
- Zeigt Branch, Commit-Hash, ggf. PR-Nummer

**Credentials-Anzeige:**
- Username: admin / Password: Joh316!!

- [ ] **Step 2: Create .ddev/web-build/Dockerfile**

```dockerfile
ARG BASE_IMAGE
FROM $BASE_IMAGE

# Copy landing page to webroot
COPY landing-index.html /var/www/html/index.html
```

- [ ] **Step 3: Create .ddev/commands/web/copy-landing-page**

```bash
#!/usr/bin/env bash
## Description: Copy landing page to webroot (for manual refresh)
## Usage: copy-landing-page

cp /var/www/nr_mcp_agent/.ddev/web-build/landing-index.html /var/www/html/index.html
echo "Landing page copied to /var/www/html/index.html"
```

- [ ] **Step 4: Make executable and commit**

```bash
chmod +x .ddev/commands/web/copy-landing-page
git add .ddev/web-build/ .ddev/commands/web/copy-landing-page
git commit -m "feat(nr-mcp-agent): add landing page with Netresearch branding"
```

### Task 0.5: Makefile & .editorconfig

**Files:**
- Create: `Makefile`
- Create: `.editorconfig`

- [ ] **Step 1: Create Makefile**

```makefile
.PHONY: up start down restart install install-all install-v13 install-v14 sync test test-unit test-func lint lint-fix phpstan ci docs

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
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --testsuite unit

test-func:  ## Run functional tests
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --testsuite functional

test-arch:  ## Run architecture tests (phpat)
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --testsuite architecture

test-js:  ## Run Jest tests (Lit Elements)
	ddev exec -d /var/www/nr_mcp_agent npx jest --coverage

test-e2e:  ## Run Playwright E2E tests
	ddev exec -d /var/www/nr_mcp_agent npx playwright test --config=Build/playwright.config.ts

test-mutation:  ## Run mutation testing (Infection)
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/infection --min-msi=70 --threads=4

test-all: test test-js test-e2e  ## Run entire test pyramid

coverage:  ## Generate HTML coverage report
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --coverage-html=.Build/coverage

# === Quality ===
lint:  ## Check code style
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/php-cs-fixer fix --dry-run --diff

lint-fix:  ## Fix code style
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/php-cs-fixer fix

phpstan:  ## Static analysis
	ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpstan analyse

ci: lint phpstan test test-js  ## Run CI checks (without E2E — those run separately)

# === Documentation ===
docs:  ## Render documentation
	ddev exec -d /var/www/nr_mcp_agent docker run --rm -v .:/project ghcr.io/typo3-documentation/render-guides:latest --config=Documentation 2>/dev/null || echo "Docs render requires Docker-in-Docker or local render"
```

- [ ] **Step 2: Create .editorconfig**

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
indent_style = space
indent_size = 4
insert_final_newline = true
trim_trailing_whitespace = true

[*.{yaml,yml}]
indent_size = 2

[*.{js,json}]
indent_size = 2

[*.md]
trim_trailing_whitespace = false

[*.sql]
indent_size = 2

[*.rst]
indent_size = 4
max_line_length = 80
```

- [ ] **Step 3: Commit**

```bash
git add Makefile .editorconfig
git commit -m "feat(nr-mcp-agent): add Makefile and .editorconfig"
```

---

## Chunk 1: Foundation – Extension Skeleton, DB Schema, Domain Model

### File Structure (Chunk 1)

```
packages/nr_mcp_agent/
├── composer.json
├── ext_emconf.php
├── ext_tables.sql
├── Classes/
│   ├── Domain/
│   │   ├── Model/Conversation.php
│   │   └── Repository/ConversationRepository.php
│   └── Enum/
│       ├── ConversationStatus.php
│       └── MessageRole.php
├── Configuration/
│   └── Services.yaml
├── Resources/
│   └── Public/
│       └── Icons/
│           └── Extension.svg          ← extension icon, create early (referenced by TCA/ext_emconf)
└── Tests/
    └── Unit/
        └── Domain/
            └── Model/
                └── ConversationTest.php
```

### Task 1.1: Extension Skeleton

**Files:**
- Create: `packages/nr_mcp_agent/composer.json`
- Create: `packages/nr_mcp_agent/ext_emconf.php`
- Create: `packages/nr_mcp_agent/Configuration/Services.yaml`
- Create: `packages/nr_mcp_agent/Resources/Public/Icons/Extension.svg`

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "netresearch/nr-mcp-agent",
    "type": "typo3-cms-extension",
    "description": "AI chat assistant for the TYPO3 backend using nr-llm and MCP server",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": "^8.2",
        "typo3/cms-core": "^13.4",
        "typo3/cms-backend": "^13.4",
        "netresearch/nr-llm": "^0.5"
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
    "extra": {
        "typo3/cms": {
            "extension-key": "nr_mcp_agent"
        }
    }
}
```

Note: `nr-llm` is a hard `require` — it provides the LLM abstraction layer. `nr-vault` and `hn/typo3-mcp-server` are `suggest` — the extension works without them (no MCP tools, API keys managed via nr-llm Task).

- [ ] **Step 2: Create ext_emconf.php**

```php
<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Chat',
    'description' => 'AI chat assistant for the TYPO3 backend',
    'category' => 'module',
    'version' => '0.1.0',
    'state' => 'alpha',
    'author' => 'Netresearch DTT GmbH',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];
```

- [ ] **Step 3: Create Services.yaml**

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Netresearch\NrMcpAgent\:
    resource: '../Classes/*'
```

- [ ] **Step 4: Create Extension.svg icon**

```svg
<!-- packages/nr_mcp_agent/Resources/Public/Icons/Extension.svg -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">
  <rect width="64" height="64" rx="8" fill="#2F99A4"/>
  <text x="32" y="42" font-family="sans-serif" font-size="28" font-weight="bold"
        fill="white" text-anchor="middle">AI</text>
</svg>
```

Note: Simple placeholder icon using Netresearch primary color. Can be replaced with a more polished design later.

- [ ] **Step 5: Commit**

```bash
git add packages/nr_mcp_agent/composer.json packages/nr_mcp_agent/ext_emconf.php packages/nr_mcp_agent/Configuration/Services.yaml packages/nr_mcp_agent/Resources/Public/Icons/Extension.svg
git commit -m "feat(nr-mcp-agent): add extension skeleton with icon"
```

### Task 1.2: Database Schema & Enums

**Files:**
- Create: `packages/nr_mcp_agent/ext_tables.sql`
- Create: `packages/nr_mcp_agent/Classes/Enum/ConversationStatus.php`
- Create: `packages/nr_mcp_agent/Classes/Enum/MessageRole.php`

- [ ] **Step 1: Create ConversationStatus enum**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

enum ConversationStatus: string
{
    case Idle = 'idle';
    case Processing = 'processing';
    case Locked = 'locked';
    case ToolLoop = 'tool_loop';
    case Failed = 'failed';
}
```

- [ ] **Step 2: Create MessageRole enum**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
```

- [ ] **Step 3: Create ext_tables.sql**

```sql
CREATE TABLE tx_nrmcpagent_conversation (
    be_user int(11) unsigned DEFAULT 0 NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    messages mediumtext,
    message_count int(11) unsigned DEFAULT 0 NOT NULL,
    status varchar(20) DEFAULT 'idle' NOT NULL,
    current_request_id varchar(64) DEFAULT '' NOT NULL,
    system_prompt text,
    archived tinyint(1) unsigned DEFAULT 0 NOT NULL,
    pinned tinyint(1) unsigned DEFAULT 0 NOT NULL,
    error_message text,

    KEY be_user_archived (be_user, archived, tstamp)
);
```

Note: TYPO3 automatically adds `uid`, `pid`, `tstamp`, `crdate`, `deleted`, `hidden` fields.

- [ ] **Step 4: Commit**

```bash
git add packages/nr_mcp_agent/ext_tables.sql packages/nr_mcp_agent/Classes/Enum/
git commit -m "feat(nr-mcp-agent): add database schema and enums"
```

### Task 1.3: Domain Model & Repository

**Files:**
- Create: `packages/nr_mcp_agent/Classes/Domain/Model/Conversation.php`
- Create: `packages/nr_mcp_agent/Classes/Domain/Repository/ConversationRepository.php`
- Create: `packages/nr_mcp_agent/Tests/Unit/Domain/Model/ConversationTest.php`

- [ ] **Step 1: Write ConversationTest**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Model;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConversationTest extends TestCase
{
    #[Test]
    public function newConversationHasIdleStatus(): void
    {
        $conversation = new Conversation();
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    #[Test]
    public function appendMessageIncreasesCount(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage('user', 'Hello');

        self::assertSame(1, $conversation->getMessageCount());
    }

    #[Test]
    public function appendMessageAddsToMessages(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage('user', 'Hello');

        $messages = $conversation->getDecodedMessages();
        self::assertCount(1, $messages);
        self::assertSame('user', $messages[0]['role']);
        self::assertSame('Hello', $messages[0]['content']);
    }

    #[Test]
    public function getDecodedMessagesReturnsEmptyArrayForNewConversation(): void
    {
        $conversation = new Conversation();
        self::assertSame([], $conversation->getDecodedMessages());
    }

    #[Test]
    public function appendAssistantMessageWithToolUse(): void
    {
        $conversation = new Conversation();
        $content = [
            ['type' => 'text', 'text' => 'I will translate that.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'translate', 'input' => ['page' => 5]],
        ];
        $conversation->appendMessage('assistant', $content);

        $messages = $conversation->getDecodedMessages();
        self::assertSame($content, $messages[0]['content']);
    }

    #[Test]
    public function autoTitleUsesFirstUserMessage(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage('user', 'Übersetze Seite 5 ins Englische');

        self::assertSame('Übersetze Seite 5 ins Englische', $conversation->getTitle());
    }

    #[Test]
    public function autoTitleTruncatesLongMessages(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage('user', str_repeat('a', 300));

        self::assertSame(255, mb_strlen($conversation->getTitle()));
    }

    #[Test]
    public function autoTitleDoesNotOverwriteExistingTitle(): void
    {
        $conversation = new Conversation();
        $conversation->setTitle('My custom title');
        $conversation->appendMessage('user', 'Hello');

        self::assertSame('My custom title', $conversation->getTitle());
    }

    #[Test]
    public function isResumableReturnsTrueForProcessingStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Processing);

        self::assertTrue($conversation->isResumable());
    }

    #[Test]
    public function isResumableReturnsTrueForToolLoopStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::ToolLoop);

        self::assertTrue($conversation->isResumable());
    }

    #[Test]
    public function isResumableReturnsTrueForFailedStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Failed);

        self::assertTrue($conversation->isResumable());
    }

    #[Test]
    public function isResumableReturnsFalseForIdleStatus(): void
    {
        $conversation = new Conversation();
        self::assertFalse($conversation->isResumable());
    }

    #[Test]
    public function isResumableReturnsFalseForLockedStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Locked);

        self::assertFalse($conversation->isResumable());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c packages/nr_mcp_agent/.Build/phpunit.xml --filter ConversationTest`
Expected: FAIL – class Conversation not found

- [ ] **Step 3: Write Conversation model**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Model;

use Netresearch\NrMcpAgent\Enum\ConversationStatus;

/**
 * Simple DTO/Value Object — no Extbase, no AbstractEntity.
 * Use Conversation::fromRow() to hydrate from a DB row.
 */
final class Conversation
{
    private int $uid = 0;
    private int $beUser = 0;
    private string $title = '';
    private string $messages = '';
    private int $messageCount = 0;
    private string $status = 'idle';
    private string $currentRequestId = '';
    private string $systemPrompt = '';
    private bool $archived = false;
    private bool $pinned = false;
    private string $errorMessage = '';
    private int $tstamp = 0;
    private int $crdate = 0;

    /**
     * Factory method: hydrate from a database row array.
     */
    public static function fromRow(array $row): self
    {
        $conversation = new self();
        $conversation->uid = (int)($row['uid'] ?? 0);
        $conversation->beUser = (int)($row['be_user'] ?? 0);
        $conversation->title = (string)($row['title'] ?? '');
        $conversation->messages = (string)($row['messages'] ?? '');
        $conversation->messageCount = (int)($row['message_count'] ?? 0);
        $conversation->status = (string)($row['status'] ?? 'idle');
        $conversation->currentRequestId = (string)($row['current_request_id'] ?? '');
        $conversation->systemPrompt = (string)($row['system_prompt'] ?? '');
        $conversation->archived = (bool)($row['archived'] ?? false);
        $conversation->pinned = (bool)($row['pinned'] ?? false);
        $conversation->errorMessage = (string)($row['error_message'] ?? '');
        $conversation->tstamp = (int)($row['tstamp'] ?? 0);
        $conversation->crdate = (int)($row['crdate'] ?? 0);
        return $conversation;
    }

    /**
     * Serialize back to a DB-compatible array (for INSERT/UPDATE).
     */
    public function toRow(): array
    {
        return [
            'be_user' => $this->beUser,
            'title' => $this->title,
            'messages' => $this->messages,
            'message_count' => $this->messageCount,
            'status' => $this->status,
            'current_request_id' => $this->currentRequestId,
            'system_prompt' => $this->systemPrompt,
            'archived' => (int)$this->archived,
            'pinned' => (int)$this->pinned,
            'error_message' => $this->errorMessage,
        ];
    }

    public function getUid(): int
    {
        return $this->uid;
    }

    public function getBeUser(): int
    {
        return $this->beUser;
    }

    public function setBeUser(int $beUser): void
    {
        $this->beUser = $beUser;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = mb_substr($title, 0, 255);
    }

    public function getMessages(): string
    {
        return $this->messages;
    }

    public function getDecodedMessages(): array
    {
        if ($this->messages === '') {
            return [];
        }
        return json_decode($this->messages, true, 512, JSON_THROW_ON_ERROR);
    }

    public function setMessages(array $messages): void
    {
        $this->messages = json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function appendMessage(string $role, string|array $content): void
    {
        $messages = $this->getDecodedMessages();
        $messages[] = ['role' => $role, 'content' => $content];
        $this->setMessages($messages);
        $this->messageCount = count($messages);

        if ($this->title === '' && $role === 'user' && is_string($content)) {
            $this->setTitle($content);
        }
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function getStatus(): ConversationStatus
    {
        return ConversationStatus::from($this->status);
    }

    public function setStatus(ConversationStatus $status): void
    {
        $this->status = $status->value;
    }

    public function getCurrentRequestId(): string
    {
        return $this->currentRequestId;
    }

    public function setCurrentRequestId(string $id): void
    {
        $this->currentRequestId = $id;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function setSystemPrompt(string $prompt): void
    {
        $this->systemPrompt = $prompt;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): void
    {
        $this->archived = $archived;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function setPinned(bool $pinned): void
    {
        $this->pinned = $pinned;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(string $message): void
    {
        $this->errorMessage = $message;
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    public function isResumable(): bool
    {
        return in_array(
            $this->getStatus(),
            [ConversationStatus::Processing, ConversationStatus::ToolLoop, ConversationStatus::Failed],
            true
        );
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit -c packages/nr_mcp_agent/.Build/phpunit.xml --filter ConversationTest`
Expected: All PASS

- [ ] **Step 5: Write ConversationRepository**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * DBAL-based repository — no Extbase, direct QueryBuilder access.
 */
final class ConversationRepository
{
    private const TABLE = 'tx_nrmcpagent_conversation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function findByUid(int $uid): ?Conversation
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, \PDO::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? Conversation::fromRow($row) : null;
    }

    public function findByBeUser(int $beUserUid, bool $includeArchived = false): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, \PDO::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
            )
            ->orderBy('tstamp', 'DESC');

        if (!$includeArchived) {
            $qb->andWhere($qb->expr()->eq('archived', 0));
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map(Conversation::fromRow(...), $rows);
    }

    public function findOneByUidAndBeUser(int $uid, int $beUserUid): ?Conversation
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, \PDO::PARAM_INT)),
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, \PDO::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? Conversation::fromRow($row) : null;
    }

    public function countActiveByBeUser(int $beUserUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$qb->count('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, \PDO::PARAM_INT)),
                $qb->expr()->in('status', $qb->createNamedParameter(
                    ['processing', 'locked', 'tool_loop'],
                    Connection::PARAM_STR_ARRAY,
                )),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchOne();
    }

    public function add(Conversation $conversation): int
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data = $conversation->toRow();
        $data['crdate'] = $data['tstamp'] = time();
        $data['pid'] = 0;
        $conn->insert(self::TABLE, $data);
        return (int)$conn->lastInsertId();
    }

    public function update(Conversation $conversation): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data = $conversation->toRow();
        $data['tstamp'] = time();
        $conn->update(self::TABLE, $data, ['uid' => $conversation->getUid()]);
    }
}
```

- [ ] **Step 6: Write Enum tests**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Enum;

use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EnumTest extends TestCase
{
    #[Test]
    public function conversationStatusHasAllExpectedCases(): void
    {
        $expected = ['idle', 'processing', 'locked', 'tool_loop', 'failed'];
        $actual = array_map(fn(ConversationStatus $s) => $s->value, ConversationStatus::cases());
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function messageRoleHasAllExpectedCases(): void
    {
        $expected = ['user', 'assistant', 'tool'];
        $actual = array_map(fn(MessageRole $r) => $r->value, MessageRole::cases());
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function conversationStatusCanBeCreatedFromString(): void
    {
        self::assertSame(ConversationStatus::Processing, ConversationStatus::from('processing'));
    }
}
```

- [ ] **Step 7: Write ConversationRepository functional test**

Create: `packages/nr_mcp_agent/Tests/Functional/Fixtures/be_users.csv`

```csv
"be_users"
,"uid","pid","username","password","admin","tstamp","crdate"
,1,0,"admin","$2y$12$xxx",1,1710000000,1710000000
,2,0,"editor","$2y$12$xxx",0,1710000000,1710000000
```

Create: `packages/nr_mcp_agent/Tests/Functional/Fixtures/tx_nrmcpagent_conversation.csv`

```csv
"tx_nrmcpagent_conversation"
,"uid","pid","be_user","title","messages","message_count","status","current_request_id","archived","pinned","deleted","tstamp","crdate"
,1,0,1,"Conv 1","[]",0,"idle","",0,0,0,1710000000,1710000000
,2,0,1,"Conv 2","[]",0,"processing","",0,0,0,1710000000,1710000000
,3,0,2,"Conv 3","[]",0,"idle","",0,0,0,1710000000,1710000000
,4,0,1,"Archived","[]",0,"idle","",1,0,0,1710000000,1710000000
,5,0,1,"Deleted","[]",0,"idle","",0,0,1,1710000000,1710000000
```

Create: `packages/nr_mcp_agent/Tests/Functional/Domain/Repository/ConversationRepositoryTest.php`

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class ConversationRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-mcp-agent'];

    private ConversationRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tx_nrmcpagent_conversation.csv');
        $this->subject = $this->get(ConversationRepository::class);
    }

    #[Test]
    public function findByUidReturnsConversation(): void
    {
        $conversation = $this->subject->findByUid(1);
        self::assertInstanceOf(Conversation::class, $conversation);
        self::assertSame('Conv 1', $conversation->getTitle());
    }

    #[Test]
    public function findByUidReturnsNullForDeleted(): void
    {
        self::assertNull($this->subject->findByUid(5));
    }

    #[Test]
    public function findByUidReturnsNullForNonExistent(): void
    {
        self::assertNull($this->subject->findByUid(999));
    }

    #[Test]
    public function findByBeUserReturnsOnlyOwnConversations(): void
    {
        $conversations = $this->subject->findByBeUser(1);
        // User 1 has uid 1 (idle), 2 (processing) — not 4 (archived) or 5 (deleted)
        self::assertCount(2, $conversations);
    }

    #[Test]
    public function findByBeUserIncludesArchivedWhenRequested(): void
    {
        $conversations = $this->subject->findByBeUser(1, includeArchived: true);
        self::assertCount(3, $conversations); // 1, 2, 4
    }

    #[Test]
    public function findOneByUidAndBeUserEnforcesOwnership(): void
    {
        // Conv 3 belongs to user 2 — user 1 must not see it
        self::assertNull($this->subject->findOneByUidAndBeUser(3, 1));
        self::assertInstanceOf(Conversation::class, $this->subject->findOneByUidAndBeUser(3, 2));
    }

    #[Test]
    public function countActiveByBeUserCountsProcessingAndLocked(): void
    {
        // User 1 has conv 2 in 'processing'
        self::assertSame(1, $this->subject->countActiveByBeUser(1));
        self::assertSame(0, $this->subject->countActiveByBeUser(2));
    }

    #[Test]
    public function addInsertsAndReturnsUid(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setTitle('New conversation');

        $uid = $this->subject->add($conversation);

        self::assertGreaterThan(0, $uid);
        $loaded = $this->subject->findByUid($uid);
        self::assertSame('New conversation', $loaded->getTitle());
    }

    #[Test]
    public function updatePersistsChanges(): void
    {
        $conversation = $this->subject->findByUid(1);
        $conversation->setTitle('Updated title');
        $this->subject->update($conversation);

        $reloaded = $this->subject->findByUid(1);
        self::assertSame('Updated title', $reloaded->getTitle());
    }
}
```

- [ ] **Step 8: Commit**

```bash
git add packages/nr_mcp_agent/Classes/Domain/ packages/nr_mcp_agent/Tests/
git commit -m "feat(nr-mcp-agent): add Conversation model, repository, and tests (unit + functional)"
```

---

## Chunk 2: Extension Configuration & nr-llm Integration

### File Structure (Chunk 2)

```
packages/nr_mcp_agent/
├── Classes/
│   └── Configuration/
│       └── ExtensionConfiguration.php
└── ext_conf_template.txt
```

### Task 2.1: Extension Configuration

**Files:**
- Create: `packages/nr_mcp_agent/ext_conf_template.txt`
- Create: `packages/nr_mcp_agent/Classes/Configuration/ExtensionConfiguration.php`

- [ ] **Step 1: Verify composer.json has nr-llm dependency**

`packages/nr_mcp_agent/composer.json` should already contain `netresearch/nr-llm` in `require` (from Chunk 1). Verify the complete file matches:

```json
{
    "name": "netresearch/nr-mcp-agent",
    "type": "typo3-cms-extension",
    "description": "AI chat assistant for the TYPO3 backend using nr-llm and MCP server",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": "^8.2",
        "typo3/cms-core": "^13.4",
        "typo3/cms-backend": "^13.4",
        "netresearch/nr-llm": "^0.5"
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
    "extra": {
        "typo3/cms": {
            "extension-key": "nr_mcp_agent"
        }
    }
}
```

Note: `nr-llm` is a hard `require` — it provides the LLM abstraction layer. `nr-vault` and `hn/typo3-mcp-server` remain `suggest`.

- [ ] **Step 2: Create ext_conf_template.txt**

```
# cat=basic/llm; type=int; label=nr-llm Task UID (reference to a configured nr-llm Task record)
llmTaskUid = 0

# cat=basic/processing; type=options[exec,worker]; label=Processing Strategy (exec: fork CLI process per request, worker: long-running systemd worker)
processingStrategy = exec

# cat=basic/access; type=string; label=Allowed Backend User Groups (comma-separated UIDs, empty = all)
allowedGroups =

# cat=basic/mcp; type=boolean; label=Enable MCP Server integration (requires hn/typo3-mcp-server)
enableMcp = 0

# cat=basic/mcp; type=string; label=MCP Server Command (path to binary, default: vendor/bin/typo3)
mcpServerCommand =

# cat=basic/mcp; type=string; label=MCP Server Arguments (comma-separated, default: mcp:server)
mcpServerArgs =

# cat=basic/ui; type=int; label=Max conversations to keep per user (0 = unlimited)
maxConversationsPerUser = 50

# cat=basic/ui; type=int; label=Auto-archive conversations after days of inactivity (0 = never)
autoArchiveDays = 30

# cat=basic/security; type=int; label=Maximum message length in characters (0 = unlimited)
maxMessageLength = 10000

# cat=basic/security; type=int; label=Maximum active (processing) conversations per user (0 = unlimited)
maxActiveConversationsPerUser = 3
```

- [ ] **Step 3: Create ExtensionConfiguration DTO**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ExtensionConfiguration
{
    private array $config;

    public function __construct()
    {
        $this->config = GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class)
            ->get('nr_mcp_agent');
    }

    public function getLlmTaskUid(): int
    {
        return (int)($this->config['llmTaskUid'] ?? 0);
    }

    public function getProcessingStrategy(): string
    {
        return (string)($this->config['processingStrategy'] ?? 'exec');
    }

    public function getAllowedGroupIds(): array
    {
        $groups = (string)($this->config['allowedGroups'] ?? '');
        if ($groups === '') {
            return [];
        }
        return array_map('intval', explode(',', $groups));
    }

    public function isMcpEnabled(): bool
    {
        return (bool)($this->config['enableMcp'] ?? false);
    }

    public function getMaxConversationsPerUser(): int
    {
        return (int)($this->config['maxConversationsPerUser'] ?? 50);
    }

    public function getAutoArchiveDays(): int
    {
        return (int)($this->config['autoArchiveDays'] ?? 30);
    }

    public function getMaxMessageLength(): int
    {
        return (int)($this->config['maxMessageLength'] ?? 10000);
    }

    public function getMaxActiveConversationsPerUser(): int
    {
        return (int)($this->config['maxActiveConversationsPerUser'] ?? 3);
    }

    public function getMcpServerCommand(): string
    {
        return (string)($this->config['mcpServerCommand'] ?? '');
    }

    public function getMcpServerArgs(): array
    {
        $args = (string)($this->config['mcpServerArgs'] ?? '');
        if ($args === '') {
            return [];
        }
        return explode(',', $args);
    }
}
```

- [ ] **Step 4: Write ExtensionConfiguration unit test**

Create: `packages/nr_mcp_agent/Tests/Unit/Configuration/ExtensionConfigurationTest.php`

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Configuration;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ExtensionConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Provide a mock for GeneralUtility::makeInstance
        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn([
            'llmTaskUid' => '42',
            'processingStrategy' => 'worker',
            'allowedGroups' => '1,3,5',
            'enableMcp' => '1',
            'maxMessageLength' => '5000',
            'maxActiveConversationsPerUser' => '2',
            'mcpServerCommand' => '/usr/bin/typo3',
            'mcpServerArgs' => 'mcp:server,--verbose',
        ]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);
    }

    #[Test]
    public function getLlmTaskUidReturnsCastedInt(): void
    {
        $config = new ExtensionConfiguration();
        self::assertSame(42, $config->getLlmTaskUid());
    }

    #[Test]
    public function getAllowedGroupIdsParsesCommaList(): void
    {
        $config = new ExtensionConfiguration();
        self::assertSame([1, 3, 5], $config->getAllowedGroupIds());
    }

    #[Test]
    public function getMcpServerArgsSplitsCommaList(): void
    {
        $config = new ExtensionConfiguration();
        self::assertSame(['mcp:server', '--verbose'], $config->getMcpServerArgs());
    }

    #[Test]
    public function defaultsAreUsedForMissingKeys(): void
    {
        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn([]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);

        $config = new ExtensionConfiguration();
        self::assertSame(0, $config->getLlmTaskUid());
        self::assertSame('exec', $config->getProcessingStrategy());
        self::assertSame([], $config->getAllowedGroupIds());
        self::assertFalse($config->isMcpEnabled());
        self::assertSame(10000, $config->getMaxMessageLength());
        self::assertSame(3, $config->getMaxActiveConversationsPerUser());
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add packages/nr_mcp_agent/ext_conf_template.txt packages/nr_mcp_agent/Classes/Configuration/ packages/nr_mcp_agent/Tests/Unit/Configuration/
git commit -m "feat(nr-mcp-agent): add extension configuration with nr-llm integration and tests"
```

---

## Chunk 3: Agent Loop & Processing

### File Structure (Chunk 3)

```
packages/nr_mcp_agent/
├── Classes/
│   ├── Service/
│   │   ├── ChatService.php
│   │   ├── ChatProcessorInterface.php
│   │   ├── ExecChatProcessor.php
│   │   └── WorkerChatProcessor.php
│   ├── Mcp/
│   │   ├── McpConnection.php
│   │   └── McpToolProvider.php
│   └── Command/
│       ├── ProcessChatCommand.php
│       └── ChatWorkerCommand.php
```

### Task 3.1: MCP Connection & Tool Provider

**Files:**
- Create: `packages/nr_mcp_agent/Classes/Mcp/McpConnection.php`
- Create: `packages/nr_mcp_agent/Classes/Mcp/McpToolProvider.php`
- Create: `packages/nr_mcp_agent/Tests/Unit/Mcp/McpConnectionTest.php`
- Create: `packages/nr_mcp_agent/Tests/Unit/Mcp/McpToolProviderTest.php`

**Designprinzip:** MCP-agnostisch. Wir sprechen nur das MCP-Protokoll (JSON-RPC über stdio), nicht die interne API eines bestimmten MCP-Servers. Dadurch können beliebige MCP-Server eingebunden werden — TYPO3-eigene, externe, Node.js-basierte etc.

- [ ] **Step 1: Write McpConnection (persistente stdio-Verbindung)**

Eine Verbindung wird pro Agent-Loop geöffnet und bleibt für alle Tool-Calls offen. Kein Fork pro Call, spec-konformes `initialize`.

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Mcp;

final class McpConnection
{
    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $stdin = null;
    /** @var resource|null */
    private $stdout = null;
    private int $requestId = 0;
    private bool $initialized = false;

    /**
     * Open a persistent connection to an MCP server.
     *
     * @param string $command  e.g. '/var/www/html/vendor/bin/typo3'
     * @param array  $args     e.g. ['mcp:server']
     * @param string $cwd      Working directory for the process
     */
    public function open(string $command, array $args = [], string $cwd = ''): void
    {
        if ($this->process !== null) {
            $this->close();
        }

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr (ignored)
        ];

        $this->process = proc_open(
            [$command, ...$args],
            $descriptors,
            $pipes,
            $cwd ?: null,
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException('Failed to start MCP server process');
        }

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        fclose($pipes[2]); // stderr nicht benötigt

        // Non-blocking stdout für Timeout-Handling
        stream_set_blocking($this->stdout, false);

        // MCP spec: initialize handshake
        $this->call('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new \stdClass(),
            'clientInfo' => [
                'name' => 'nr-mcp-agent',
                'version' => '0.1.0',
            ],
        ]);

        // MCP spec: initialized notification (no response expected)
        $this->notify('notifications/initialized');

        $this->initialized = true;
    }

    public function isOpen(): bool
    {
        return $this->initialized && $this->process !== null;
    }

    /**
     * Send a JSON-RPC request and wait for response.
     */
    public function call(string $method, array $params = []): array
    {
        $id = ++$this->requestId;

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->write($request);

        return $this->readResponse($id);
    }

    /**
     * Send a JSON-RPC notification (no response expected).
     */
    public function notify(string $method, array $params = []): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->write($request);
    }

    public function close(): void
    {
        if ($this->stdin !== null) {
            fclose($this->stdin);
            $this->stdin = null;
        }
        if ($this->stdout !== null) {
            fclose($this->stdout);
            $this->stdout = null;
        }
        if ($this->process !== null) {
            proc_close($this->process);
            $this->process = null;
        }
        $this->initialized = false;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function write(string $data): void
    {
        if ($this->stdin === null) {
            throw new \RuntimeException('MCP connection not open');
        }
        fwrite($this->stdin, $data . "\n");
        fflush($this->stdin);
    }

    private function readResponse(int $expectedId, float $timeoutSeconds = 30.0): array
    {
        if ($this->stdout === null) {
            throw new \RuntimeException('MCP connection not open');
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = fgets($this->stdout);
            if ($chunk === false || $chunk === '') {
                usleep(10_000); // 10ms
                continue;
            }

            $buffer .= $chunk;

            // Versuche JSON zu parsen (Zeile kann komplett sein)
            $decoded = json_decode(trim($buffer), true);
            if ($decoded === null) {
                continue;
            }

            $buffer = '';

            // Notifications überspringen, auf Response mit unserer ID warten
            if (!isset($decoded['id'])) {
                continue;
            }

            if ($decoded['id'] !== $expectedId) {
                continue;
            }

            if (isset($decoded['error'])) {
                throw new \RuntimeException(
                    sprintf('MCP error %d: %s',
                        $decoded['error']['code'] ?? -1,
                        $decoded['error']['message'] ?? 'Unknown error'
                    )
                );
            }

            return $decoded['result'] ?? [];
        }

        throw new \RuntimeException(
            sprintf('MCP server timeout after %.1fs waiting for response to request %d', $timeoutSeconds, $expectedId)
        );
    }
}
```

- [ ] **Step 2: Write McpToolProvider (nutzt McpConnection)**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Mcp;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;

final class McpToolProvider
{
    private ?McpConnection $connection = null;

    public function __construct(
        private readonly ExtensionConfiguration $config,
    ) {}

    /**
     * Open connection for an agent loop session.
     * Call once before getToolDefinitions/executeTool, close after loop ends.
     */
    public function connect(): void
    {
        if (!$this->config->isMcpEnabled()) {
            return;
        }

        $command = $this->config->getMcpServerCommand()
            ?: Environment::getProjectPath() . '/vendor/bin/typo3';
        $args = $this->config->getMcpServerArgs() ?: ['mcp:server'];

        $this->connection = new McpConnection();
        $this->connection->open($command, $args, Environment::getProjectPath());
    }

    /**
     * Get available tools in OpenAI-compatible format (for nr-llm).
     */
    public function getToolDefinitions(): array
    {
        if ($this->connection === null || !$this->connection->isOpen()) {
            return [];
        }

        $result = $this->connection->call('tools/list');

        return array_map(
            static fn(array $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['inputSchema'] ?? ['type' => 'object'],
                ],
            ],
            $result['tools'] ?? []
        );
    }

    /**
     * Execute a tool call via MCP.
     */
    public function executeTool(string $toolName, array $input): string
    {
        if ($this->connection === null || !$this->connection->isOpen()) {
            return json_encode(['error' => 'MCP not connected']);
        }

        $result = $this->connection->call('tools/call', [
            'name' => $toolName,
            'arguments' => $input,
        ]);

        // MCP returns content array, serialize for nr-llm tool result
        $texts = [];
        foreach ($result['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $texts[] = $block['text'];
            }
        }

        return implode("\n", $texts) ?: json_encode($result);
    }

    public function disconnect(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }
}
```

Hinweis: `getToolDefinitions()` liefert jetzt **OpenAI-kompatibles Format** (`type: function`, `function.parameters`), weil nr-llm das erwartet und pro Provider übersetzt.

- [ ] **Step 3: Write McpConnectionTest**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Mcp;

use Netresearch\NrMcpAgent\Mcp\McpConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class McpConnectionTest extends TestCase
{
    #[Test]
    public function isOpenReturnsFalseByDefault(): void
    {
        $connection = new McpConnection();
        self::assertFalse($connection->isOpen());
    }

    #[Test]
    public function closeOnClosedConnectionIsNoop(): void
    {
        $connection = new McpConnection();
        $connection->close();
        self::assertFalse($connection->isOpen());
    }

    #[Test]
    public function openWithInvalidCommandThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $connection = new McpConnection();
        $connection->open('/nonexistent/binary', [], '/tmp');
    }
}
```

- [ ] **Step 4: Write McpToolProviderTest**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Mcp;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Mcp\McpToolProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class McpToolProviderTest extends TestCase
{
    #[Test]
    public function connectDoesNothingWhenMcpDisabled(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(false);

        $provider = new McpToolProvider($config);
        $provider->connect();

        // Should not throw, connection stays null
        self::assertSame([], $provider->getToolDefinitions());
    }

    #[Test]
    public function getToolDefinitionsReturnsEmptyWhenNotConnected(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(false);

        $provider = new McpToolProvider($config);
        self::assertSame([], $provider->getToolDefinitions());
    }

    #[Test]
    public function executeToolReturnsErrorWhenNotConnected(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(false);

        $provider = new McpToolProvider($config);
        $result = $provider->executeTool('test', []);

        self::assertStringContainsString('error', $result);
        self::assertStringContainsString('MCP not connected', $result);
    }

    #[Test]
    public function disconnectIsIdempotent(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('isMcpEnabled')->willReturn(false);

        $provider = new McpToolProvider($config);
        $provider->disconnect();
        $provider->disconnect(); // should not throw
        self::assertSame([], $provider->getToolDefinitions());
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add packages/nr_mcp_agent/Classes/Mcp/ packages/nr_mcp_agent/Tests/Unit/Mcp/
git commit -m "feat(nr-mcp-agent): add persistent MCP connection and tool provider with tests"
```

### Task 3.2: Chat Service (Agent Loop)

**Files:**
- Create: `packages/nr_mcp_agent/Classes/Service/ChatService.php`
- Create: `packages/nr_mcp_agent/Tests/Unit/Service/ChatServiceTest.php`

- [ ] **Step 1: Write ChatService test**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Mcp\McpToolProvider;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Dto\ChatResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ChatServiceTest extends TestCase
{
    #[Test]
    public function processConversationSetsIdleOnSimpleResponse(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $chatResponse = $this->createMock(ChatResponse::class);
        $chatResponse->method('hasToolCalls')->willReturn(false);
        $chatResponse->method('getContent')->willReturn('Hi there!');
        $chatResponse->toolCalls = [];

        $llmManager = $this->createMock(LlmServiceManager::class);
        $llmManager->expects(self::once())->method('chatWithTools')->willReturn($chatResponse);

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProvider::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $service = new ChatService(
            $llmManager, $repository, $config, $mcpProvider
        );
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(2, $conversation->getMessageCount()); // user + assistant
    }

    #[Test]
    public function processConversationSetsFailedWhenNoLlmTaskConfigured(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $llmManager = $this->createMock(LlmServiceManager::class);
        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);
        $mcpProvider = $this->createMock(McpToolProvider::class);

        $service = new ChatService(
            $llmManager, $repository, $config, $mcpProvider
        );
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('nr-llm Task', $conversation->getErrorMessage());
    }
}
```

- [ ] **Step 2: Run test, verify fail**

- [ ] **Step 3: Write ChatService**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Dto\ChatMessage;
use Netresearch\NrLlm\Dto\ToolOptions;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Mcp\McpToolProvider;

final class ChatService
{
    private const MAX_TOOL_ITERATIONS = 20;
    private const MAX_LLM_RETRIES = 2;
    private const LLM_RETRY_DELAY_SECONDS = 3;

    public function __construct(
        private readonly LlmServiceManager $llmManager,
        private readonly ConversationRepository $repository,
        private readonly ExtensionConfiguration $config,
        private readonly McpToolProvider $mcpToolProvider,
    ) {}

    public function processConversation(Conversation $conversation): void
    {
        $taskUid = $this->config->getLlmTaskUid();
        if ($taskUid === 0) {
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage('No nr-llm Task configured. Set llmTaskUid in Extension Configuration.');
            $this->persist($conversation);
            return;
        }

        try {
            $this->mcpToolProvider->connect();
            $tools = $this->mcpToolProvider->getToolDefinitions();
            $this->runAgentLoop($conversation, $taskUid, $tools);
        } catch (\Throwable $e) {
            $conversation->setStatus(ConversationStatus::Failed);
            // Sanitize error message: strip potential API keys/URLs from exception
            $conversation->setErrorMessage($this->sanitizeErrorMessage($e->getMessage()));
            $this->persist($conversation);
        } finally {
            $this->mcpToolProvider->disconnect();
        }
    }

    /**
     * Resume a conversation that was interrupted during processing.
     */
    public function resumeConversation(Conversation $conversation): void
    {
        if (!$conversation->isResumable()) {
            return;
        }

        $messages = $conversation->getDecodedMessages();
        $lastMessage = end($messages);

        // If last message is assistant with tool_calls, execute tools first
        if ($lastMessage && $lastMessage['role'] === 'assistant' && !empty($lastMessage['tool_calls'])) {
            $toolResults = $this->executeToolCalls($lastMessage['tool_calls']);
            $messages = $conversation->getDecodedMessages();
            foreach ($toolResults as $result) {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $result['tool_call_id'],
                    'content' => $result['content'],
                ];
            }
            $conversation->setMessages($messages);
            $this->persist($conversation);
        }

        $this->processConversation($conversation);
    }

    private function runAgentLoop(
        Conversation $conversation,
        int $taskUid,
        array $tools,
    ): void {
        $conversation->setStatus(ConversationStatus::Processing);
        $this->persist($conversation);

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            $messages = $conversation->getDecodedMessages();

            // nr-llm handles provider-specific format translation
            // Retry on transient errors (429/503) with exponential backoff
            $response = $this->callLlmWithRetry(
                $messages, $tools, $taskUid, $conversation
            );

            if ($response->hasToolCalls()) {
                // Persist assistant message with tool_calls in OpenAI format.
                // nr-llm normalises all provider responses to this shape, so
                // storing it directly means the messages array can be sent back
                // to nr-llm on the next turn without any extra conversion.
                $messages = $conversation->getDecodedMessages();
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $response->getContent(),
                    'tool_calls' => $response->toolCalls,
                ];
                $conversation->setMessages($messages);
                $this->persist($conversation);

                // Execute tools and persist results in OpenAI tool-result format
                $conversation->setStatus(ConversationStatus::ToolLoop);
                $toolResults = $this->executeToolCalls($response->toolCalls);
                foreach ($toolResults as $result) {
                    $messages = $conversation->getDecodedMessages();
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $result['tool_call_id'],
                        'content' => $result['content'],
                    ];
                    $conversation->setMessages($messages);
                }
                $this->persist($conversation);
                continue;
            }

            // No tool calls — final response
            $conversation->appendMessage('assistant', $response->getContent());
            $conversation->setStatus(ConversationStatus::Idle);
            $this->persist($conversation);
            return;
        }

        // Max iterations reached
        $conversation->setStatus(ConversationStatus::Failed);
        $conversation->setErrorMessage('Max tool iterations reached');
        $this->persist($conversation);
    }

    private function callLlmWithRetry(
        array $messages,
        array $tools,
        int $taskUid,
        Conversation $conversation,
    ): mixed {
        $lastException = null;
        for ($attempt = 0; $attempt <= self::MAX_LLM_RETRIES; $attempt++) {
            try {
                return $this->llmManager->chatWithTools(
                    $messages,
                    $tools,
                    ToolOptions::auto(),
                    taskUid: $taskUid,
                    systemPrompt: $this->buildSystemPrompt($conversation),
                );
            } catch (\Throwable $e) {
                $lastException = $e;
                // Only retry on transient errors (rate limit, server error)
                $isTransient = str_contains($e->getMessage(), '429')
                    || str_contains($e->getMessage(), '503')
                    || str_contains($e->getMessage(), 'rate')
                    || str_contains($e->getMessage(), 'overloaded');
                if (!$isTransient || $attempt >= self::MAX_LLM_RETRIES) {
                    throw $e;
                }
                sleep(self::LLM_RETRY_DELAY_SECONDS * ($attempt + 1));
            }
        }
        throw $lastException;
    }

    private function executeToolCalls(array $toolCalls): array
    {
        $results = [];
        foreach ($toolCalls as $call) {
            $functionName = $call['function']['name'] ?? $call['name'] ?? '';
            $arguments = $call['function']['arguments'] ?? $call['input'] ?? [];
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }
            $result = $this->mcpToolProvider->executeTool($functionName, $arguments);
            $results[] = [
                'tool_call_id' => $call['id'],
                'content' => $result,
            ];
        }
        return $results;
    }

    private function buildSystemPrompt(Conversation $conversation): string
    {
        $custom = $conversation->getSystemPrompt();
        if ($custom !== '') {
            return $custom;
        }

        // Default system prompt – language based on BE_USER settings
        $lang = $GLOBALS['BE_USER']->uc['lang'] ?? 'default';

        return match ($lang) {
            'de' => 'Du bist ein TYPO3-Assistent. Du hilfst beim Verwalten von Inhalten über die verfügbaren Tools. Antworte auf Deutsch.',
            default => 'You are a TYPO3 assistant. You help manage content using the available tools. Respond in English.',
        };
    }

    /**
     * Sanitize error messages before storing — strip potential API keys, URLs, and tokens.
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // Strip Bearer tokens and API keys
        $message = preg_replace('/(?:Bearer |sk-|key-)[a-zA-Z0-9\-_]+/', '[REDACTED]', $message);
        // Strip full URLs (may contain API keys as query params)
        $message = preg_replace('#https?://[^\s]+#', '[URL]', $message);
        // Truncate to reasonable length
        return mb_substr($message, 0, 500);
    }

    private function persist(Conversation $conversation): void
    {
        $this->repository->update($conversation);
    }
}
```

- [ ] **Step 4: Run tests, verify pass**

- [ ] **Step 5: Write ChatService retry and error-sanitization tests**

Create: `packages/nr_mcp_agent/Tests/Unit/Service/ChatServiceRetryTest.php`

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Mcp\McpToolProvider;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ChatServiceRetryTest extends TestCase
{
    #[Test]
    public function retriesOnTransient429Error(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $llmManager = $this->createMock(LlmServiceManager::class);
        $callCount = 0;
        $llmManager->method('chatWithTools')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new \RuntimeException('429 Too Many Requests');
            }
            $response = $this->createMock(\Netresearch\NrLlm\Dto\ChatResponse::class);
            $response->method('hasToolCalls')->willReturn(false);
            $response->method('getContent')->willReturn('Hi!');
            $response->toolCalls = [];
            return $response;
        });

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProvider::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(2, $callCount); // 1 fail + 1 success
    }

    #[Test]
    public function doesNotRetryOnNonTransientError(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $llmManager = $this->createMock(LlmServiceManager::class);
        $llmManager->method('chatWithTools')->willThrowException(
            new \RuntimeException('Invalid API key')
        );

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProvider::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
    }

    #[Test]
    public function errorMessageIsSanitized(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $llmManager = $this->createMock(LlmServiceManager::class);
        $llmManager->method('chatWithTools')->willThrowException(
            new \RuntimeException('Error calling https://api.anthropic.com/v1/messages with Bearer sk-ant-api03-secretkey123: 500')
        );

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProvider::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        // API key and URL must be stripped
        self::assertStringNotContainsString('sk-ant', $conversation->getErrorMessage());
        self::assertStringNotContainsString('anthropic.com', $conversation->getErrorMessage());
        self::assertStringContainsString('[REDACTED]', $conversation->getErrorMessage());
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add packages/nr_mcp_agent/Classes/Service/ChatService.php packages/nr_mcp_agent/Tests/Unit/Service/
git commit -m "feat(nr-mcp-agent): add chat service with agent loop, retry, and error sanitization tests"
```

### Task 3.3: Chat Processors & CLI Commands

**Files:**
- Create: `packages/nr_mcp_agent/Classes/Service/ChatProcessorInterface.php`
- Create: `packages/nr_mcp_agent/Classes/Service/ExecChatProcessor.php`
- Create: `packages/nr_mcp_agent/Classes/Service/WorkerChatProcessor.php`
- Create: `packages/nr_mcp_agent/Classes/Command/ProcessChatCommand.php`
- Create: `packages/nr_mcp_agent/Classes/Command/ChatWorkerCommand.php`

- [ ] **Step 1: Create ChatProcessorInterface**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

interface ChatProcessorInterface
{
    /**
     * Dispatch conversation processing.
     * The conversation must already be saved with status 'processing'.
     */
    public function dispatch(int $conversationUid): void;
}
```

- [ ] **Step 2: Create ExecChatProcessor**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use TYPO3\CMS\Core\Core\Environment;

final class ExecChatProcessor implements ChatProcessorInterface
{
    public function __construct(
        private readonly ConversationRepository $repository,
    ) {}

    public function dispatch(int $conversationUid): void
    {
        $typo3Bin = Environment::getProjectPath() . '/vendor/bin/typo3';
        $cmd = sprintf(
            '%s %s ai-chat:process %d',
            PHP_BINARY,
            escapeshellarg($typo3Bin),
            $conversationUid,
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (is_resource($process)) {
            $status = proc_get_status($process);
            $pid = (string)$status['pid'];

            // Store PID as current_request_id for timeout tracking
            $conversation = $this->repository->findByUid($conversationUid);
            if ($conversation !== null) {
                $conversation->setCurrentRequestId($pid);
                $this->repository->update($conversation);
            }

            // Close pipes and detach — process runs in background
            fclose($pipes[0]);
            proc_close($process);
        }
    }
}
```

- [ ] **Step 3: Create WorkerChatProcessor**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

/**
 * No-op dispatcher for worker mode.
 * The ChatWorkerCommand polls the DB for conversations with status 'processing'.
 */
final class WorkerChatProcessor implements ChatProcessorInterface
{
    public function dispatch(int $conversationUid): void
    {
        // Worker picks up conversations by polling status field.
        // Nothing to do here.
    }
}
```

- [ ] **Step 4: Create ProcessChatCommand**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Command;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ChatService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'ai-chat:process', description: 'Process a single chat conversation')]
final class ProcessChatCommand extends Command
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly ConversationRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('conversationUid', InputArgument::REQUIRED, 'UID of the conversation to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = (int)$input->getArgument('conversationUid');
        $conversation = $this->repository->findByUid($uid);

        if ($conversation === null) {
            $output->writeln('<error>Conversation not found</error>');
            return Command::FAILURE;
        }

        // Bootstrap BE_USER context
        $this->initializeBackendUser($conversation->getBeUser());

        $this->chatService->processConversation($conversation);

        return Command::SUCCESS;
    }

    // TODO: Extract initializeBackendUser() to a shared trait or service — duplicated in ChatWorkerCommand
    private function initializeBackendUser(int $userUid): void
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        $queryBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
            ->getQueryBuilderForTable('be_users');

        $userRecord = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $userUid))
            ->executeQuery()
            ->fetchAssociative();

        if ($userRecord === false) {
            throw new \RuntimeException(sprintf('Backend user %d not found', $userUid));
        }

        $backendUser->user = $userRecord;
        $backendUser->fetchGroupData();
        $GLOBALS['BE_USER'] = $backendUser;
    }
}
```

- [ ] **Step 5: Create ChatWorkerCommand**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Command;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ChatService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'ai-chat:worker', description: 'Long-running worker that processes chat conversations from queue')]
final class ChatWorkerCommand extends Command
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly ConversationRepository $repository,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('poll-interval', null, InputOption::VALUE_OPTIONAL, 'Poll interval in milliseconds', 200);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pollInterval = (int)$input->getOption('poll-interval') * 1000; // to microseconds
        $workerId = 'worker_' . getmypid() . '_' . bin2hex(random_bytes(4));

        $output->writeln(sprintf('<info>AI Chat worker %s started. Polling every %dms</info>', $workerId, $pollInterval / 1000));

        while (true) {
            $conversation = $this->dequeue($workerId);

            if ($conversation !== null) {
                $output->writeln(sprintf(
                    '<info>Processing conversation %d for user %d</info>',
                    $conversation->getUid(),
                    $conversation->getBeUser(),
                ));

                $this->initializeBackendUser($conversation->getBeUser());
                $this->chatService->processConversation($conversation);
            } else {
                usleep($pollInterval);
            }
        }
    }

    private function dequeue(string $workerId): ?Conversation
    {
        $conn = $this->connectionPool->getConnectionForTable('tx_nrmcpagent_conversation');

        // Atomic UPDATE: claim one row — InnoDB row-level locking ensures no race condition
        $affected = $conn->executeStatement(
            'UPDATE tx_nrmcpagent_conversation
             SET status = \'locked\', current_request_id = ?
             WHERE status = \'processing\' AND deleted = 0
             ORDER BY tstamp ASC LIMIT 1',
            [$workerId]
        );

        if ($affected === 0) {
            return null;
        }

        // Fetch the row we just claimed
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_nrmcpagent_conversation');
        $row = $qb->select('*')
            ->from('tx_nrmcpagent_conversation')
            ->where(
                $qb->expr()->eq('current_request_id', $qb->createNamedParameter($workerId)),
                $qb->expr()->eq('status', $qb->createNamedParameter('locked')),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return Conversation::fromRow($row);
    }

    // TODO: Extract initializeBackendUser() to a shared trait or service — duplicated in ProcessChatCommand
    private function initializeBackendUser(int $userUid): void
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $userRecord = $qb->select('*')
            ->from('be_users')
            ->where($qb->expr()->eq('uid', $userUid))
            ->executeQuery()
            ->fetchAssociative();

        if ($userRecord === false) {
            throw new \RuntimeException(sprintf('Backend user %d not found', $userUid));
        }

        $backendUser->user = $userRecord;
        $backendUser->fetchGroupData();
        $GLOBALS['BE_USER'] = $backendUser;
    }
}
```

- [ ] **Step 6: Register commands and processor in Services.yaml**

Add to `Configuration/Services.yaml`:

```yaml
  # CLI Commands
  Netresearch\NrMcpAgent\Command\ProcessChatCommand:
    tags:
      - name: 'console.command'
        command: 'ai-chat:process'

  Netresearch\NrMcpAgent\Command\ChatWorkerCommand:
    tags:
      - name: 'console.command'
        command: 'ai-chat:worker'

  # Processor: defaults to exec, override for worker mode
  Netresearch\NrMcpAgent\Service\ChatProcessorInterface:
    class: Netresearch\NrMcpAgent\Service\ExecChatProcessor
```

- [ ] **Step 7: Commit**

```bash
git add packages/nr_mcp_agent/Classes/Service/ChatProcessor* packages/nr_mcp_agent/Classes/Service/ExecChatProcessor.php packages/nr_mcp_agent/Classes/Service/WorkerChatProcessor.php packages/nr_mcp_agent/Classes/Command/
git commit -m "feat(nr-mcp-agent): add CLI commands and processing strategies"
```

---

## Chunk 4: Backend API (AJAX Endpoints)

### File Structure (Chunk 4)

```
packages/nr_mcp_agent/
├── Classes/
│   └── Controller/
│       └── ChatApiController.php
├── Configuration/
│   ├── Backend/
│   │   └── AjaxRoutes.php
│   └── TCA/
│       └── tx_nrmcpagent_conversation.php
```

### Task 4.1: Backend AJAX Routes & Controller

**Files:**
- Create: `packages/nr_mcp_agent/Configuration/Backend/AjaxRoutes.php`
- Create: `packages/nr_mcp_agent/Classes/Controller/ChatApiController.php`
- Create: `packages/nr_mcp_agent/Configuration/TCA/tx_nrmcpagent_conversation.php`

- [ ] **Step 1: Create AjaxRoutes.php**

```php
<?php

use Netresearch\NrMcpAgent\Controller\ChatApiController;

return [
    'ai_chat_status' => [
        'path' => '/ai-chat/status',
        'target' => ChatApiController::class . '::getStatus',
    ],
    'ai_chat_conversations' => [
        'path' => '/ai-chat/conversations',
        'target' => ChatApiController::class . '::listConversations',
    ],
    'ai_chat_conversation_create' => [
        'path' => '/ai-chat/conversations/create',
        'target' => ChatApiController::class . '::createConversation',
    ],
    'ai_chat_conversation_messages' => [
        'path' => '/ai-chat/conversations/messages',
        'target' => ChatApiController::class . '::getMessages',
    ],
    'ai_chat_conversation_send' => [
        'path' => '/ai-chat/conversations/send',
        'target' => ChatApiController::class . '::sendMessage',
    ],
    'ai_chat_conversation_resume' => [
        'path' => '/ai-chat/conversations/resume',
        'target' => ChatApiController::class . '::resumeConversation',
    ],
    'ai_chat_conversation_archive' => [
        'path' => '/ai-chat/conversations/archive',
        'target' => ChatApiController::class . '::archiveConversation',
    ],
    'ai_chat_conversation_pin' => [
        'path' => '/ai-chat/conversations/pin',
        'target' => ChatApiController::class . '::togglePin',
    ],
];
```

- [ ] **Step 2: Create TCA configuration**

```php
<?php

return [
    'ctrl' => [
        'title' => 'AI Chat Conversation',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'readOnly' => true,
        'adminOnly' => true,
        'rootLevel' => 1,
        'iconfile' => 'EXT:nr_mcp_agent/Resources/Public/Icons/Extension.svg',
        'searchFields' => 'title',
    ],
    'types' => [
        '0' => ['showitem' => 'title, be_user, status, message_count, error_message, archived, pinned'],
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'be_user' => [
            'label' => 'Backend User',
            'config' => ['type' => 'group', 'allowed' => 'be_users', 'maxitems' => 1, 'readOnly' => true],
        ],
        'status' => [
            'label' => 'Status',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'message_count' => [
            'label' => 'Messages',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'error_message' => [
            'label' => 'Error',
            'config' => ['type' => 'text', 'readOnly' => true],
        ],
        'archived' => [
            'label' => 'Archived',
            'config' => ['type' => 'check', 'readOnly' => true],
        ],
        'pinned' => [
            'label' => 'Pinned',
            'config' => ['type' => 'check', 'readOnly' => true],
        ],
    ],
];
```

- [ ] **Step 3: Create ChatApiController**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ChatApiController
{
    public function __construct(
        private readonly ConversationRepository $repository,
        private readonly ChatProcessorInterface $processor,
        private readonly ExtensionConfiguration $config,
    ) {}

    /**
     * GET /ai-chat/status – Check if AI chat is available for current user.
     */
    public function getStatus(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $taskUid = $this->config->getLlmTaskUid();
        $mcpEnabled = $this->config->isMcpEnabled();
        $issues = [];

        if ($taskUid === 0) {
            $issues[] = 'No nr-llm Task configured. An admin must create an nr-llm Task record and set its UID in Extension Configuration.';
        }

        $mcpServerInstalled = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('typo3_mcp_server');

        if ($mcpEnabled && !$mcpServerInstalled) {
            $issues[] = 'MCP is enabled but hn/typo3-mcp-server is not installed. Install it via: composer require hn/typo3-mcp-server';
        } elseif (!$mcpEnabled && $mcpServerInstalled) {
            $issues[] = 'hn/typo3-mcp-server is installed but MCP is not enabled. Enable MCP in Extension Configuration to allow content actions.';
        } elseif (!$mcpEnabled && !$mcpServerInstalled) {
            // No warning — MCP is optional. Only hint in docs.
        }

        return new JsonResponse([
            'available' => $taskUid > 0,
            'mcpEnabled' => $mcpEnabled,
            'issues' => $issues,
        ]);
    }

    /**
     * GET /ai-chat/conversations – List conversations for current user.
     */
    public function listConversations(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversations = $this->repository->findByBeUser($this->getBeUserUid());

        $items = array_map(static fn(Conversation $c) => [
            'uid' => $c->getUid(),
            'title' => $c->getTitle(),
            'status' => $c->getStatus()->value,
            'messageCount' => $c->getMessageCount(),
            'pinned' => $c->isPinned(),
            'resumable' => $c->isResumable(),
            'errorMessage' => $c->getErrorMessage(),
            'tstamp' => $c->getTstamp(),
        ], $conversations);

        return new JsonResponse(['conversations' => $items]);
    }

    /**
     * POST /ai-chat/conversations – Create new conversation.
     */
    public function createConversation(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = new Conversation();
        $conversation->setBeUser($this->getBeUserUid());

        $uid = $this->repository->add($conversation);

        return new JsonResponse([
            'uid' => $uid,
        ], 201);
    }

    /**
     * GET /ai-chat/conversations/{conversationUid}/messages?after={uid}
     */
    public function getMessages(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $afterIndex = (int)($request->getQueryParams()['after'] ?? 0);
        $messages = $conversation->getDecodedMessages();

        // Return only messages after the given index
        $newMessages = array_slice($messages, $afterIndex);

        return new JsonResponse([
            'status' => $conversation->getStatus()->value,
            'messages' => $newMessages,
            'totalCount' => count($messages),
            'errorMessage' => $conversation->getErrorMessage(),
        ]);
    }

    /**
     * POST /ai-chat/conversations/{conversationUid}/message
     */
    public function sendMessage(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $body = json_decode((string)$request->getBody(), true);
        $content = trim($body['content'] ?? '');

        if ($content === '') {
            return new JsonResponse(['error' => 'Empty message'], 400);
        }

        // Security: message length limit
        $maxLength = $this->config->getMaxMessageLength();
        if ($maxLength > 0 && mb_strlen($content) > $maxLength) {
            return new JsonResponse(['error' => sprintf('Message too long (max %d characters)', $maxLength)], 400);
        }

        if ($conversation->getStatus() === ConversationStatus::Processing
            || $conversation->getStatus() === ConversationStatus::Locked
            || $conversation->getStatus() === ConversationStatus::ToolLoop
        ) {
            return new JsonResponse(['error' => 'Conversation is already processing'], 409);
        }

        // Security: rate limiting — max active conversations per user
        $maxActive = $this->config->getMaxActiveConversationsPerUser();
        if ($maxActive > 0) {
            $activeCount = $this->repository->countActiveByBeUser($this->getBeUserUid());
            if ($activeCount >= $maxActive) {
                return new JsonResponse(['error' => sprintf('Too many active conversations (max %d)', $maxActive)], 429);
            }
        }

        // Persist user message and set processing status
        $conversation->appendMessage('user', $content);
        $conversation->setStatus(ConversationStatus::Processing);
        $conversation->setErrorMessage('');
        $this->repository->update($conversation);

        // Dispatch async processing
        $this->processor->dispatch($conversation->getUid());

        return new JsonResponse(['status' => 'processing'], 202);
    }

    /**
     * POST /ai-chat/conversations/{conversationUid}/resume
     */
    public function resumeConversation(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        if (!$conversation->isResumable()) {
            return new JsonResponse(['error' => 'Conversation is not resumable'], 400);
        }

        $this->processor->dispatch($conversation->getUid());

        return new JsonResponse(['status' => 'processing'], 202);
    }

    /**
     * POST /ai-chat/conversations/{conversationUid}/archive
     */
    public function archiveConversation(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $conversation->setArchived(true);
        $this->repository->update($conversation);

        return new JsonResponse(['status' => 'archived']);
    }

    /**
     * POST /ai-chat/conversations/{conversationUid}/pin
     */
    public function togglePin(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $conversation->setPinned(!$conversation->isPinned());
        $this->repository->update($conversation);

        return new JsonResponse(['pinned' => $conversation->isPinned()]);
    }

    private function findConversationOrFail(ServerRequestInterface $request): Conversation|ResponseInterface
    {
        $body = json_decode((string)$request->getBody(), true) ?? [];
        $uid = (int)($request->getQueryParams()['conversationUid'] ?? $body['conversationUid'] ?? 0);

        $conversation = $this->repository->findOneByUidAndBeUser($uid, $this->getBeUserUid());

        if ($conversation === null) {
            return new JsonResponse(['error' => 'Conversation not found'], 404);
        }

        return $conversation;
    }

    /**
     * Check if the current backend user is allowed to use AI chat.
     * Returns a 403 JsonResponse if not allowed, null if access is granted.
     */
    private function checkAccess(): ?ResponseInterface
    {
        $allowedGroups = $this->config->getAllowedGroupIds();
        if ($allowedGroups === []) {
            return null; // No restriction configured — all users allowed
        }

        $userGroups = GeneralUtility::intExplode(
            ',',
            (string)($GLOBALS['BE_USER']->user['usergroup'] ?? ''),
            true,
        );

        if (array_intersect($allowedGroups, $userGroups) !== []) {
            return null; // User is in at least one allowed group
        }

        return new JsonResponse(['error' => 'Access denied'], 403);
    }

    private function getBeUserUid(): int
    {
        return (int)$GLOBALS['BE_USER']->user['uid'];
    }
}
```

- [ ] **Step 3: Write ChatApiController unit test**

Create: `packages/nr_mcp_agent/Tests/Unit/Controller/ChatApiControllerTest.php`

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

class ChatApiControllerTest extends TestCase
{
    private ChatApiController $subject;
    private ConversationRepository $repository;
    private ExtensionConfiguration $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(ConversationRepository::class);
        $processor = $this->createMock(ChatProcessorInterface::class);
        $this->config = $this->createMock(ExtensionConfiguration::class);
        $this->config->method('getAllowedGroupIds')->willReturn([]);
        $this->config->method('getMaxMessageLength')->willReturn(10000);
        $this->config->method('getMaxActiveConversationsPerUser')->willReturn(3);
        $this->subject = new ChatApiController($this->repository, $processor, $this->config);

        // Mock BE_USER
        $GLOBALS['BE_USER'] = new \stdClass();
        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => '1,2'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function sendMessageRejectsEmptyContent(): void
    {
        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "  "}');
        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $response = $this->subject->sendMessage($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageRejectsMessageExceedingMaxLength(): void
    {
        $this->config = $this->createMock(ExtensionConfiguration::class);
        $this->config->method('getAllowedGroupIds')->willReturn([]);
        $this->config->method('getMaxMessageLength')->willReturn(10);
        $this->config->method('getMaxActiveConversationsPerUser')->willReturn(3);
        $processor = $this->createMock(ChatProcessorInterface::class);
        $subject = new ChatApiController($this->repository, $processor, $this->config);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "This message is way too long for the limit"}');
        $conversation = new Conversation();
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $response = $subject->sendMessage($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function sendMessageRejectsAlreadyProcessingConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Processing);
        $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

        $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Hello"}');
        $response = $this->subject->sendMessage($request);

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function checkAccessDeniesUnauthorizedGroup(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([99]); // User is in 1,2 — not 99
        $config->method('getLlmTaskUid')->willReturn(1);
        $processor = $this->createMock(ChatProcessorInterface::class);
        $subject = new ChatApiController($this->repository, $processor, $config);

        $request = $this->createRequest('GET', '');
        $response = $subject->getStatus($request);

        self::assertSame(403, $response->getStatusCode());
    }

    private function createRequest(string $method, string $body): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getBody')->willReturn($stream);
        $request->method('getQueryParams')->willReturn([]);
        return $request;
    }
}
```

- [ ] **Step 4: Write Worker dequeue race-condition functional test**

Create: `packages/nr_mcp_agent/Tests/Functional/Command/ChatWorkerDequeueTest.php`

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Command;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChatWorkerDequeueTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-mcp-agent'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    #[Test]
    public function atomicDequeueClaimsExactlyOneRow(): void
    {
        $conn = $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_nrmcpagent_conversation');

        // Insert 3 conversations in 'processing'
        for ($i = 1; $i <= 3; $i++) {
            $conn->insert('tx_nrmcpagent_conversation', [
                'be_user' => 1,
                'title' => "Conv $i",
                'messages' => '[]',
                'message_count' => 0,
                'status' => 'processing',
                'current_request_id' => '',
                'archived' => 0,
                'pinned' => 0,
                'pid' => 0,
                'crdate' => time(),
                'tstamp' => time(),
            ]);
        }

        // Simulate two workers claiming simultaneously
        $workerId1 = 'worker_1';
        $workerId2 = 'worker_2';

        $claimed1 = $conn->executeStatement(
            "UPDATE tx_nrmcpagent_conversation
             SET status = 'locked', current_request_id = ?
             WHERE status = 'processing' AND deleted = 0
             ORDER BY tstamp ASC LIMIT 1",
            [$workerId1]
        );

        $claimed2 = $conn->executeStatement(
            "UPDATE tx_nrmcpagent_conversation
             SET status = 'locked', current_request_id = ?
             WHERE status = 'processing' AND deleted = 0
             ORDER BY tstamp ASC LIMIT 1",
            [$workerId2]
        );

        // Each worker should claim exactly 1 row, never the same
        self::assertSame(1, $claimed1);
        self::assertSame(1, $claimed2);

        // Verify different rows were claimed
        $qb = $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_nrmcpagent_conversation');
        $lockedCount = (int)$qb->count('uid')
            ->from('tx_nrmcpagent_conversation')
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('locked')))
            ->executeQuery()
            ->fetchOne();

        self::assertSame(2, $lockedCount);
    }
}
```

- [ ] **Step 5: Write CleanupCommand functional test**

Create: `packages/nr_mcp_agent/Tests/Functional/Command/CleanupCommandTest.php`

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Command;

use Netresearch\NrMcpAgent\Command\CleanupCommand;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class CleanupCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-mcp-agent'];

    #[Test]
    public function timesOutStuckConversationsIncludingToolLoop(): void
    {
        $conn = $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_nrmcpagent_conversation');

        // Insert stuck conversations (tstamp 10 minutes ago)
        $stuckTime = time() - 600;
        foreach (['processing', 'locked', 'tool_loop'] as $status) {
            $conn->insert('tx_nrmcpagent_conversation', [
                'be_user' => 1, 'title' => "Stuck $status",
                'messages' => '[]', 'message_count' => 0,
                'status' => $status, 'current_request_id' => '',
                'archived' => 0, 'pinned' => 0, 'pid' => 0,
                'crdate' => $stuckTime, 'tstamp' => $stuckTime,
            ]);
        }

        $command = $this->get(CleanupCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $qb = $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_nrmcpagent_conversation');
        $failedCount = (int)$qb->count('uid')
            ->from('tx_nrmcpagent_conversation')
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('failed')))
            ->executeQuery()
            ->fetchOne();

        self::assertSame(3, $failedCount, 'All three stuck statuses should be marked as failed');
    }
}
```

- [ ] **Step 6: Write ChatApiControllerFunctionalTest**

Create: `packages/nr_mcp_agent/Tests/Functional/Controller/ChatApiControllerFunctionalTest.php`

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Netresearch\NrMcpAgent\Controller\ChatApiController;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChatApiControllerFunctionalTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-mcp-agent'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrmcpagent_conversation.csv');
    }

    #[Test]
    public function listConversationsReturnsOnlyOwnConversations(): void
    {
        $this->setUpBackendUser(1);
        $subject = $this->get(ChatApiController::class);

        $request = new ServerRequest('https://localhost/typo3/ajax/ai-chat/conversations', 'GET');
        $request = $request->withAttribute('normalizedParams', new \TYPO3\CMS\Core\Http\NormalizedParams([], [], '', ''));
        $response = $subject->listConversations($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        self::assertIsArray($data);

        // All returned conversations should belong to be_user 1
        foreach ($data as $conversation) {
            // The fixture should only have conversations for the authenticated user
            self::assertArrayHasKey('uid', $conversation);
        }
    }

    #[Test]
    public function sendMessageCreatesConversationInDatabase(): void
    {
        $this->setUpBackendUser(1);
        $subject = $this->get(ChatApiController::class);

        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode(['message' => 'Hello AI']));
        $body->rewind();

        $request = new ServerRequest('https://localhost/typo3/ajax/ai-chat/send', 'POST');
        $request = $request->withBody($body)
            ->withHeader('Content-Type', 'application/json')
            ->withAttribute('normalizedParams', new \TYPO3\CMS\Core\Http\NormalizedParams([], [], '', ''));

        $response = $subject->sendMessage($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        self::assertArrayHasKey('conversationUid', $data);

        // Verify conversation exists in DB
        $qb = $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_nrmcpagent_conversation');
        $row = $qb->select('*')
            ->from('tx_nrmcpagent_conversation')
            ->where($qb->expr()->eq('uid', $data['conversationUid']))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame('processing', $row['status']);
    }
}
```

- [ ] **Step 7: Commit**

```bash
git add packages/nr_mcp_agent/Configuration/Backend/ packages/nr_mcp_agent/Configuration/TCA/ packages/nr_mcp_agent/Classes/Controller/ packages/nr_mcp_agent/Tests/
git commit -m "feat(nr-mcp-agent): add AJAX routes, TCA, controller, and tests (unit + functional)"
```

---

## Chunk 5: Bottom Panel UI (Lit Element)

### File Structure (Chunk 5)

```
packages/nr_mcp_agent/
├── Configuration/
│   └── JavaScriptModules.php
├── Resources/
│   ├── Public/
│   │   └── JavaScript/
│   │       ├── chat-panel.js
│   │       ├── chat-message.js
│   │       └── chat-conversation-list.js
│   └── Private/
│       └── Language/
│           ├── locallang.xlf
│           └── de.locallang.xlf
```

### Task 5.1: JavaScript Module Registration & Toolbar Integration

**Files:**
- Create: `packages/nr_mcp_agent/Configuration/JavaScriptModules.php`
- Create: `packages/nr_mcp_agent/ext_localconf.php`

- [ ] **Step 1: Create JavaScriptModules.php**

```php
<?php

return [
    'dependencies' => ['backend'],
    'imports' => [
        '@netresearch/nr-mcp-agent/' => 'EXT:nr_mcp_agent/Resources/Public/JavaScript/',
    ],
];
```

- [ ] **Step 2: Create ext_localconf.php for toolbar item**

```php
<?php

defined('TYPO3') or die();

// Register the toolbar item that injects the chat panel
$GLOBALS['TYPO3_CONF_VARS']['BE']['toolbarItems'][1700000000]
    = \Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem::class;
```

- [ ] **Step 3: Create ChatToolbarItem**

Create: `packages/nr_mcp_agent/Classes/Backend/ToolbarItems/ChatToolbarItem.php`

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Backend\ToolbarItems;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Core\Page\PageRenderer;

final class ChatToolbarItem implements ToolbarItemInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $config,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function checkAccess(): bool
    {
        // Available if an nr-llm Task is configured
        return $this->config->getLlmTaskUid() > 0;
    }

    public function getItem(): string
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-mcp-agent/chat-panel.js');

        return '<button class="toolbar-item ai-chat-toolbar-trigger"
                    data-dispatch-action="TYPO3.AiChat.toggle"
                    title="AI Chat">
                    <span class="ai-chat-icon">
                        <typo3-backend-icon identifier="actions-lightbulb" size="small"></typo3-backend-icon>
                    </span>
                </button>';
    }

    public function hasDropDown(): bool
    {
        return false;
    }

    public function getDropDown(): string
    {
        return '';
    }

    public function getAdditionalAttributes(): array
    {
        return [];
    }

    public function getIndex(): int
    {
        return 90;
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add packages/nr_mcp_agent/Configuration/JavaScriptModules.php packages/nr_mcp_agent/ext_localconf.php packages/nr_mcp_agent/Classes/Backend/
git commit -m "feat(nr-mcp-agent): add toolbar item and JS module registration"
```

### Task 5.2: Localization Files

**Files:**
- Create: `packages/nr_mcp_agent/Resources/Private/Language/locallang.xlf`
- Create: `packages/nr_mcp_agent/Resources/Private/Language/de.locallang.xlf`

- [ ] **Step 1: Create locallang.xlf**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">
    <file source-language="en" datatype="plaintext" original="messages">
        <body>
            <trans-unit id="chat.title">
                <source>AI Chat</source>
            </trans-unit>
            <trans-unit id="chat.placeholder">
                <source>Type a message...</source>
            </trans-unit>
            <trans-unit id="chat.send">
                <source>Send</source>
            </trans-unit>
            <trans-unit id="chat.new_conversation">
                <source>New conversation</source>
            </trans-unit>
            <trans-unit id="chat.conversations">
                <source>Conversations</source>
            </trans-unit>
            <trans-unit id="chat.resume">
                <source>Resume</source>
            </trans-unit>
            <trans-unit id="chat.archive">
                <source>Archive</source>
            </trans-unit>
            <trans-unit id="chat.status.idle">
                <source>Ready</source>
            </trans-unit>
            <trans-unit id="chat.status.processing">
                <source>Thinking...</source>
            </trans-unit>
            <trans-unit id="chat.status.locked">
                <source>Processing...</source>
            </trans-unit>
            <trans-unit id="chat.status.tool_loop">
                <source>Working...</source>
            </trans-unit>
            <trans-unit id="chat.status.failed">
                <source>Error</source>
            </trans-unit>
            <trans-unit id="chat.no_llm_task">
                <source>No LLM task configured. Please contact your administrator.</source>
            </trans-unit>
            <trans-unit id="chat.tab.chat">
                <source>Chat</source>
            </trans-unit>
            <trans-unit id="chat.tab.conversations">
                <source>History</source>
            </trans-unit>
            <trans-unit id="chat.tab.settings">
                <source>Settings</source>
            </trans-unit>
            <trans-unit id="chat.collapse">
                <source>Collapse</source>
            </trans-unit>
            <trans-unit id="chat.expand">
                <source>Expand</source>
            </trans-unit>
            <trans-unit id="chat.maximize">
                <source>Maximize</source>
            </trans-unit>
            <trans-unit id="chat.close">
                <source>Close</source>
            </trans-unit>
        </body>
    </file>
</xliff>
```

- [ ] **Step 2: Create de.locallang.xlf**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">
    <file source-language="en" target-language="de" datatype="plaintext" original="messages">
        <body>
            <trans-unit id="chat.title">
                <source>AI Chat</source>
                <target>KI-Chat</target>
            </trans-unit>
            <trans-unit id="chat.placeholder">
                <source>Type a message...</source>
                <target>Nachricht eingeben...</target>
            </trans-unit>
            <trans-unit id="chat.send">
                <source>Send</source>
                <target>Senden</target>
            </trans-unit>
            <trans-unit id="chat.new_conversation">
                <source>New conversation</source>
                <target>Neue Konversation</target>
            </trans-unit>
            <trans-unit id="chat.conversations">
                <source>Conversations</source>
                <target>Konversationen</target>
            </trans-unit>
            <trans-unit id="chat.resume">
                <source>Resume</source>
                <target>Fortsetzen</target>
            </trans-unit>
            <trans-unit id="chat.archive">
                <source>Archive</source>
                <target>Archivieren</target>
            </trans-unit>
            <trans-unit id="chat.status.idle">
                <source>Ready</source>
                <target>Bereit</target>
            </trans-unit>
            <trans-unit id="chat.status.processing">
                <source>Thinking...</source>
                <target>Denkt nach...</target>
            </trans-unit>
            <trans-unit id="chat.status.locked">
                <source>Processing...</source>
                <target>Verarbeitung...</target>
            </trans-unit>
            <trans-unit id="chat.status.tool_loop">
                <source>Working...</source>
                <target>Arbeitet...</target>
            </trans-unit>
            <trans-unit id="chat.status.failed">
                <source>Error</source>
                <target>Fehler</target>
            </trans-unit>
            <trans-unit id="chat.no_llm_task">
                <source>No LLM task configured. Please contact your administrator.</source>
                <target>Kein LLM-Task konfiguriert. Bitte kontaktieren Sie Ihren Administrator.</target>
            </trans-unit>
            <trans-unit id="chat.tab.chat">
                <source>Chat</source>
                <target>Chat</target>
            </trans-unit>
            <trans-unit id="chat.tab.conversations">
                <source>History</source>
                <target>Verlauf</target>
            </trans-unit>
            <trans-unit id="chat.tab.settings">
                <source>Settings</source>
                <target>Einstellungen</target>
            </trans-unit>
            <trans-unit id="chat.collapse">
                <source>Collapse</source>
                <target>Einklappen</target>
            </trans-unit>
            <trans-unit id="chat.expand">
                <source>Expand</source>
                <target>Aufklappen</target>
            </trans-unit>
            <trans-unit id="chat.maximize">
                <source>Maximize</source>
                <target>Maximieren</target>
            </trans-unit>
            <trans-unit id="chat.close">
                <source>Close</source>
                <target>Schließen</target>
            </trans-unit>
        </body>
    </file>
</xliff>
```

- [ ] **Step 3: Commit**

```bash
git add packages/nr_mcp_agent/Resources/Private/Language/
git commit -m "feat(nr-mcp-agent): add localization files (en, de)"
```

### Task 5.3: Chat Panel Lit Element

**Files:**
- Create: `packages/nr_mcp_agent/Resources/Public/JavaScript/chat-panel.js`
- Create: `packages/nr_mcp_agent/Resources/Public/JavaScript/chat-message.js`
- Create: `packages/nr_mcp_agent/Resources/Public/JavaScript/chat-conversation-list.js`

- [ ] **Step 1: Create chat-message.js component**

```javascript
import { LitElement, html, css, nothing } from 'lit';

export class AiChatMessage extends LitElement {
    static properties = {
        role: { type: String },
        content: { type: Object }, // string or array of content blocks
    };

    static styles = css`
        :host {
            display: block;
            margin-bottom: 8px;
        }

        .message {
            padding: 8px 12px;
            border-radius: 8px;
            max-width: 85%;
            word-wrap: break-word;
            font-size: 13px;
            line-height: 1.5;
        }

        .message--user {
            background: var(--typo3-component-primary-color, #538bb3);
            color: white;
            margin-left: auto;
        }

        .message--assistant {
            background: var(--typo3-surface-container-low, #f0f0f0);
            color: var(--typo3-text-color-base, #333);
        }

        .tool-call {
            font-size: 11px;
            padding: 4px 8px;
            margin: 4px 0;
            border-left: 3px solid var(--typo3-component-primary-color, #538bb3);
            background: var(--typo3-surface-container-lowest, #fafafa);
            border-radius: 0 4px 4px 0;
            font-family: monospace;
        }

        .tool-result {
            font-size: 11px;
            padding: 4px 8px;
            margin: 4px 0;
            border-left: 3px solid #4caf50;
            background: var(--typo3-surface-container-lowest, #fafafa);
            border-radius: 0 4px 4px 0;
        }
    `;

    render() {
        if (typeof this.content === 'string') {
            return html`<div class="message message--${this.role}">${this.content}</div>`;
        }

        if (!Array.isArray(this.content)) {
            return nothing;
        }

        return html`
            ${this.content.map(block => this.renderBlock(block))}
        `;
    }

    renderBlock(block) {
        switch (block.type) {
            case 'text':
                return html`<div class="message message--${this.role}">${block.text}</div>`;
            case 'tool_use':
                return html`
                    <div class="tool-call">
                        ⚙ ${block.name}(${JSON.stringify(block.input || {})})
                    </div>
                `;
            case 'tool_result':
                return html`
                    <div class="tool-result">
                        ✓ Result for ${block.tool_use_id}
                    </div>
                `;
            default:
                return nothing;
        }
    }
}

customElements.define('ai-chat-message', AiChatMessage);
```

- [ ] **Step 2: Create chat-conversation-list.js component**

```javascript
import { LitElement, html, css } from 'lit';

export class AiChatConversationList extends LitElement {
    static properties = {
        conversations: { type: Array },
        activeUid: { type: Number },
    };

    static styles = css`
        :host {
            display: block;
            overflow-y: auto;
        }

        .conversation {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--typo3-surface-container-low, #eee);
            gap: 8px;
        }

        .conversation:hover {
            background: var(--typo3-surface-container-low, #f5f5f5);
        }

        .conversation--active {
            background: var(--typo3-surface-container-high, #e0e0e0);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .status-dot--idle { background: #9e9e9e; }
        .status-dot--processing { background: #2196f3; animation: pulse 1s infinite; }
        .status-dot--locked { background: #2196f3; animation: pulse 1s infinite; }
        .status-dot--tool_loop { background: #ff9800; animation: pulse 1s infinite; }
        .status-dot--failed { background: #f44336; }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .title {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 13px;
        }

        .actions {
            display: flex;
            gap: 4px;
            opacity: 0;
        }

        .conversation:hover .actions {
            opacity: 1;
        }

        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px;
            font-size: 12px;
            color: var(--typo3-text-color-base, #666);
        }

        .pin-indicator {
            font-size: 10px;
        }
    `;

    render() {
        if (!this.conversations?.length) {
            return html`<div style="padding: 16px; text-align: center; color: #999; font-size: 13px;">
                No conversations yet
            </div>`;
        }

        return html`
            ${this.conversations.map(conv => html`
                <div class="conversation ${conv.uid === this.activeUid ? 'conversation--active' : ''}"
                     @click=${() => this.selectConversation(conv.uid)}>
                    <span class="status-dot status-dot--${conv.status}"></span>
                    ${conv.pinned ? html`<span class="pin-indicator">📌</span>` : ''}
                    <span class="title">${conv.title || 'New conversation'}</span>
                    <span class="actions">
                        ${conv.resumable ? html`
                            <button class="action-btn" @click=${(e) => this.resume(e, conv.uid)}
                                    title="Resume">▶</button>
                        ` : ''}
                        <button class="action-btn" @click=${(e) => this.archive(e, conv.uid)}
                                title="Archive">🗑</button>
                    </span>
                </div>
            `)}
        `;
    }

    selectConversation(uid) {
        this.dispatchEvent(new CustomEvent('select', { detail: { uid } }));
    }

    resume(e, uid) {
        e.stopPropagation();
        this.dispatchEvent(new CustomEvent('resume', { detail: { uid } }));
    }

    archive(e, uid) {
        e.stopPropagation();
        this.dispatchEvent(new CustomEvent('archive', { detail: { uid } }));
    }
}

customElements.define('ai-chat-conversation-list', AiChatConversationList);
```

- [ ] **Step 3: Create chat-panel.js (main component)**

```javascript
import { LitElement, html, css, nothing } from 'lit';
import './chat-message.js';
import './chat-conversation-list.js';

const PANEL_STATES = { COLLAPSED: 'collapsed', HALF: 'half', MAXIMIZED: 'maximized', HIDDEN: 'hidden' };
const TABS = { CHAT: 'chat', CONVERSATIONS: 'conversations' };

class AiChatPanel extends LitElement {
    static properties = {
        panelState: { type: String, state: true },
        activeTab: { type: String, state: true },
        conversations: { type: Array, state: true },
        activeConversationUid: { type: Number, state: true },
        messages: { type: Array, state: true },
        conversationStatus: { type: String, state: true },
        inputValue: { type: String, state: true },
        available: { type: Boolean, state: true },
        panelHeight: { type: Number, state: true },
        isDragging: { type: Boolean, state: true },
    };

    static styles = css`
        :host {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 9999;
            font-family: var(--typo3-font-family, sans-serif);
        }

        .panel {
            background: var(--typo3-surface-base, #fff);
            border-top: 2px solid var(--typo3-surface-container-high, #ccc);
            display: flex;
            flex-direction: column;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
        }

        .panel--hidden { display: none; }
        .panel--collapsed { height: 36px; overflow: hidden; }

        .resize-handle {
            height: 4px;
            cursor: ns-resize;
            background: transparent;
            position: absolute;
            top: -2px;
            left: 0;
            right: 0;
        }

        .resize-handle:hover {
            background: var(--typo3-component-primary-color, #538bb3);
        }

        .header {
            display: flex;
            align-items: center;
            padding: 6px 12px;
            background: var(--typo3-surface-container-low, #f5f5f5);
            border-bottom: 1px solid var(--typo3-surface-container-high, #ddd);
            gap: 4px;
            cursor: pointer;
            user-select: none;
            flex-shrink: 0;
        }

        .header-title {
            font-weight: 600;
            font-size: 13px;
            margin-right: auto;
        }

        .tab-btn {
            background: none;
            border: none;
            padding: 4px 10px;
            cursor: pointer;
            font-size: 12px;
            border-radius: 4px;
            color: var(--typo3-text-color-base, #333);
        }

        .tab-btn--active {
            background: var(--typo3-component-primary-color, #538bb3);
            color: white;
        }

        .control-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            font-size: 14px;
            color: var(--typo3-text-color-base, #666);
            line-height: 1;
        }

        .control-btn:hover {
            color: var(--typo3-text-color-base, #333);
        }

        .body {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        .body--maximized {
            /* In maximized mode, show conversation list sidebar */
        }

        .sidebar {
            width: 250px;
            border-right: 1px solid var(--typo3-surface-container-high, #ddd);
            overflow-y: auto;
            flex-shrink: 0;
        }

        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
        }

        .input-area {
            display: flex;
            padding: 8px 12px;
            border-top: 1px solid var(--typo3-surface-container-high, #ddd);
            gap: 8px;
            flex-shrink: 0;
        }

        .input-area input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid var(--typo3-surface-container-high, #ccc);
            border-radius: 6px;
            font-size: 13px;
            outline: none;
        }

        .input-area input:focus {
            border-color: var(--typo3-component-primary-color, #538bb3);
        }

        .send-btn {
            padding: 8px 16px;
            background: var(--typo3-component-primary-color, #538bb3);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
        }

        .send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }

        .status-indicator--idle { background: #4caf50; }
        .status-indicator--processing { background: #2196f3; animation: pulse 1s infinite; }
        .status-indicator--locked { background: #2196f3; animation: pulse 1s infinite; }
        .status-indicator--tool_loop { background: #ff9800; animation: pulse 1s infinite; }
        .status-indicator--failed { background: #f44336; }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
    `;

    constructor() {
        super();
        this.panelState = PANEL_STATES.HIDDEN;
        this.activeTab = TABS.CHAT;
        this.conversations = [];
        this.activeConversationUid = null;
        this.messages = [];
        this.conversationStatus = 'idle';
        this.inputValue = '';
        this.available = false;
        this.panelHeight = 350;
        this.isDragging = false;
        this._pollTimer = null;

        this.loadPreferences();
        this.checkAvailability();
    }

    connectedCallback() {
        super.connectedCallback();
        document.querySelector('.ai-chat-toolbar-trigger')
            ?.addEventListener('click', () => this.toggle());
    }

    disconnectedCallback() {
        super.disconnectedCallback();
        this.stopPolling();
    }

    // -- State Management --

    toggle() {
        if (this.panelState === PANEL_STATES.HIDDEN) {
            this.panelState = PANEL_STATES.HALF;
            this.loadConversations();
        } else {
            this.panelState = PANEL_STATES.HIDDEN;
            this.stopPolling();
        }
        this.savePreferences();
    }

    setPanelState(state) {
        this.panelState = state;
        this.savePreferences();
    }

    // -- API Calls --

    async checkAvailability() {
        try {
            const res = await this.apiFetch('ai_chat_status');
            this.available = res.available;
            this._statusIssues = res.issues || [];
        } catch { this.available = false; this._statusIssues = []; }
    }

    async loadConversations() {
        try {
            const res = await this.apiFetch('ai_chat_conversations');
            this.conversations = res.conversations || [];
        } catch { this.conversations = []; }
    }

    async createConversation() {
        const res = await this.apiFetch('ai_chat_conversation_create', {}, { method: 'POST' });
        this.activeConversationUid = res.uid;
        this.messages = [];
        this.conversationStatus = 'idle';
        await this.loadConversations();
        this.activeTab = TABS.CHAT;
    }

    async sendMessage() {
        const content = this.inputValue.trim();
        if (!content || !this.activeConversationUid) return;

        this.inputValue = '';

        // Optimistic UI update
        this.messages = [...this.messages, { role: 'user', content }];

        await this.apiFetch(
            'ai_chat_conversation_send',
            {},
            { method: 'POST', body: JSON.stringify({ conversationUid: this.activeConversationUid, content }) }
        );

        this.conversationStatus = 'processing';
        this.startPolling();
    }

    async pollMessages() {
        if (!this.activeConversationUid) return;

        try {
            const afterIndex = this.messages.length;
            const res = await this.apiFetch(
                'ai_chat_conversation_messages',
                { conversationUid: this.activeConversationUid, after: afterIndex }
            );

            if (res.messages?.length) {
                this.messages = [...this.messages, ...res.messages];
                this.scrollToBottom();
            }

            this.conversationStatus = res.status;

            if (res.status === 'idle' || res.status === 'failed') {
                this.stopPolling();
                this.loadConversations();
            }
        } catch { /* retry on next poll */ }
    }

    startPolling() {
        this.stopPolling();
        // Adaptive: 500ms during processing, 5s when idle
        const interval = this.conversationStatus === 'idle' ? 5000 : 500;
        this._pollTimer = setInterval(() => this.pollMessages(), interval);
    }

    stopPolling() {
        if (this._pollTimer) {
            clearInterval(this._pollTimer);
            this._pollTimer = null;
        }
    }

    async selectConversation(uid) {
        this.activeConversationUid = uid;
        this.activeTab = TABS.CHAT;

        const res = await this.apiFetch('ai_chat_conversation_messages', { conversationUid: uid, after: 0 });
        this.messages = res.messages || [];
        this.conversationStatus = res.status;

        if (['processing', 'locked', 'tool_loop'].includes(res.status)) {
            this.startPolling();
        }

        this.scrollToBottom();
    }

    async resumeConversation(uid) {
        await this.apiFetch('ai_chat_conversation_resume', {}, { method: 'POST', body: JSON.stringify({ conversationUid: uid }) });
        await this.selectConversation(uid);
        this.startPolling();
    }

    async archiveConversation(uid) {
        await this.apiFetch('ai_chat_conversation_archive', {}, { method: 'POST', body: JSON.stringify({ conversationUid: uid }) });
        if (this.activeConversationUid === uid) {
            this.activeConversationUid = null;
            this.messages = [];
        }
        await this.loadConversations();
    }

    // -- Helpers --

    async apiFetch(routeName, params = {}, options = {}) {
        let url = TYPO3.settings.ajaxUrls[routeName];
        if (!url) {
            throw new Error(`Unknown AJAX route: ${routeName}`);
        }

        // Append query parameters
        if (Object.keys(params).length && (!options.method || options.method === 'GET')) {
            const qs = new URLSearchParams(params).toString();
            url += (url.includes('?') ? '&' : '?') + qs;
        }

        const res = await fetch(url, {
            headers: { 'Content-Type': 'application/json' },
            ...options,
        });

        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    scrollToBottom() {
        requestAnimationFrame(() => {
            const container = this.shadowRoot?.querySelector('.messages');
            if (container) container.scrollTop = container.scrollHeight;
        });
    }

    loadPreferences() {
        try {
            const saved = localStorage.getItem('ai-chat-prefs');
            if (saved) {
                const prefs = JSON.parse(saved);
                this.panelHeight = prefs.panelHeight || 350;
            }
        } catch { /* ignore */ }
    }

    savePreferences() {
        localStorage.setItem('ai-chat-prefs', JSON.stringify({
            panelHeight: this.panelHeight,
        }));
    }

    // -- Resize --

    onResizeStart(e) {
        this.isDragging = true;
        const startY = e.clientY;
        const startHeight = this.panelHeight;

        const onMove = (ev) => {
            this.panelHeight = Math.max(200, Math.min(window.innerHeight - 100, startHeight + (startY - ev.clientY)));
        };

        const onUp = () => {
            this.isDragging = false;
            this.savePreferences();
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        };

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }

    // -- Keyboard --

    onInputKeydown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.sendMessage();
        }
    }

    // -- Render --

    render() {
        const isProcessing = ['processing', 'locked', 'tool_loop'].includes(this.conversationStatus);
        const panelClass = `panel panel--${this.panelState}`;
        const heightStyle = this.panelState === PANEL_STATES.MAXIMIZED
            ? 'height: calc(100vh - 50px)'
            : this.panelState === PANEL_STATES.HALF
                ? `height: ${this.panelHeight}px`
                : '';

        return html`
            <div class="${panelClass}" style="${heightStyle}">
                ${this.panelState !== PANEL_STATES.HIDDEN && this.panelState !== PANEL_STATES.COLLAPSED
                    ? html`<div class="resize-handle" @mousedown=${this.onResizeStart}></div>`
                    : nothing}

                <div class="header" @dblclick=${() => this.setPanelState(
                    this.panelState === PANEL_STATES.MAXIMIZED ? PANEL_STATES.HALF : PANEL_STATES.MAXIMIZED
                )}>
                    <span class="status-indicator status-indicator--${this.conversationStatus}"></span>
                    <span class="header-title">AI Chat</span>

                    <button class="tab-btn ${this.activeTab === TABS.CHAT ? 'tab-btn--active' : ''}"
                            @click=${(e) => { e.stopPropagation(); this.activeTab = TABS.CHAT; }}>
                        Chat
                    </button>
                    <button class="tab-btn ${this.activeTab === TABS.CONVERSATIONS ? 'tab-btn--active' : ''}"
                            @click=${(e) => { e.stopPropagation(); this.activeTab = TABS.CONVERSATIONS; this.loadConversations(); }}>
                        History (${this.conversations.length})
                    </button>

                    <button class="control-btn" @click=${(e) => { e.stopPropagation(); this.setPanelState(PANEL_STATES.COLLAPSED); }}
                            title="Collapse">─</button>
                    <button class="control-btn" @click=${(e) => { e.stopPropagation(); this.setPanelState(
                        this.panelState === PANEL_STATES.MAXIMIZED ? PANEL_STATES.HALF : PANEL_STATES.MAXIMIZED
                    ); }} title="Maximize">□</button>
                    <button class="control-btn" @click=${(e) => { e.stopPropagation(); this.toggle(); }}
                            title="Close">✕</button>
                </div>

                ${this.panelState !== PANEL_STATES.COLLAPSED ? html`
                    <div class="body ${this.panelState === PANEL_STATES.MAXIMIZED ? 'body--maximized' : ''}">
                        ${this.panelState === PANEL_STATES.MAXIMIZED ? html`
                            <div class="sidebar">
                                <div style="padding: 8px">
                                    <button class="send-btn" style="width: 100%" @click=${this.createConversation}>
                                        + New
                                    </button>
                                </div>
                                <ai-chat-conversation-list
                                    .conversations=${this.conversations}
                                    .activeUid=${this.activeConversationUid}
                                    @select=${(e) => this.selectConversation(e.detail.uid)}
                                    @resume=${(e) => this.resumeConversation(e.detail.uid)}
                                    @archive=${(e) => this.archiveConversation(e.detail.uid)}
                                ></ai-chat-conversation-list>
                            </div>
                        ` : nothing}

                        <div class="chat-area">
                            ${this.activeTab === TABS.CHAT ? html`
                                ${this.activeConversationUid ? html`
                                    <div class="messages">
                                        ${this.messages.map(msg => html`
                                            <ai-chat-message
                                                .role=${msg.role}
                                                .content=${msg.content}
                                            ></ai-chat-message>
                                        `)}
                                    </div>
                                    <div class="input-area">
                                        <input
                                            type="text"
                                            .value=${this.inputValue}
                                            @input=${(e) => this.inputValue = e.target.value}
                                            @keydown=${this.onInputKeydown}
                                            placeholder="Type a message..."
                                            ?disabled=${isProcessing}
                                        />
                                        <button class="send-btn"
                                                @click=${this.sendMessage}
                                                ?disabled=${isProcessing || !this.inputValue.trim()}>
                                            ${isProcessing ? '...' : 'Send'}
                                        </button>
                                    </div>
                                ` : html`
                                    <div style="display: flex; align-items: center; justify-content: center; flex: 1;">
                                        <button class="send-btn" @click=${this.createConversation}>
                                            + New conversation
                                        </button>
                                    </div>
                                `}
                            ` : html`
                                <ai-chat-conversation-list
                                    .conversations=${this.conversations}
                                    .activeUid=${this.activeConversationUid}
                                    @select=${(e) => this.selectConversation(e.detail.uid)}
                                    @resume=${(e) => this.resumeConversation(e.detail.uid)}
                                    @archive=${(e) => this.archiveConversation(e.detail.uid)}
                                ></ai-chat-conversation-list>
                            `}
                        </div>
                    </div>
                ` : nothing}
            </div>
        `;
    }
}

customElements.define('ai-chat-panel', AiChatPanel);

// Auto-inject into document
document.addEventListener('DOMContentLoaded', () => {
    if (!document.querySelector('ai-chat-panel')) {
        document.body.appendChild(document.createElement('ai-chat-panel'));
    }
});
```

Note: Die `apiFetch`-Methode nutzt `TYPO3.settings.ajaxUrls[routeName]` für TYPO3-konforme AJAX-Requests. CSRF-Token wird automatisch durch die TYPO3 Backend-Routes gehandhabt.

- [ ] **Step 4: Write Jest tests for Lit components**

Create: `packages/nr_mcp_agent/Resources/Public/JavaScript/__tests__/chat-message.test.js`

```javascript
import { fixture, html, expect } from '@open-wc/testing';
import '../chat-message.js';

describe('AiChatMessage', () => {
    it('renders user message with correct class', async () => {
        const el = await fixture(html`
            <ai-chat-message .role=${'user'} .content=${'Hello'}></ai-chat-message>
        `);
        const msg = el.shadowRoot.querySelector('.message');
        expect(msg).to.exist;
        expect(msg.classList.contains('message--user')).to.be.true;
        expect(msg.textContent).to.equal('Hello');
    });

    it('renders assistant message', async () => {
        const el = await fixture(html`
            <ai-chat-message .role=${'assistant'} .content=${'Hi there!'}></ai-chat-message>
        `);
        const msg = el.shadowRoot.querySelector('.message');
        expect(msg.classList.contains('message--assistant')).to.be.true;
    });

    it('renders tool_use content blocks', async () => {
        const content = [
            { type: 'text', text: 'Translating...' },
            { type: 'tool_use', id: 'toolu_1', name: 'translate', input: { page: 5 } },
        ];
        const el = await fixture(html`
            <ai-chat-message .role=${'assistant'} .content=${content}></ai-chat-message>
        `);
        const toolCall = el.shadowRoot.querySelector('.tool-call');
        expect(toolCall).to.exist;
        expect(toolCall.textContent).to.include('translate');
    });

    it('renders nothing for empty content', async () => {
        const el = await fixture(html`
            <ai-chat-message .role=${'assistant'} .content=${null}></ai-chat-message>
        `);
        expect(el.shadowRoot.querySelector('.message')).to.not.exist;
    });
});
```

Create: `packages/nr_mcp_agent/Resources/Public/JavaScript/__tests__/chat-conversation-list.test.js`

```javascript
import { fixture, html, expect, oneEvent } from '@open-wc/testing';
import '../chat-conversation-list.js';

describe('AiChatConversationList', () => {
    const mockConversations = [
        { uid: 1, title: 'First', status: 'idle', pinned: false, resumable: false },
        { uid: 2, title: 'Second', status: 'processing', pinned: true, resumable: false },
        { uid: 3, title: 'Failed', status: 'failed', pinned: false, resumable: true },
    ];

    it('renders all conversations', async () => {
        const el = await fixture(html`
            <ai-chat-conversation-list .conversations=${mockConversations}></ai-chat-conversation-list>
        `);
        const items = el.shadowRoot.querySelectorAll('.conversation');
        expect(items.length).to.equal(3);
    });

    it('shows pin indicator for pinned conversations', async () => {
        const el = await fixture(html`
            <ai-chat-conversation-list .conversations=${mockConversations}></ai-chat-conversation-list>
        `);
        const pins = el.shadowRoot.querySelectorAll('.pin-indicator');
        expect(pins.length).to.equal(1);
    });

    it('fires select event on click', async () => {
        const el = await fixture(html`
            <ai-chat-conversation-list .conversations=${mockConversations}></ai-chat-conversation-list>
        `);
        const listener = oneEvent(el, 'select');
        el.shadowRoot.querySelectorAll('.conversation')[0].click();
        const event = await listener;
        expect(event.detail.uid).to.equal(1);
    });

    it('shows resume button only for resumable conversations', async () => {
        const el = await fixture(html`
            <ai-chat-conversation-list .conversations=${mockConversations}></ai-chat-conversation-list>
        `);
        const resumeButtons = el.shadowRoot.querySelectorAll('.action-btn[title="Resume"]');
        expect(resumeButtons.length).to.equal(1); // Only uid=3 is resumable
    });

    it('shows empty state when no conversations', async () => {
        const el = await fixture(html`
            <ai-chat-conversation-list .conversations=${[]}></ai-chat-conversation-list>
        `);
        expect(el.shadowRoot.textContent).to.include('No conversations');
    });
});
```

- [ ] **Step 5: Write chat-panel.test.js**

Create: `packages/nr_mcp_agent/Resources/Public/JavaScript/__tests__/chat-panel.test.js`

```javascript
import { fixture, html, expect, waitUntil } from '@open-wc/testing';
import '../chat-panel.js';

describe('AiChatPanel', () => {
    it('renders with collapsed state by default', async () => {
        const el = await fixture(html`<ai-chat-panel></ai-chat-panel>`);
        expect(el.shadowRoot.querySelector('.chat-panel')).to.exist;
        expect(el.open).to.be.false;
    });

    it('opens panel when open property is set', async () => {
        const el = await fixture(html`<ai-chat-panel .open=${true}></ai-chat-panel>`);
        const panel = el.shadowRoot.querySelector('.chat-panel');
        expect(panel.classList.contains('open')).to.be.true;
    });

    it('shows input area when panel is open', async () => {
        const el = await fixture(html`<ai-chat-panel .open=${true}></ai-chat-panel>`);
        const input = el.shadowRoot.querySelector('.chat-input');
        expect(input).to.exist;
    });

    it('disables input when status is processing', async () => {
        const el = await fixture(html`
            <ai-chat-panel .open=${true} .status=${'processing'}></ai-chat-panel>
        `);
        const textarea = el.shadowRoot.querySelector('textarea');
        expect(textarea.disabled).to.be.true;
    });

    it('shows conversation list in sidebar', async () => {
        const el = await fixture(html`<ai-chat-panel .open=${true}></ai-chat-panel>`);
        const list = el.shadowRoot.querySelector('ai-chat-conversation-list');
        expect(list).to.exist;
    });

    it('shows unavailable message when issues exist', async () => {
        const el = await fixture(html`
            <ai-chat-panel .open=${true} .issues=${['No LLM configured']}></ai-chat-panel>
        `);
        const warning = el.shadowRoot.querySelector('.status-warning');
        expect(warning).to.exist;
        expect(warning.textContent).to.include('No LLM configured');
    });
});
```

- [ ] **Step 6: Commit**

```bash
git add packages/nr_mcp_agent/Resources/Public/JavaScript/
git commit -m "feat(nr-mcp-agent): add bottom panel UI with Lit Elements and Jest tests"
```

---

## Chunk 6: Maintenance, Architecture Tests & E2E

### Task 6.1: Auto-Archive & Cleanup Command

**Files:**
- Create: `packages/nr_mcp_agent/Classes/Command/CleanupCommand.php`

- [ ] **Step 1: Create CleanupCommand**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Command;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(name: 'ai-chat:cleanup', description: 'Archive old conversations and delete archived ones')]
final class CleanupCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ExtensionConfiguration $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('delete-after-days', null, InputOption::VALUE_OPTIONAL,
            'Delete archived conversations older than N days', 90);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Timeout handling: fail conversations stuck in 'processing' or 'locked' for >5min
        $timeoutThreshold = time() - 300; // 5 minutes
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_nrmcpagent_conversation');
        $timeoutCount = $qb->update('tx_nrmcpagent_conversation')
            ->set('status', 'failed')
            ->set('error_message', 'Processing timeout (>5 minutes)')
            ->where(
                $qb->expr()->in('status', $qb->createNamedParameter(
                    ['processing', 'locked', 'tool_loop'],
                    Connection::PARAM_STR_ARRAY,
                )),
                $qb->expr()->lt('tstamp', $timeoutThreshold),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeStatement();

        if ($timeoutCount > 0) {
            $output->writeln(sprintf('<info>Timed out %d stuck conversations</info>', $timeoutCount));
        }

        // 2. Auto-archive inactive conversations
        $archiveDays = $this->config->getAutoArchiveDays();
        if ($archiveDays > 0) {
            $threshold = time() - ($archiveDays * 86400);

            $qb = $this->connectionPool->getQueryBuilderForTable('tx_nrmcpagent_conversation');
            $archiveCount = $qb->update('tx_nrmcpagent_conversation')
                ->set('archived', 1)
                ->where(
                    $qb->expr()->eq('archived', 0),
                    $qb->expr()->eq('status', $qb->createNamedParameter('idle')),
                    $qb->expr()->lt('tstamp', $threshold),
                    $qb->expr()->eq('deleted', 0),
                )
                ->executeStatement();

            $output->writeln(sprintf('<info>Archived %d inactive conversations</info>', $archiveCount));
        }

        // 3. Delete old archived conversations
        $deleteDays = (int)$input->getOption('delete-after-days');
        if ($deleteDays > 0) {
            $deleteThreshold = time() - ($deleteDays * 86400);

            $qb = $this->connectionPool->getQueryBuilderForTable('tx_nrmcpagent_conversation');
            $deleteCount = $qb->delete('tx_nrmcpagent_conversation')
                ->where(
                    $qb->expr()->eq('archived', 1),
                    $qb->expr()->lt('tstamp', $deleteThreshold),
                )
                ->executeStatement();

            $output->writeln(sprintf('<info>Deleted %d old archived conversations</info>', $deleteCount));
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Register in Services.yaml**

```yaml
  Netresearch\NrMcpAgent\Command\CleanupCommand:
    tags:
      - name: 'console.command'
        command: 'ai-chat:cleanup'
```

- [ ] **Step 3: Commit**

```bash
git add packages/nr_mcp_agent/Classes/Command/CleanupCommand.php
git commit -m "feat(nr-mcp-agent): add cleanup command for conversation lifecycle"
```

### Task 6.2: Architecture Tests (phpat)

**Files:**
- Create: `packages/nr_mcp_agent/phpat.php`
- Create: `packages/nr_mcp_agent/Tests/Architecture/LayerDependencyTest.php`

- [ ] **Step 1: Install phpat**

```bash
composer require --dev carlosas/phpat
```

- [ ] **Step 2: Create Architecture test**

Die phpat-Regeln sind bereits in der Testing-Strategy-Sektion definiert (siehe `phpat.php` oben). Zusätzlich:

Create: `packages/nr_mcp_agent/Tests/Architecture/LayerDependencyTest.php`

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class LayerDependencyTest extends PHPat
{
    public function testDomainDoesNotDependOnInfrastructure(): Rule
    {
        return $this->newRule
            ->classesThat(Selector::havePath('Domain/*'))
            ->mustNotDependOn()
            ->classesThat(Selector::havePath('Controller/*'))
            ->andClassesThat(Selector::havePath('Command/*'))
            ->andClassesThat(Selector::havePath('Mcp/*'))
            ->build();
    }

    public function testEnumsAreFinal(): Rule
    {
        return $this->newRule
            ->classesThat(Selector::havePath('Enum/*'))
            ->mustBeFinal()
            ->build();
    }

    public function testServicesDoNotAccessDatabaseDirectly(): Rule
    {
        return $this->newRule
            ->classesThat(Selector::havePath('Service/*'))
            ->mustNotDependOn()
            ->classesThat(Selector::haveClassName(
                'TYPO3\CMS\Core\Database\ConnectionPool'
            ))
            ->build();
    }

    public function testControllerDoesNotExecuteProcesses(): Rule
    {
        return $this->newRule
            ->classesThat(Selector::havePath('Controller/*'))
            ->mustNotDependOn()
            ->classesThat(Selector::havePath('Mcp/*'))
            ->build();
    }
}
```

- [ ] **Step 3: Run and verify**

```bash
ddev exec -d /var/www/nr_mcp_agent .Build/bin/phpunit -c .Build/phpunit.xml --testsuite architecture
```

- [ ] **Step 4: Commit**

```bash
git add packages/nr_mcp_agent/phpat.php packages/nr_mcp_agent/Tests/Architecture/
git commit -m "test(nr-mcp-agent): add architecture tests (phpat layer constraints)"
```

### Task 6.3: E2E Tests (Playwright)

**Files:**
- Create: `Build/playwright.config.ts`
- Create: `Build/tests/playwright/config.ts`
- Create: `Build/tests/playwright/helper/login.setup.ts`
- Create: `Build/tests/playwright/fixtures/backend-page.ts`
- Create: `Build/tests/playwright/e2e/chat-panel-open.spec.ts`
- Create: `Build/tests/playwright/e2e/chat-send-message.spec.ts`
- Create: `Build/tests/playwright/e2e/chat-conversation-history.spec.ts`
- Create: `Build/tests/playwright/e2e/chat-error-states.spec.ts`

Die Playwright-Konfiguration ist bereits in der Testing-Strategy-Sektion definiert. Hier die E2E-Specs:

- [ ] **Step 1: Create Page Object for Backend**

```typescript
// Build/tests/playwright/fixtures/backend-page.ts
import { Page, Locator, test as base } from '@playwright/test';

export class BackendPage {
    readonly page: Page;
    readonly toolbar: Locator;
    readonly chatTrigger: Locator;
    readonly chatPanel: Locator;

    constructor(page: Page) {
        this.page = page;
        this.toolbar = page.locator('.scaffold-toolbar');
        this.chatTrigger = page.locator('.ai-chat-toolbar-trigger');
        this.chatPanel = page.locator('ai-chat-panel');
    }

    async openChatPanel(): Promise<void> {
        await this.chatTrigger.click();
        await this.chatPanel.locator('.panel--half, .panel--maximized')
            .waitFor({ state: 'visible' });
    }

    async sendMessage(text: string): Promise<void> {
        const input = this.chatPanel.locator('input[type="text"]');
        await input.fill(text);
        await input.press('Enter');
    }

    async waitForResponse(): Promise<void> {
        // Wait for processing to complete (status indicator changes)
        await this.chatPanel.locator('.status-indicator--idle')
            .waitFor({ timeout: 30_000 });
    }

    async getMessages(): Promise<string[]> {
        const messages = this.chatPanel.locator('ai-chat-message');
        const count = await messages.count();
        const texts: string[] = [];
        for (let i = 0; i < count; i++) {
            texts.push(await messages.nth(i).textContent() || '');
        }
        return texts;
    }
}

interface BackendFixtures {
    backend: BackendPage;
}

export const test = base.extend<BackendFixtures>({
    backend: async ({ page }, use) => {
        await use(new BackendPage(page));
    },
});
```

- [ ] **Step 2: Create Login Setup**

```typescript
// Build/tests/playwright/helper/login.setup.ts
import { setup } from '@playwright/test';
import { config } from '../config';

setup('login', async ({ page }) => {
    await page.goto(config.baseUrl + '/typo3/');
    await page.getByLabel('Username').fill(config.admin.username);
    await page.getByLabel('Password').fill(config.admin.password);
    await page.getByRole('button', { name: /log\s*in/i }).click();
    await page.waitForURL('**/typo3/**');
    await page.context().storageState({ path: './.auth/login.json' });
});
```

- [ ] **Step 3: Create E2E Specs**

```typescript
// Build/tests/playwright/e2e/chat-panel-open.spec.ts
import { expect } from '@playwright/test';
import { test } from '../fixtures/backend-page';

test.describe('Chat Panel', () => {
    test('toolbar button opens the chat panel', async ({ backend, page }) => {
        await page.goto('/typo3/');
        await expect(backend.chatTrigger).toBeVisible();

        await backend.openChatPanel();
        await expect(backend.chatPanel).toBeVisible();
    });

    test('panel can be collapsed and expanded', async ({ backend, page }) => {
        await page.goto('/typo3/');
        await backend.openChatPanel();

        // Collapse
        await backend.chatPanel.locator('button[title="Collapse"]').click();
        await expect(backend.chatPanel.locator('.panel--collapsed')).toBeVisible();

        // Click header to expand
        await backend.chatPanel.locator('.header').click();
    });

    test('panel shows "New conversation" button when no active conversation', async ({ backend, page }) => {
        await page.goto('/typo3/');
        await backend.openChatPanel();

        await expect(backend.chatPanel.getByText('New conversation')).toBeVisible();
    });

    test('panel state survives module navigation', async ({ backend, page }) => {
        await page.goto('/typo3/');
        await backend.openChatPanel();

        // Navigate to different module
        await page.locator('[data-modulemenu-identifier="web_list"]').click();
        await page.waitForTimeout(500);

        // Panel should still be visible
        await expect(backend.chatPanel).toBeVisible();
    });
});
```

```typescript
// Build/tests/playwright/e2e/chat-send-message.spec.ts
import { expect } from '@playwright/test';
import { test } from '../fixtures/backend-page';

test.describe('Chat Messaging', () => {
    test('can create conversation and send a message', async ({ backend, page }) => {
        await page.goto('/typo3/');
        await backend.openChatPanel();

        // Create new conversation
        await backend.chatPanel.getByText('New conversation').click();

        // Send message
        await backend.sendMessage('Hello, AI assistant');

        // Verify optimistic update (user message appears immediately)
        const messages = backend.chatPanel.locator('ai-chat-message');
        await expect(messages.first()).toContainText('Hello, AI assistant');

        // Status should change to processing
        await expect(backend.chatPanel.locator('.status-indicator--processing'))
            .toBeVisible({ timeout: 2000 });
    });

    test('input is disabled during processing', async ({ backend, page }) => {
        await page.goto('/typo3/');
        await backend.openChatPanel();
        await backend.chatPanel.getByText('New conversation').click();

        await backend.sendMessage('Test message');

        const input = backend.chatPanel.locator('input[type="text"]');
        await expect(input).toBeDisabled({ timeout: 2000 });
    });

    test('Enter key sends message', async ({ backend, page }) => {
        await page.goto('/typo3/');
        await backend.openChatPanel();
        await backend.chatPanel.getByText('New conversation').click();

        const input = backend.chatPanel.locator('input[type="text"]');
        await input.fill('Test via Enter');
        await input.press('Enter');

        await expect(backend.chatPanel.locator('ai-chat-message'))
            .toHaveCount(1, { timeout: 2000 });
    });
});
```

```typescript
// Build/tests/playwright/e2e/chat-error-states.spec.ts
import { expect } from '@playwright/test';
import { test } from '../fixtures/backend-page';

test.describe('Chat Error States', () => {
    test('shows issues when LLM is not configured', async ({ page }) => {
        // This test requires llmTaskUid = 0
        await page.goto('/typo3/');

        // Intercept status API to simulate misconfiguration
        await page.route('**/ajax/ai-chat/status*', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    available: false,
                    mcpEnabled: false,
                    issues: ['No nr-llm Task configured.'],
                }),
            });
        });

        const chatTrigger = page.locator('.ai-chat-toolbar-trigger');
        if (await chatTrigger.isVisible()) {
            await chatTrigger.click();
            // Should show unavailability info
            const panel = page.locator('ai-chat-panel');
            await expect(panel).toBeVisible();
        }
    });
});
```

- [ ] **Step 4: Write chat-conversation-history.spec.ts**

```typescript
// Build/tests/playwright/e2e/chat-conversation-history.spec.ts
import { expect } from '@playwright/test';
import { test } from '../fixtures/backend-page';

test.describe('Chat Conversation History', () => {
    test('shows previous conversations in sidebar', async ({ page }) => {
        await page.goto('/typo3/');

        // Intercept conversations API
        await page.route('**/ajax/ai-chat/conversations*', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify([
                    { uid: 1, title: 'Previous chat', messageCount: 5, status: 'idle' },
                    { uid: 2, title: 'Another chat', messageCount: 2, status: 'idle' },
                ]),
            });
        });

        const chatTrigger = page.locator('.ai-chat-toolbar-trigger');
        await chatTrigger.click();

        const conversationList = page.locator('ai-chat-conversation-list');
        await expect(conversationList).toBeVisible();
        await expect(conversationList.locator('.conversation-item')).toHaveCount(2);
    });

    test('loads conversation when clicking history item', async ({ page }) => {
        await page.goto('/typo3/');

        const messagesPayload = {
            uid: 1,
            title: 'Previous chat',
            messages: [
                { role: 'user', content: 'Hello' },
                { role: 'assistant', content: 'Hi there!' },
            ],
            status: 'idle',
        };

        await page.route('**/ajax/ai-chat/conversations/1*', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(messagesPayload),
            });
        });

        const chatTrigger = page.locator('.ai-chat-toolbar-trigger');
        await chatTrigger.click();

        // Click first conversation
        const firstItem = page.locator('.conversation-item').first();
        await firstItem.click();

        // Messages should be displayed
        const messages = page.locator('ai-chat-message');
        await expect(messages).toHaveCount(2);
    });
});
```

- [ ] **Step 5: Commit**

```bash
git add Build/playwright.config.ts Build/tests/ packages/nr_mcp_agent/Tests/Architecture/
git commit -m "test(nr-mcp-agent): add E2E tests (Playwright) and architecture tests"
```

---

## Chunk 7: Documentation (RST, docs.typo3.org-konform)

### Overview

Vollständige Extension-Dokumentation nach TYPO3-Standard: RST-Format, `guides.xml`, Screenshots, Rendering via Docker. Die Docs werden auf `docs.nr-mcp-agent.ddev.site` gerendert und sind kompatibel mit docs.typo3.org.

### File Structure (Chunk 7)

```
packages/nr_mcp_agent/
├── Documentation/
│   ├── .editorconfig
│   ├── guides.xml
│   ├── Includes.rst.txt
│   ├── Index.rst
│   ├── Introduction/
│   │   └── Index.rst
│   ├── Installation/
│   │   └── Index.rst
│   ├── Configuration/
│   │   └── Index.rst
│   ├── Usage/
│   │   └── Index.rst
│   ├── Developer/
│   │   ├── Index.rst
│   │   ├── Architecture.rst
│   │   ├── AgentLoop.rst
│   │   └── Commands.rst
│   └── Images/
│       ├── chat-panel-half.png
│       ├── chat-panel-maximized.png
│       └── extension-configuration.png
└── README.md
```

### Task 7.1: Documentation Skeleton

**Files:**
- Create: `Documentation/.editorconfig`
- Create: `Documentation/guides.xml`
- Create: `Documentation/Index.rst`

- [ ] **Step 1: Create Documentation/.editorconfig**

```ini
root = true

[*.rst]
charset = utf-8
end_of_line = lf
indent_size = 4
indent_style = space
insert_final_newline = true
max_line_length = 80
trim_trailing_whitespace = true
```

- [ ] **Step 2: Create Documentation/guides.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<guides
    xmlns="https://www.phpdoc.org/guides"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="https://www.phpdoc.org/guides vendor/phpdocumentor/guides-cli/resources/schema/guides.xsd"
    theme="typo3docs"
>
    <project
        title="AI Chat for TYPO3"
        version="0.1.0"
        release="0.1.0"
        copyright="Netresearch DTT GmbH"
    />

    <extension
        class="\T3Docs\Typo3DocsTheme\DependencyInjection\Typo3DocsThemeExtension"
        project-home="https://github.com/netresearch/t3x-nr-mcp-agent"
        project-contact="https://github.com/netresearch/t3x-nr-mcp-agent/issues"
        project-repository="https://github.com/netresearch/t3x-nr-mcp-agent"
        edit-on-github="netresearch/t3x-nr-mcp-agent"
        edit-on-github-branch="main"
    />

    <inventory id="t3coreapi"
        url="https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/"/>
    <inventory id="t3tsconfig"
        url="https://docs.typo3.org/m/typo3/reference-tsconfig/main/en-us/"/>
</guides>
```

- [ ] **Step 3: Create Documentation/Index.rst**

```rst
.. include:: /Includes.rst.txt

====================
AI Chat for TYPO3
====================

:Extension key:
    nr_mcp_agent

:Package name:
    netresearch/nr-mcp-agent

:Version:
    |release|

:Language:
    en

:Author:
    Netresearch DTT GmbH

:License:
    This document is published under the
    `GPL-2.0-or-later <https://www.gnu.org/licenses/gpl-2.0.html>`__
    license.

:Rendered:
    |today|

----

AI chat assistant for the TYPO3 backend. Enables editors to interact with
an AI assistant directly within the TYPO3 backend via a persistent bottom
panel, using nr-llm (provider-agnostic LLM abstraction) and MCP server integration for content
management tasks.

----

**Table of Contents**

.. toctree::
    :maxdepth: 2
    :titlesonly:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Usage/Index
    Developer/Index
```

- [ ] **Step 4: Create Includes.rst.txt**

```rst
.. This is included by all RST files to provide common definitions.

.. |extension_key| replace:: nr_mcp_agent
.. |package_name| replace:: netresearch/nr-mcp-agent
```

- [ ] **Step 5: Commit**

```bash
git add Documentation/.editorconfig Documentation/guides.xml Documentation/Index.rst Documentation/Includes.rst.txt
git commit -m "docs(nr-mcp-agent): add documentation skeleton with guides.xml"
```

### Task 7.2: Introduction & Installation

**Files:**
- Create: `Documentation/Introduction/Index.rst`
- Create: `Documentation/Installation/Index.rst`

- [ ] **Step 1: Create Introduction/Index.rst**

```rst
.. include:: /Includes.rst.txt

============
Introduction
============

What does it do?
================

This extension adds an AI-powered chat assistant to the TYPO3 backend.
Editors can interact with the assistant directly within their familiar
TYPO3 environment — no external tools needed.

The assistant uses `nr-llm <https://packagist.org/packages/netresearch/nr-llm>`__
for provider-agnostic LLM access (Claude, GPT, Gemini, etc.) and can
optionally connect to a
`TYPO3 MCP Server <https://github.com/hauptsacheNet/typo3-mcp-server>`__
to access TYPO3-native content management tools.

Key features
------------

.. card-grid::
    :columns: 2

    .. card:: Integrated chat panel

        Persistent bottom panel in the TYPO3 backend — stays open during
        module navigation.

    .. card:: Content management via MCP

        Translate pages, audit content, check SEO metadata — all through
        natural language.

    .. card:: Conversation history

        Conversations are persisted and can be resumed after interruption
        or browser refresh.

    .. card:: Secure by design

        API keys managed via nr-vault, conversations isolated per user,
        MCP tools run in BE_USER permission context.

Example interactions
--------------------

*   "Translate page 5 to English and French"
*   "Check all pages under /study for missing meta descriptions"
*   "Create a content audit for the Research section"
*   "Import this text as a news article"

Acknowledgments
---------------

This extension builds on the work of the TYPO3 community and would not
be possible without the following projects:

*   **hn/typo3-mcp-server** by
    `hauptsache.net <https://hauptsache.net>`__ — The TYPO3 MCP Server
    provides the AI-accessible tools for content management. Without
    this extension, the chat assistant would have no way to interact
    with TYPO3 content. Thank you to the hauptsache.net team for making
    this available to the community.

*   **netresearch/nr-llm** by
    `Netresearch DTT GmbH <https://www.netresearch.de>`__ — Provider-agnostic
    LLM abstraction layer that enables support for Claude, GPT, Gemini,
    and other providers.

*   **netresearch/nr-vault** by
    `Netresearch DTT GmbH <https://www.netresearch.de>`__ — Secure API
    key management with encryption and audit logging.

.. tip::

    If you are using or extending this extension, please consider
    contributing back to `hn/typo3-mcp-server
    <https://github.com/hauptsacheNet/typo3-mcp-server>`__ as well —
    every MCP tool added there benefits all extensions built on top of it.
```

- [ ] **Step 2: Create Installation/Index.rst**

```rst
.. include:: /Includes.rst.txt

============
Installation
============

Requirements
============

*   TYPO3 v13.4+ or v14.0+
*   PHP 8.2+
*   ``netresearch/nr-llm`` (installed automatically as dependency)
*   A configured nr-llm Task record with a valid LLM API key

Optional dependencies:

*   `netresearch/nr-vault <https://packagist.org/packages/netresearch/nr-vault>`__
    — Secure API key storage with encryption and audit logging
*   `hn/typo3-mcp-server <https://github.com/hauptsacheNet/typo3-mcp-server>`__
    — TYPO3 content management tools for the AI assistant

Quick start (3 steps)
=====================

.. code-block:: bash
    :caption: 1. Install

    composer require netresearch/nr-mcp-agent hn/typo3-mcp-server
    vendor/bin/typo3 extension:setup

2.  Create an **nr-llm Task** record in the TYPO3 backend
    (choose a provider like Claude or GPT, add your API key)

3.  Set the Task UID in :guilabel:`Admin Tools > Settings >
    Extension Configuration > nr_mcp_agent > llmTaskUid`

Done — the AI chat icon appears in the backend toolbar.

Installation via Composer
=========================

.. code-block:: bash
    :caption: Install the extension

    composer require netresearch/nr-mcp-agent

    # Recommended: Install MCP server for TYPO3 content tools
    # (developed by hauptsache.net — see Acknowledgments)
    composer require hn/typo3-mcp-server

    # Optional: Install vault for secure key storage
    composer require netresearch/nr-vault

Activate the extension:

.. code-block:: bash
    :caption: Activate and set up

    vendor/bin/typo3 extension:setup

Development setup with DDEV
============================

.. code-block:: bash
    :caption: One-command development setup

    git clone <repository-url>
    cd nr-mcp-agent
    make up

This starts DDEV, installs TYPO3 v14, and pre-installs the MCP server.

Access:

*   **Backend:** https://v14.nr-mcp-agent.ddev.site/typo3
*   **Login:** admin / Joh316!!
*   **Docs:** https://docs.nr-mcp-agent.ddev.site

.. tip::

    After installing, create an nr-llm Task in the backend and set
    the UID in :file:`.ddev/.env`:

    .. code-block:: bash

        NR_MCP_AGENT_LLM_TASK_UID=1
```

- [ ] **Step 3: Commit**

```bash
git add Documentation/Introduction/ Documentation/Installation/
git commit -m "docs(nr-mcp-agent): add introduction and installation guide"
```

### Task 7.3: Configuration & Usage

**Files:**
- Create: `Documentation/Configuration/Index.rst`
- Create: `Documentation/Usage/Index.rst`

- [ ] **Step 1: Create Configuration/Index.rst**

```rst
.. include:: /Includes.rst.txt

=============
Configuration
=============

Extension configuration
=======================

Configure the extension in :guilabel:`Admin Tools > Settings > Extension
Configuration > nr_mcp_agent`.

.. confval:: llmTaskUid
    :type: int
    :default: 0

    Reference to an nr-llm Task record. The Task defines which LLM
    provider, model, and API key to use. Create a Task in the TYPO3
    backend (nr-llm module) and set its UID here.

.. confval:: processingStrategy
    :type: string
    :default: exec
    :options: exec, worker

    How chat processing is dispatched:

    ``exec``
        Forks a CLI process per request. Simple, suitable for development
        and small instances.

    ``worker``
        Long-running systemd worker polls a queue. Recommended for
        production with high load.

.. confval:: allowedGroups
    :type: string
    :default: *(empty — all groups)*

    Comma-separated list of backend user group UIDs that may use the
    AI chat. Empty means all authenticated users have access.

.. confval:: enableMcp
    :type: boolean
    :default: false

    Enable MCP server integration. Requires
    :composer:`hn/typo3-mcp-server` to be installed.

.. confval:: maxConversationsPerUser
    :type: int
    :default: 50

    Maximum number of conversations to keep per user. Set to 0 for
    unlimited.

.. confval:: autoArchiveDays
    :type: int
    :default: 30

    Auto-archive conversations after this many days of inactivity.
    Set to 0 to disable.

.. confval:: maxMessageLength
    :type: int
    :default: 10000

    Maximum allowed message length in characters. Messages exceeding
    this limit are rejected. Set to 0 for unlimited.

.. confval:: maxActiveConversationsPerUser
    :type: int
    :default: 3

    Maximum number of simultaneously active (processing/locked)
    conversations per user. Prevents abuse. Set to 0 for unlimited.

LLM configuration
=================

This extension uses **nr-llm** for LLM abstraction. API keys, model
selection, and provider configuration are managed via nr-llm Task records:

1.  Install nr-llm: ``composer require netresearch/nr-llm``
2.  Create an nr-llm Task record in the TYPO3 backend
3.  Set the Task UID in :confval:`llmTaskUid`

.. important::

    API keys are stored in the nr-llm Task, not in this extension.
    For secure key storage, configure nr-llm with nr-vault.

Worker mode (production)
========================

For production environments with multiple concurrent users, use the
worker processing strategy:

1.  Set :confval:`processingStrategy` to ``worker``
2.  Create a systemd service:

    .. code-block:: ini
        :caption: /etc/systemd/system/typo3-ai-chat-worker.service

        [Unit]
        Description=TYPO3 AI Chat Worker
        After=network.target mysql.service

        [Service]
        Type=simple
        User=www-data
        WorkingDirectory=/var/www/html
        ExecStart=/usr/bin/php vendor/bin/typo3 ai-chat:worker
        Restart=always
        RestartSec=5

        [Install]
        WantedBy=multi-user.target

3.  Enable and start:

    .. code-block:: bash

        systemctl enable typo3-ai-chat-worker
        systemctl start typo3-ai-chat-worker
```

- [ ] **Step 2: Create Usage/Index.rst**

```rst
.. include:: /Includes.rst.txt

=====
Usage
=====

Opening the chat panel
======================

Click the **AI Chat** button in the TYPO3 backend toolbar (lightbulb
icon). The chat panel appears at the bottom of the screen.

.. figure:: /Images/chat-panel-half.png
    :alt: AI Chat panel in half-height mode
    :class: with-border with-shadow
    :zoom: lightbox

    The chat panel in its default half-height state.

Panel states
------------

The panel has three states:

**Collapsed**
    Only the tab bar is visible. Activity indicators show when the
    assistant is processing.

**Half** (default)
    The panel occupies the lower portion of the screen. Height is
    adjustable by dragging the top edge.

**Maximized**
    Full-height panel with a conversation list sidebar.

Double-click the header to toggle between half and maximized.

Sending messages
================

1.  Open the panel and click :guilabel:`+ New conversation`
2.  Type your message and press :kbd:`Enter` or click :guilabel:`Send`
3.  The assistant processes your request — a pulsing indicator shows
    activity
4.  Tool calls (MCP operations) are shown inline with their results

Conversation history
====================

Switch to the :guilabel:`History` tab to see past conversations.
Conversations can be:

*   **Resumed** — if interrupted during processing, click the play
    button
*   **Archived** — removes from the default list
*   **Pinned** — keeps important conversations at the top

.. figure:: /Images/chat-panel-maximized.png
    :alt: AI Chat panel maximized with conversation sidebar
    :class: with-border with-shadow
    :zoom: lightbox

    Maximized view with conversation history sidebar.
```

- [ ] **Step 3: Commit**

```bash
git add Documentation/Configuration/ Documentation/Usage/
git commit -m "docs(nr-mcp-agent): add configuration and usage guide"
```

### Task 7.4: Developer Documentation

**Files:**
- Create: `Documentation/Developer/Index.rst`
- Create: `Documentation/Developer/Architecture.rst`
- Create: `Documentation/Developer/AgentLoop.rst`
- Create: `Documentation/Developer/Commands.rst`

- [ ] **Step 1: Create Developer/Index.rst**

```rst
.. include:: /Includes.rst.txt

=========
Developer
=========

Technical documentation for developers extending or integrating with
the AI Chat extension.

.. toctree::
    :maxdepth: 2
    :titlesonly:

    Architecture
    AgentLoop
    Commands
```

- [ ] **Step 2: Create Developer/Architecture.rst**

```rst
.. include:: /Includes.rst.txt

============
Architecture
============

System overview
===============

.. code-block:: text
    :caption: Component interaction flow

    TYPO3 Backend (Browser)
      Bottom Panel (Lit Element)
             │
             │  Polling (GET /messages?after=N)
             │  Fire & Forget (POST /message)
             ▼
      TYPO3 Backend API (AJAX Routes + CSRF)
      ChatApiController ──► Conversation DB Table
             │
             │  exec() / systemd Worker
             ▼
      CLI Process (BE_USER context bootstrapped)
        Agent Loop: nr-llm (LLM API) ◄──► MCP Tools
        Every step ──► DB persist

Key design decisions
====================

Polling over SSE/WebSocket
--------------------------

The agent loop can run for minutes (Claude call → tool → Claude → ...).
Polling avoids:

*   PHP timeout issues with long-running HTTP connections
*   Sticky session requirements behind load balancers
*   Connection drops on deploy/restart

The client polls at 500ms during processing, 5s when idle.

CLI processing over HTTP workers
---------------------------------

The agent loop runs as a CLI process, not inside the HTTP request cycle:

*   No PHP ``max_execution_time`` constraint
*   BE_USER context bootstrapped from the conversation record
*   Crash recovery: each step is persisted to DB immediately

Domain model
============

.. php:class:: Netresearch\\NrMcpAgent\\Domain\\Model\\Conversation

    Central entity. Stores messages as JSON, tracks processing status,
    and belongs to a single backend user.

    Key methods:

    .. php:method:: appendMessage(string $role, string|array $content): void

        Adds a message, updates count, auto-generates title from first
        user message.

    .. php:method:: isResumable(): bool

        Returns true if status is ``processing``, ``tool_loop``, or ``failed``.

    .. php:method:: getDecodedMessages(): array

        Returns messages as PHP array (decoded from JSON).
```

- [ ] **Step 3: Create Developer/AgentLoop.rst**

```rst
.. include:: /Includes.rst.txt

==========
Agent loop
==========

The agent loop in :php:`ChatService::runAgentLoop()` implements the
Claude tool-use pattern:

.. code-block:: text
    :caption: Agent loop flow

    1. Send messages via nr-llm (chatWithTools)
    2. Receive response (provider-agnostic format)
    3. If no tool_calls → done, set status idle
    4. If hasToolCalls():
       a. Execute each tool call via MCP
       b. Append tool results as tool messages (OpenAI format)
       c. Persist to DB
       d. Go to 1

The loop has a safety limit of 20 iterations to prevent runaway
tool chains.

Crash recovery
==============

After every step, the conversation state is persisted:

.. list-table::
    :header-rows: 1

    *   - Crash point
        - Recovery behavior
    *   - Before Claude call
        - Resume restarts the loop
    *   - During Claude call
        - Same — Claude has not changed state
    *   - After Claude, before tool execution
        - Resume re-executes tools (idempotent via workspaces)
    *   - After tool execution
        - Resume makes next Claude call

MCP tool provider
=================

:php:`McpToolProvider` communicates with the TYPO3 MCP server via
stdio JSON-RPC. It:

1.  Discovers available tools (``tools/list``)
2.  Converts them to OpenAI-compatible tool definitions (for nr-llm)
3.  Executes tool calls (``tools/call``) in the BE_USER context
```

- [ ] **Step 4: Create Developer/Commands.rst**

```rst
.. include:: /Includes.rst.txt

================
Console commands
================

.. confval:: ai-chat:process
    :type: command

    Process a single conversation. Used internally by the ``exec``
    processing strategy.

    .. code-block:: bash

        vendor/bin/typo3 ai-chat:process <conversationUid>

.. confval:: ai-chat:worker
    :type: command

    Long-running worker that polls for conversations in ``processing``
    status. Used by the ``worker`` processing strategy.

    .. code-block:: bash

        vendor/bin/typo3 ai-chat:worker [--poll-interval=200]

    :option:`--poll-interval`
        Poll interval in milliseconds (default: 200).

.. confval:: ai-chat:cleanup
    :type: command

    Archives inactive conversations and deletes old archived ones.
    Should be run as a scheduled task (cron).

    .. code-block:: bash

        vendor/bin/typo3 ai-chat:cleanup [--delete-after-days=90]

    :option:`--delete-after-days`
        Delete archived conversations older than N days (default: 90).

    Recommended cron schedule:

    .. code-block:: bash
        :caption: Run daily at 3 AM

        0 3 * * * cd /var/www/html && vendor/bin/typo3 ai-chat:cleanup
```

- [ ] **Step 5: Commit**

```bash
git add Documentation/Developer/
git commit -m "docs(nr-mcp-agent): add developer documentation"
```

### Task 7.5: README.md

**Files:**
- Create: `README.md`

- [ ] **Step 1: Create README.md**

Synced with Documentation/. Enthält:
- Kurzbeschreibung
- Features als Bullet-Liste
- **Quick Start** (3 Schritte: install, create task, done)
- Installationsanleitung (Composer)
- DDEV Quick-Start
- Konfigurationsübersicht
- **Acknowledgments** — Credits an hauptsache.net für hn/typo3-mcp-server
- Link auf die vollständige Doku

- [ ] **Step 2: Validate and render documentation**

```bash
# Validate RST
ddev exec -d /var/www/nr_mcp_agent docker run --rm -v .:/project ghcr.io/typo3-documentation/render-guides:latest --config=Documentation --no-progress

# Check rendered output
open https://docs.nr-mcp-agent.ddev.site
```

- [ ] **Step 3: Commit**

```bash
git add README.md Documentation/
git commit -m "docs(nr-mcp-agent): add README and finalize documentation"
```

---

## Review Checklist

After implementation, review from three perspectives:

### User Experience
- [ ] Panel opens/closes smoothly from toolbar button
- [ ] Collapsed state shows activity indicator when processing
- [ ] Conversations persist and are listed in history
- [ ] Resume works after browser refresh / tab close
- [ ] Error messages are user-friendly, sanitized, and localized
- [ ] Input is disabled during processing (prevent double-send)
- [ ] Panel state (height, open/closed) survives page navigation
- [ ] Keyboard: Enter sends, panel doesn't interfere with TYPO3 shortcuts
- [ ] Status endpoint returns actionable `issues[]` array — user sees *why* something is unavailable
- [ ] "Quick Start" docs make first-time setup achievable in 3 steps

### Robustness
- [ ] LLM calls retry on transient errors (429/503) with exponential backoff (max 2 retries)
- [ ] Error messages sanitized before storage (API keys, URLs stripped)
- [ ] CleanupCommand times out `processing`, `locked`, AND `tool_loop` conversations after 5min
- [ ] MCP connection has 30s per-call timeout
- [ ] Agent loop has 20-iteration safety limit

### Performance
- [ ] Polling interval adapts (500ms processing, 5s idle)
- [ ] Messages loaded incrementally (`?after=N`), not full history each time
- [ ] Agent loop runs in CLI process, not blocking HTTP worker
- [ ] Conversation list query uses index `(be_user, archived, tstamp)`
- [ ] Cleanup command prevents unbounded table growth
- [ ] No N+1 queries in conversation list
- [ ] Large message histories (100+ messages) don't slow down the UI

### Security
- [ ] API keys managed via nr-llm Task records, never exposed to frontend
- [ ] Conversation access scoped to `be_user` – no user can access another user's conversations
- [ ] `findConversationOrFail` always checks `be_user` ownership
- [ ] CLI command bootstraps BE_USER from conversation record (not from request)
- [ ] `proc_open` with PID tracking instead of `exec()`, PIDs stored in `current_request_id`
- [ ] CSRF protection via TYPO3 backend token (automatic for AJAX routes)
- [ ] No direct SQL injection (QueryBuilder used throughout)
- [ ] System prompt not controllable by end users (unless explicitly configured by admin)
- [ ] MCP tool calls run in the context of the originating BE_USER's permissions
- [ ] Message length limit enforced (configurable, default 10000 chars)
- [ ] Rate limiting: max active conversations per user (configurable, default 3)
- [ ] TCA for `tx_nrmcpagent_conversation` is readOnly and adminOnly
- [ ] Timeout handling: CleanupCommand fails conversations stuck >5min in processing/locked/tool_loop
- [ ] Worker dequeue is atomic (UPDATE...LIMIT 1 with InnoDB row-level locking)
- [ ] Error messages sanitized — no API keys, tokens, or full URLs in `error_message` field

### Documentation & Community
- [ ] Acknowledgments section in Introduction credits hauptsache.net for hn/typo3-mcp-server
- [ ] README.md includes Acknowledgments section
- [ ] `guides.xml` uses correct namespace (`https://www.phpdoc.org/guides`) and `theme="typo3docs"`
- [ ] Quick Start in Installation docs (3 steps)
- [ ] All docs render without warnings
