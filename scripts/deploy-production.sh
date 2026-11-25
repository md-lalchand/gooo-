#!/usr/bin/env bash
set -Eeuo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH is required}"
: "${NODE_SERVICE:?NODE_SERVICE is required}"
: "${PHP_FPM_SERVICE:?PHP_FPM_SERVICE is required}"
: "${NODE_HEALTH_URL:?NODE_HEALTH_URL is required}"
: "${LARAVEL_HEALTH_URL:?LARAVEL_HEALTH_URL is required}"

if [[ ! -d "$DEPLOY_PATH/.git" ]]; then
  echo "Deployment path is not an existing Git checkout: $DEPLOY_PATH" >&2
  exit 1
fi

cd "$DEPLOY_PATH"
exec 9>"$DEPLOY_PATH/.deploy.lock"
if ! flock -n 9; then
  echo "Another deployment is already running." >&2
  exit 1
fi

git fetch --prune origin main
PREVIOUS_SHA="$(git rev-parse HEAD)"

# Put Laravel into maintenance mode before changing any live application files.
cd "$DEPLOY_PATH/laravel-system"
php artisan down --retry=60

DEPLOY_SUCCEEDED=0
on_exit() {
  status=$?
  if [[ "$DEPLOY_SUCCEEDED" -ne 1 ]]; then
    echo "Deployment did not complete successfully; keeping Laravel in maintenance mode." >&2
    php artisan down --retry=60 || true
  fi
  exit "$status"
}
trap on_exit EXIT

cd "$DEPLOY_PATH"
git checkout main
git reset --hard origin/main

cd "$DEPLOY_PATH/laravel-system"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan view:cache

cd "$DEPLOY_PATH"
npm ci
npm run lint
npm run build

sudo systemctl restart "$NODE_SERVICE"
sudo systemctl reload "$PHP_FPM_SERVICE"

# Health checks require the application to be out of maintenance mode.
cd "$DEPLOY_PATH/laravel-system"
php artisan up
curl --fail --silent --show-error --retry 10 --retry-delay 3 "$LARAVEL_HEALTH_URL" >/dev/null
curl --fail --silent --show-error --retry 10 --retry-delay 3 "$NODE_HEALTH_URL" >/dev/null

DEPLOY_SUCCEEDED=1
trap - EXIT
echo "Production release deployed and both health endpoints passed."

# PREVIOUS_SHA is recorded for operator-assisted rollback if a release fails.
# Database migrations may not be reversible; review migration compatibility before deploying.
