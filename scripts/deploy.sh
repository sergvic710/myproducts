#!/usr/bin/env bash
#
# Server-side deploy. Run from the app checkout on the server:
#
#   bash scripts/deploy.sh [git-ref]
#
# CI (.github/workflows/deploy.yml) calls it over SSH with the merged commit
# SHA after uploading the built Vite assets to public/build. Run by hand with
# no argument to deploy the tip of origin/main.
#
# Panels like Hestia install several PHP versions and a per-user Composer, so
# the binaries can be pinned: PHP_BIN=/usr/bin/php8.4 COMPOSER_BIN=~/.composer/composer

set -euo pipefail

REF="${1:-origin/main}"
BRANCH="${DEPLOY_BRANCH:-main}"

cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer || echo "$HOME/.composer/composer")}"

php()      { command "$PHP_BIN" "$@"; }
composer() { command "$PHP_BIN" "$COMPOSER_BIN" "$@"; }
log()      { echo "==> $*"; }

if [ ! -f .env ]; then
    echo ".env is missing in $(pwd); create it before the first deploy." >&2
    exit 1
fi

log "Using $("$PHP_BIN" -r 'echo PHP_BINARY, " ", PHP_VERSION;'), composer at $COMPOSER_BIN"

log "Fetching $BRANCH"
git fetch --prune origin "$BRANCH"

# storage/ is gitignored, so make sure the directories Laravel needs exist.
mkdir -p storage/app/private/bot/docs storage/app/public \
    storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache

log "Entering maintenance mode"
php artisan down --retry=60 || true
trap 'echo "Deploy failed, leaving maintenance mode" >&2; php artisan up' ERR

log "Checking out $REF"
git reset --hard "$REF"

log "Installing PHP dependencies"
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

log "Running migrations"
php artisan migrate --force

log "Rebuilding caches"
php artisan optimize:clear
php artisan optimize
php artisan filament:optimize

[ -L public/storage ] || php artisan storage:link

log "Restarting queue workers"
php artisan queue:restart

trap - ERR
php artisan up

# Re-register the Telegram webhook so it always points at this app's current
# URL. Done after `up` and allowed to fail: the code is already live, and a
# Telegram outage must not mark a good deploy as broken. Pending updates are
# kept, so messages sent during maintenance are still delivered.
# The command prints the Telegram API URL, which embeds the bot token; mask it
# so the token never lands in the CI log.
log "Registering Telegram webhook"
if ! php artisan telegraph:set-webhook --no-interaction 2>&1 \
        | sed -E 's#bot[0-9]+:[A-Za-z0-9_-]+#bot***#g'; then
    echo "WARNING: telegraph:set-webhook failed; run it by hand (see telegraph:debug-webhook)" >&2
fi

log "Deployed $(git rev-parse --short HEAD)"
