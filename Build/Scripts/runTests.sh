#!/usr/bin/env bash

#
# TYPO3 Extension Test Runner - nr_mcp_agent
# Docker/podman-based test orchestration following TYPO3 core conventions.
#
# Reference: https://github.com/TYPO3BestPractices/tea
# Template: https://github.com/netresearch/nr-llm
#

trap 'cleanUp;exit 2' SIGINT

cleanUp() {
    if [ -n "${NETWORK:-}" ] && [ -n "${CONTAINER_BIN:-}" ]; then
        ATTACHED_CONTAINERS=$(${CONTAINER_BIN} ps --filter network=${NETWORK} --format='{{.Names}}' 2>/dev/null)
        for ATTACHED_CONTAINER in ${ATTACHED_CONTAINERS}; do
            ${CONTAINER_BIN} rm -f ${ATTACHED_CONTAINER} >/dev/null 2>&1
        done
        ${CONTAINER_BIN} network rm ${NETWORK} >/dev/null 2>&1
    fi
}

cleanCacheFiles() {
    echo -n "Clean caches ... "
    rm -rf \
        .Build/.cache \
        .Build/cache \
        .php-cs-fixer.cache
    echo "done"
}

loadHelp() {
    read -r -d '' HELP <<EOF
nr_mcp_agent - TYPO3 Extension Test Runner
Execute tests in Docker containers using TYPO3 core-testing images.

Usage: $0 [options] [file]

Options:
    -s <...>
        Specifies which test suite to run
            - architecture: Architecture tests (PHPat via PHPStan)
            - cgl: PHP CS Fixer check/fix
            - clean: Clean temporary files
            - composer: Run composer commands
            - composerUpdate: Clean install dependencies (removes vendor/)
            - lint: PHP linting
            - mutation: Mutation testing with Infection
            - phpstan: PHPStan static analysis
            - rector: Rector code upgrades
            - unit: PHP unit tests (default)
            - unitCoverage: Unit tests with coverage

    -p <8.2|8.3|8.4>
        PHP version (default: 8.2)

    -x
        Enable Xdebug for debugging

    -n
        Dry-run mode (for cgl, rector)

    -h
        Show this help

Examples:
    # Run unit tests
    ./Build/Scripts/runTests.sh -s unit

    # Run unit tests with PHP 8.4
    ./Build/Scripts/runTests.sh -s unit -p 8.4

    # Run PHPStan analysis
    ./Build/Scripts/runTests.sh -s phpstan

    # Run mutation tests
    ./Build/Scripts/runTests.sh -s mutation
EOF
}

# Check container runtime
if ! type "docker" >/dev/null 2>&1 && ! type "podman" >/dev/null 2>&1; then
    echo "This script requires docker or podman." >&2
    exit 1
fi

# Option defaults
TEST_SUITE="unit"
PHP_VERSION="8.2"
PHP_XDEBUG_ON=0
PHP_XDEBUG_PORT=9003
CGLCHECK_DRY_RUN=0
CI_PARAMS="${CI_PARAMS:-}"
CONTAINER_BIN=""
CONTAINER_HOST="host.docker.internal"
SUITE_EXIT_CODE=0

# Parse options
OPTIND=1
while getopts "s:b:p:xy:nhu" OPT; do
    case ${OPT} in
        s) TEST_SUITE=${OPTARG} ;;
        b) CONTAINER_BIN=${OPTARG} ;;
        p) PHP_VERSION=${OPTARG} ;;
        x) PHP_XDEBUG_ON=1 ;;
        y) PHP_XDEBUG_PORT=${OPTARG} ;;
        n) CGLCHECK_DRY_RUN=1 ;;
        h) loadHelp; echo "${HELP}"; exit 0 ;;
        u) TEST_SUITE=update ;;
        \?) exit 1 ;;
    esac
done

# Extension version for Composer
COMPOSER_ROOT_VERSION="dev-main"

HOST_UID=$(id -u)
USERSET=""
if [ $(uname) != "Darwin" ]; then
    USERSET="--user $HOST_UID"
fi

# Navigate to project root
THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
cd "$THIS_SCRIPT_DIR" || exit 1
cd ../../ || exit 1
ROOT_DIR="${PWD}"

# Create cache directories
mkdir -p .Build/.cache
mkdir -p .Build/Web/typo3temp/var/tests

IMAGE_PREFIX="docker.io/"
TYPO3_IMAGE_PREFIX="ghcr.io/typo3/"
CONTAINER_INTERACTIVE="-it --init"

IS_CORE_CI=0
if [ "${CI}" == "true" ] || ! [ -t 0 ]; then
    IS_CORE_CI=1
    IMAGE_PREFIX=""
    CONTAINER_INTERACTIVE=""
fi

# Determine container binary
if [[ -z "${CONTAINER_BIN}" ]]; then
    if type "podman" >/dev/null 2>&1; then
        CONTAINER_BIN="podman"
    elif type "docker" >/dev/null 2>&1; then
        CONTAINER_BIN="docker"
    fi
