#!/usr/bin/env sh
#
# Development task runner for the Omnitrack Engine Docker stack -- POSIX twin of
# scripts/dev.ps1, for WSL, macOS, Linux and CI runners.
#
# Usage:  ./scripts/dev.sh <command> [args...]
#
#   init      Create .env if missing, install vendor/ into its volume (slow once)
#   up        Start app + db with the source bind-mounted
#   build     Rebuild the app image (only for Dockerfile / composer.lock changes)
#   down      Stop the stack (-v also removes named volumes)
#   restart   down + up
#   test      Run the PHPUnit suite, no rebuild
#   artisan   php artisan inside the app container
#   tinker    php artisan tinker
#   composer  composer via the tools profile
#   npm       npm via the tools profile (once package.json exists)
#   logs      Follow logs (logs [service])
#   ps        Show containers and health
#   sh        Interactive shell in the app container
#   fresh     migrate:fresh --seed
#   config    Validate and print the merged compose config
#
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
cd "$REPO_ROOT"

# NOTE: the Compose project name is intentionally NOT set here. It comes from
# COMPOSE_PROJECT_NAME in .env, which keeps this script and a raw
# `docker compose -f ...` invocation in the *same* project -- and therefore
# sharing the same named volumes. Exporting it here would silently override
# .env and produce a second, empty set of volumes.

BASE=docker-compose.yml
DEV=docker-compose.dev.yml

# `--profile test` keeps the test service in the project model so that `down`
# and `ps` do not silently leave its container behind.
# `--profile test` is used ONLY where the test service must be visible to the
# project model. Enabling a profile also starts its services, so passing it to
# `up` booted omnitrack-test-1 -- which ran `php artisan test` under the dev
# entrypoint and exited 2 on every `up`. Hence dc_test() vs dc_run().
dc_test() {
    docker compose -f "$BASE" -f "$DEV" --profile test "$@"
}

dc_run() {
    docker compose -f "$BASE" -f "$DEV" "$@"
}

dc_tools() {
    docker compose -f "$BASE" -f "$DEV" --profile tools "$@"
}

cmd=${1:-help}
[ $# -gt 0 ] && shift

case "$cmd" in
    init)
        if [ ! -f .env ]; then
            echo "[init] .env missing -- copying .env.example"
            cp .env.example .env
            echo "[init] Set APP_KEY (php artisan key:generate) before serving."
        else
            echo "[init] .env present."
        fi
        echo "[init] Installing composer dependencies into the dev-vendor volume..."
        dc_tools run --rm composer install --no-interaction --prefer-dist "$@"
        echo "[init] Done. Next: ./scripts/dev.sh up"
        ;;

    up)      dc_run up -d "$@" ;;
    build)   dc_run build "$@" ;;
    down)    dc_test down "$@" ;;
    restart) dc_test down; dc_run up -d ;;
    test)    dc_test run --rm --pull never test "$@" ;;
    artisan) dc_test exec app php artisan "$@" ;;
    tinker)  dc_test exec app php artisan tinker ;;
    composer) dc_tools run --rm composer "$@" ;;
    npm)     dc_tools run --rm npm "$@" ;;
    logs)    dc_run logs -f --tail=100 "${1:-app}" ;;
    ps)      dc_test ps ;;
    sh)      dc_test exec app sh ;;
    fresh)   dc_test exec app php artisan migrate:fresh --seed ;;
    config)  dc_run config ;;

    help|*)
        sed -n '3,30p' "$0" | sed 's/^# \{0,1\}//'
        ;;
esac
