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
# If PHP/Composer live inside a container, set DEPLOY_EXEC to the prefix that
# reaches them, e.g. DEPLOY_EXEC="docker compose exec -T www".

set -euo pipefail

REF="${1:-origin/main}"
BRANCH="${DEPLOY_BRANCH:-main}"
EXEC="${DEPLOY_EXEC:-}"

cd "$(dirname "$0")/.."

php()      { $EXEC php "$@"; }
composer() { $EXEC composer "$@"; }
log()      { echo "==> $*"; }

if [ ! -f .env ]; then
    echo ".env is missing in $(pwd); create it before the first deploy." >&2
    exit 1
fi

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
log "Deployed $(git rev-parse --short HEAD)"
