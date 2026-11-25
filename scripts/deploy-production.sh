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
git fetch --prune origin main
git checkout main
git pull --ff-only origin main

cd "$DEPLOY_PATH/laravel-system"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan down --retry=60 || true
restore_app() {
  php artisan up || true
}
trap restore_app EXIT

php artisan migrate --force
php artisan config:cache
php artisan view:cache

cd "$DEPLOY_PATH"
npm install --no-package-lock
npm run lint
npm run build

sudo systemctl restart "$NODE_SERVICE"
sudo systemctl reload "$PHP_FPM_SERVICE"
php artisan up
trap - EXIT

curl --fail --silent --show-error --retry 10 --retry-delay 3 "$LARAVEL_HEALTH_URL" >/dev/null
curl --fail --silent --show-error --retry 10 --retry-delay 3 "$NODE_HEALTH_URL" >/dev/null

echo "Production release deployed and both health endpoints passed."
