#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "== HIPOCRATIQ Backend preflight =="

if [[ ! -f vendor/autoload.php ]]; then
  composer install --no-interaction --no-progress
fi

composer validate --no-interaction
composer audit --locked --no-interaction
vendor/bin/pint --test

export APP_ENV=testing
export CACHE_STORE=array
export SESSION_DRIVER=array
export QUEUE_CONNECTION=sync
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_DATABASE:-$ROOT_DIR/database/preflight.sqlite}"

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
