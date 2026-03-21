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
