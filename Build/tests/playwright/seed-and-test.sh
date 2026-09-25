#!/usr/bin/env bash
#
# CI-only E2E hook. The e2e reusable workflow (netresearch/typo3-ci-workflows)
# runs this as its `test-command` AFTER TYPO3 setup and the PHP server start,
# once per `setup-variants` entry, with the variant in $E2E_VARIANT.
#
# The specs need two states of the same installation that cannot coexist:
#
#   unconfigured  `llmTaskUid` is 0, as after a fresh install. The AI Chat
#                 module must say the chat is not available and disable
#                 "New chat" (ai-chat-module.spec.ts states it is written for
#                 this state), and the user has no conversations yet.
#   configured    An nr-llm Task exists and `llmTaskUid` points at it. Only
#                 then is the toolbar item rendered, which loads the chat panel
#                 every other spec drives (ChatToolbarItem::checkAccess()).
#
# DB coordinates are the fixed defaults the reusable workflow's default mode
# provisions (see its "Setup TYPO3" step): host 127.0.0.1, root/root, db typo3.
# It also writes config/system/additional.php before this script runs, so the
# setting is appended to that file.
set -euo pipefail

export TYPO3_ADMIN_USER="${TYPO3_ADMIN_USER:-admin}"
# The password the reusable workflow's `typo3 setup` creates the admin with.
export TYPO3_ADMIN_PASSWORD="${TYPO3_ADMIN_PASSWORD:-Joh316!!}"
export PLAYWRIGHT_HTML_OUTPUT_DIR="${PWD}/playwright-report"

PLAYWRIGHT_DIR=Build/tests/playwright
CONFIG="${PLAYWRIGHT_DIR}/playwright.config.ts"
UNCONFIGURED_SPECS=("${PLAYWRIGHT_DIR}/specs/ai-chat-module.spec.ts")

run_playwright() {
  npx playwright test --config="${CONFIG}" --reporter=list,html "$@"
}

case "${E2E_VARIANT:-}" in
  unconfigured)
    echo "Running the specs written for an installation without an nr-llm Task..."
    run_playwright "${UNCONFIGURED_SPECS[@]}"
    ;;

  configured)
    if command -v mysql >/dev/null 2>&1; then
      DB_CLIENT="mysql"
    elif command -v mariadb >/dev/null 2>&1; then
      DB_CLIENT="mariadb"
    else
      echo "::error::No mysql/mariadb client on the runner; cannot seed the E2E database." >&2
      exit 1
    fi

    echo "Seeding an nr-llm Provider, Model, Configuration and Task (${DB_CLIENT})..."
    "${DB_CLIENT}" -h 127.0.0.1 -u root -proot typo3 < "${PLAYWRIGHT_DIR}/fixtures/nr-llm-task-seed.sql"

    echo "Pointing llmTaskUid at the seeded Task..."
    cat >> config/system/additional.php << 'PHPEOF'

$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['llmTaskUid'] = 1;
PHPEOF
    .Build/bin/typo3 cache:flush

    # Every spec except the ones written for the unconfigured state, so a new
    # spec runs here without being listed.
    specs=()
    for spec in "${PLAYWRIGHT_DIR}"/specs/*.spec.ts; do
      skip=false
      for excluded in "${UNCONFIGURED_SPECS[@]}"; do
        if [[ "${spec}" == "${excluded}" ]]; then
          skip=true
        fi
      done
      if [[ "${skip}" == false ]]; then
        specs+=("${spec}")
      fi
    done

    echo "Running ${#specs[@]} spec files against the configured installation..."
    run_playwright "${specs[@]}"
    ;;

  *)
    echo "::error::Unknown E2E_VARIANT '${E2E_VARIANT:-}'; expected 'unconfigured' or 'configured'." >&2
    exit 1
    ;;
esac