fi

# Container images
IMAGE_PHP="${TYPO3_IMAGE_PREFIX}core-testing-$(echo "php${PHP_VERSION}" | sed -e 's/\.//'):latest"
IMAGE_ALPINE="${IMAGE_PREFIX}alpine:3.20"

shift $((OPTIND - 1))

SUFFIX="$(date +%s)-${RANDOM}"
NETWORK="nr-mcp-agent-${SUFFIX}"
if ! ${CONTAINER_BIN} network create ${NETWORK} >/dev/null 2>&1; then
    echo "Failed to create container network '${NETWORK}'. Ensure ${CONTAINER_BIN} daemon is running." >&2
    exit 1
fi

if [ ${CONTAINER_BIN} = "docker" ]; then
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} --add-host "${CONTAINER_HOST}:host-gateway" ${USERSET} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
else
    CONTAINER_HOST="host.containers.internal"
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm --network ${NETWORK} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
fi

if [ ${PHP_XDEBUG_ON} -eq 0 ]; then
    XDEBUG_MODE="-e XDEBUG_MODE=off"
    XDEBUG_CONFIG=" "
else
    XDEBUG_MODE="-e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=foo"
    XDEBUG_CONFIG="client_port=${PHP_XDEBUG_PORT} client_host=${CONTAINER_HOST}"
fi

# PHP performance options
PHP_OPCACHE_OPTS="-d opcache.enable_cli=1 -d opcache.jit=1255 -d opcache.jit_buffer_size=128M"

# Suite execution
case ${TEST_SUITE} in
    architecture)
        COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name architecture-${SUFFIX} -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    cgl)
        if [ "${CGLCHECK_DRY_RUN}" -eq 1 ]; then
            COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/php-cs-fixer fix -v --dry-run --diff"
        else
            COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/php-cs-fixer fix -v"
        fi
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name cgl-${SUFFIX} -e COMPOSER_CACHE_DIR=.Build/.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    clean)
        cleanCacheFiles
        SUITE_EXIT_CODE=0
        ;;
    composer)
        COMMAND=(composer "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-${SUFFIX} -e COMPOSER_CACHE_DIR=.Build/.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    composerUpdate)
        rm -rf .Build/bin/ .Build/vendor ./composer.lock
        COMMAND=(composer install --no-ansi --no-interaction --no-progress)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-${SUFFIX} -e COMPOSER_CACHE_DIR=.Build/.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    lint)
        COMMAND="find . -name \\*.php ! -path \"./.Build/\\*\" -print0 | xargs -0 -n1 -P\$(nproc) php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off -l >/dev/null"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-${SUFFIX} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    mutation)
        COMMAND=(php -d opcache.enable_cli=1 .Build/bin/infection --configuration=infection.json.dist --threads=4 "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name mutation-${SUFFIX} -e XDEBUG_MODE=coverage ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    phpstan)
        COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-${SUFFIX} -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    rector)
        if [ "${CGLCHECK_DRY_RUN}" -eq 1 ]; then
            COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/rector process --config Build/rector/rector.php --dry-run"
        else
            COMMAND="php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/rector process --config Build/rector/rector.php"
        fi
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name rector-${SUFFIX} -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    unit)
        COMMAND=(php ${PHP_OPCACHE_OPTS} -dxdebug.mode=off .Build/bin/phpunit -c Build/phpunit.xml --testsuite unit "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    unitCoverage)
        mkdir -p .Build/coverage
        COMMAND=(php -d opcache.enable_cli=1 .Build/bin/phpunit -c Build/phpunit.xml --testsuite unit --coverage-clover=.Build/coverage/unit.xml --coverage-html=.Build/coverage/html-unit --coverage-text "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-coverage-${SUFFIX} -e XDEBUG_MODE=coverage ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    update)
        echo "> Updating ${TYPO3_IMAGE_PREFIX}core-testing-* images..."
        ${CONTAINER_BIN} images "${TYPO3_IMAGE_PREFIX}core-testing-*" --format "{{.Repository}}:{{.Tag}}" | xargs -I {} ${CONTAINER_BIN} pull {}
        SUITE_EXIT_CODE=$?
        ;;
    *)
        loadHelp
        echo "Invalid -s option: ${TEST_SUITE}" >&2
        echo "${HELP}" >&2
        exit 1
        ;;
esac

cleanUp

# Print summary
echo "" >&2
echo "###########################################################################" >&2
echo "Result of ${TEST_SUITE}" >&2
echo "Container runtime: ${CONTAINER_BIN}" >&2
if [[ ${IS_CORE_CI} -eq 1 ]]; then
    echo "Environment: CI" >&2
else
    echo "Environment: local" >&2
fi
echo "PHP: ${PHP_VERSION}" >&2
if [[ ${SUITE_EXIT_CODE} -eq 0 ]]; then
    echo "SUCCESS" >&2
else
    echo "FAILURE" >&2
fi
echo "###########################################################################" >&2
echo "" >&2

exit $SUITE_EXIT_CODE
