#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

IMAGE="${HIPOCRATIQ_QA_IMAGE:-hipocratiq-backend-ci:qa-gate}"
RUN_ID="${HIPOCRATIQ_QA_RUN_ID:-$(date -u +%Y%m%d%H%M%S)}"
NETWORK="hipocratiq-qa-${RUN_ID}"
POSTGRES="hipocratiq-qa-postgres-${RUN_ID}"
DB_NAME="hipocratiq_qa"
DB_USER="hipocratiq_qa"
DB_PASSWORD="qa_only_not_production"

cleanup() {
  docker rm -f "$POSTGRES" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "== Build backend QA image =="
docker build --pull -f Dockerfile.ci -t "$IMAGE" .

echo "== Backend preflight / unit / feature tests =="
docker run --rm "$IMAGE" bash scripts/preflight-release.sh

echo "== Start isolated PostgreSQL restore target =="
docker network create "$NETWORK" >/dev/null
docker run -d --name "$POSTGRES" --network "$NETWORK" \
  -e POSTGRES_DB="$DB_NAME" \
  -e POSTGRES_USER="$DB_USER" \
  -e POSTGRES_PASSWORD="$DB_PASSWORD" \
  postgres:17-alpine >/dev/null

for attempt in {1..30}; do
  if docker exec "$POSTGRES" pg_isready -U "$DB_USER" -d "$DB_NAME" >/dev/null 2>&1; then
    break
  fi
  if [[ "$attempt" -eq 30 ]]; then
    echo "ERROR: PostgreSQL QA no quedó listo."
    exit 1
  fi
  sleep 1
done

echo "== Backup / verify / restore drill =="
docker run --rm --network "$NETWORK" \
  -e APP_ENV=testing \
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e QUEUE_CONNECTION=sync \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST="$POSTGRES" \
  -e DB_PORT=5432 \
  -e DB_DATABASE="$DB_NAME" \
  -e DB_USERNAME="$DB_USER" \
  -e DB_PASSWORD="$DB_PASSWORD" \
  -e BACKUP_SERVICE_ENABLED=true \
  -e BACKUP_SERVICE_PROVIDER=postgresql \
  -e BACKUP_STORAGE_DISK=local \
  -e BACKUP_PREFIX=qa-restore \
  "$IMAGE" bash -lc '
    set -euo pipefail
    export PGPASSWORD="$DB_PASSWORD"

    php artisan migrate:fresh --force --no-interaction

    psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
      -v ON_ERROR_STOP=1 \
      -c "CREATE TABLE qa_restore_probe (id integer PRIMARY KEY, value text NOT NULL);" \
      -c "INSERT INTO qa_restore_probe (id, value) VALUES (1, '\''before'\'');"

    create_output="$(php artisan backups:create)"
    printf "%s\n" "$create_output"
    backup_id="$(printf "%s\n" "$create_output" | sed -n "s/^Respaldo creado: //p" | tail -n 1)"

    if [[ -z "$backup_id" ]]; then
      echo "ERROR: no se pudo obtener el identificador del respaldo."
      exit 1
    fi

    php artisan backups:verify "$backup_id"

    psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
      -v ON_ERROR_STOP=1 \
      -c "UPDATE qa_restore_probe SET value = '\''after'\'' WHERE id = 1;"

    php artisan backups:restore "$backup_id" --confirm="$backup_id"

    restored="$(psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
      -Atqc "SELECT value FROM qa_restore_probe WHERE id = 1;")"

    test "$restored" = "before"
    echo "Restore drill OK: $backup_id"
  '

echo "Backend QA gate OK"
