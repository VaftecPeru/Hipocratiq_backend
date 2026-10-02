#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "== HIPOCRATIQ Backend preflight =="

composer install --no-interaction --no-progress --prefer-dist
composer validate --strict --no-interaction
composer audit --locked --no-interaction
php -l scripts/check-security-baseline.php
php scripts/check-security-baseline.php
vendor/bin/pint --test

export APP_ENV=testing
export CACHE_STORE=array
export CACHE_LIMITER_STORE=array
export SESSION_DRIVER=array
export QUEUE_CONNECTION=sync
export DB_CONNECTION=sqlite
export DB_URL=''
export DB_DATABASE="${DB_DATABASE:-$ROOT_DIR/database/preflight.sqlite}"

# El checkout QA puede contener bootstrap cache generado con valores de producción
# (Redis/DB). Se elimina únicamente el cache compilado antes de bootear Artisan.
rm -f bootstrap/cache/config.php
rm -f bootstrap/cache/events.php
rm -f bootstrap/cache/routes-*.php
php artisan optimize:clear

rm -f "$DB_DATABASE"
touch "$DB_DATABASE"

cleanup() {
  rm -f "$DB_DATABASE"
}
trap cleanup EXIT

php artisan migrate:fresh --force --no-interaction
composer test
php artisan route:list >/dev/null

if command -v docker >/dev/null 2>&1; then
  docker compose config --quiet
else
  echo "WARN: Docker no disponible; se omite docker compose config."
fi

echo "Backend preflight OK"
