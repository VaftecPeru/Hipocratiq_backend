#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
QA_IMAGE="${HIPOCRATIQ_QA_IMAGE:-hipocratiq-backend-qa:php84}"

if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: Docker es obligatorio para ejecutar el preflight aislado en VPS." >&2
  exit 1
fi

if ! docker image inspect "$QA_IMAGE" >/dev/null 2>&1; then
  echo "ERROR: No existe la imagen QA '$QA_IMAGE'." >&2
  echo "Créala primero desde la imagen backend-app agregando pdo_sqlite." >&2
  exit 1
fi

echo "== HIPOCRATIQ Backend preflight aislado =="
echo "Imagen: $QA_IMAGE"
echo "Checkout: $ROOT_DIR"

docker run --rm   --user "$(id -u):$(id -g)"   -e HOME=/tmp   -e COMPOSER_HOME=/tmp/composer   -e APP_ENV=testing   -e CACHE_STORE=array   -e CACHE_LIMITER_STORE=array   -e SESSION_DRIVER=array   -e QUEUE_CONNECTION=sync   -e DB_CONNECTION=sqlite   -e DB_URL=   -e DB_DATABASE=/app/database/preflight.sqlite   -v "$ROOT_DIR:/app"   -w /app   "$QA_IMAGE"   bash -lc './scripts/preflight-release.sh'
