#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# Fail fast before spending time building images or running the full suite.
bash -n "$0"

IMAGE="${HIPOCRATIQ_QA_IMAGE:-hipocratiq-backend-ci:qa-gate}"
RUN_ID="${HIPOCRATIQ_QA_RUN_ID:-$(date -u +%Y%m%d%H%M%S)}"
NETWORK="hipocratiq-qa-${RUN_ID}"
POSTGRES="hipocratiq-qa-postgres-${RUN_ID}"
REDIS="hipocratiq-qa-redis-${RUN_ID}"
APP="hipocratiq-qa-backend-${RUN_ID}"
APP_PORT="${HIPOCRATIQ_QA_PORT:-18080}"
DB_NAME="hipocratiq_qa"
DB_USER="hipocratiq_qa"
DB_PASSWORD="${HIPOCRATIQ_QA_DB_PASSWORD:-qa_only_not_production}"

cleanup() {
  docker rm -f "$APP" >/dev/null 2>&1 || true
  docker rm -f "$REDIS" >/dev/null 2>&1 || true
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
  --health-cmd="pg_isready -U $DB_USER -d $DB_NAME" \
  --health-interval=3s \
  --health-timeout=3s \
  --health-retries=20 \
  postgres:17-alpine >/dev/null

for attempt in {1..30}; do
  if docker exec "$POSTGRES" pg_isready -U "$DB_USER" -d "$DB_NAME" >/dev/null 2>&1; then
    echo "PostgreSQL QA listo."
    break
  fi

  if [[ "$attempt" -eq 30 ]]; then
    echo "ERROR: PostgreSQL QA no quedó listo."
    docker logs "$POSTGRES" || true
    exit 1
  fi

  sleep 1
done

echo "== Start isolated Redis QA =="
docker run -d --name "$REDIS" --network "$NETWORK" \
  --health-cmd="redis-cli ping || exit 1" \
  --health-interval=3s \
  --health-timeout=3s \
  --health-retries=20 \
  redis:8.2.7-alpine >/dev/null

for attempt in {1..30}; do
  if docker exec "$REDIS" redis-cli ping 2>/dev/null | grep -q '^PONG$'; then
    echo "Redis QA listo."
    break
  fi

  if [[ "$attempt" -eq 30 ]]; then
    echo "ERROR: Redis QA no quedó listo."
    docker logs "$REDIS" || true
    exit 1
  fi

  sleep 1
done

common_env=(
  -e APP_ENV=testing
  -e APP_DEBUG=false
  -e CACHE_STORE=redis
  -e CACHE_LIMITER_STORE=redis_limiter
  -e SESSION_DRIVER=redis
  -e SESSION_CONNECTION=session
  -e SESSION_STORE=redis_session
  -e QUEUE_CONNECTION=sync
  -e FILESYSTEM_DISK=local
  -e BACKUP_SERVICE_ENABLED=false
  -e REDIS_HOST="$REDIS"
  -e REDIS_PORT=6379
  -e REDIS_URL=null
  -e REDIS_CACHE_URL=null
  -e REDIS_SESSION_URL=null
  -e REDIS_LIMITER_URL=null
  -e REDIS_PASSWORD=null
  -e REDIS_DB=0
  -e REDIS_CACHE_DB=1
  -e REDIS_SESSION_DB=2
  -e REDIS_LIMITER_DB=3
  -e REDIS_LOCKS_DB=4
  -e REDIS_CACHE_CONNECTION=cache
  -e REDIS_CACHE_LOCK_CONNECTION=locks
  -e REDIS_LIMITER_CONNECTION=limiter
  -e DB_CONNECTION=pgsql
  -e DB_URL=null
  -e DB_HOST="$POSTGRES"
  -e DB_PORT=5432
  -e DB_DATABASE="$DB_NAME"
  -e DB_USERNAME="$DB_USER"
  -e DB_PASSWORD="$DB_PASSWORD"
)

echo "== Redis integration tests =="
docker run --rm --network "$NETWORK" \
  "${common_env[@]}" \
  "$IMAGE" php artisan test --filter=RedisInfrastructureTest

echo "== Backup / verify / restore drill =="
docker run --rm --network "$NETWORK" \
  "${common_env[@]}" \
  -e BACKUP_SERVICE_ENABLED=true \
  -e BACKUP_SERVICE_PROVIDER=postgresql \
  -e BACKUP_STORAGE_DISK=local \
  -e BACKUP_PREFIX=qa-restore \
  "$IMAGE" bash -lc '
    set -euo pipefail
    export PGPASSWORD="$DB_PASSWORD"

    php artisan migrate:fresh --force --no-interaction
    php artisan db:seed --force --no-interaction

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

echo "== Start isolated backend QA on host port $APP_PORT =="
docker run -d \
  --name "$APP" \
  --network "$NETWORK" \
  -p "127.0.0.1:${APP_PORT}:8000" \
  "${common_env[@]}" \
  "$IMAGE" \
  php -S 0.0.0.0:8000 -t public public/index.php >/dev/null

echo "== Wait for backend QA =="
for attempt in {1..40}; do
  LIVE_FILE="$(mktemp)"
  LIVE_STATUS="$(curl --silent --show-error \
    --output "$LIVE_FILE" \
    --write-out "%{http_code}" \
    "http://127.0.0.1:${APP_PORT}/api/health/live" || true)"

  if [[ "$LIVE_STATUS" == "200" ]] \
    && grep -Eq '"success"[[:space:]]*:[[:space:]]*true' "$LIVE_FILE" \
    && grep -Eq '"status"[[:space:]]*:[[:space:]]*"ok"' "$LIVE_FILE"; then
    rm -f "$LIVE_FILE"
    echo "Backend QA listo."
    break
  fi

  rm -f "$LIVE_FILE"

  if [[ "$attempt" -eq 40 ]]; then
    echo "ERROR: Backend QA no inició con una respuesta JSON válida."
    docker ps -a --filter "name=$APP"
    docker logs "$APP" || true
    exit 1
  fi

  sleep 1
done

echo "== Readiness QA =="
READINESS_FILE="$(mktemp)"
READINESS_STATUS="$(curl --silent --show-error \
  --output "$READINESS_FILE" \
  --write-out "%{http_code}" \
  "http://127.0.0.1:${APP_PORT}/api/health/ready" || true)"
cat "$READINESS_FILE"
echo

if [[ "$READINESS_STATUS" != "200" ]] \
  || ! grep -Eq '"success"[[:space:]]*:[[:space:]]*true' "$READINESS_FILE" \
  || ! grep -Eq '"status"[[:space:]]*:[[:space:]]*"ok"' "$READINESS_FILE" \
  || ! grep -Eq '"database"[[:space:]]*:[[:space:]]*"ok"' "$READINESS_FILE" \
  || ! grep -Eq '"redis"[[:space:]]*:[[:space:]]*"ok"' "$READINESS_FILE" \
  || ! grep -Eq '"storage"[[:space:]]*:[[:space:]]*"ok"' "$READINESS_FILE"; then
  echo "ERROR: readiness respondió HTTP $READINESS_STATUS."
  echo "== Backend QA logs =="
  docker logs "$APP" || true

  echo "== Backend QA effective network environment =="
  docker exec "$APP" sh -lc '
    printf "DB_HOST=%s DB_PORT=%s DB_DATABASE=%s DB_USERNAME=%s\n" "$DB_HOST" "$DB_PORT" "$DB_DATABASE" "$DB_USERNAME"
    printf "REDIS_HOST=%s REDIS_PORT=%s\n" "$REDIS_HOST" "$REDIS_PORT"
    printf "DB_DNS=%s\n" "$(getent hosts "$DB_HOST" | awk "NR==1 {print \$1}")"
    printf "REDIS_DNS=%s\n" "$(getent hosts "$REDIS_HOST" | awk "NR==1 {print \$1}")"
  ' || true

  echo "== PostgreSQL connectivity from backend QA container =="
  docker exec "$APP" sh -lc '
    export PGPASSWORD="$DB_PASSWORD"
    pg_isready --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE"
    psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" -Atqc "select 1"
  ' || true

  echo "== Redis connectivity from backend QA container =="
  docker exec "$APP" php -r '
    $redis = new Redis();
    $redis->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"), 2.0);
    echo $redis->ping(), PHP_EOL;
  ' || true

  echo "== PostgreSQL QA status =="
  docker exec "$POSTGRES" pg_isready -U "$DB_USER" -d "$DB_NAME" || true
  echo "== Redis QA status =="
  docker exec "$REDIS" redis-cli ping || true
  rm -f "$READINESS_FILE"
  exit 1
fi

rm -f "$READINESS_FILE"

echo "== Functional probe before performance smoke =="
PLANS_FILE="$(mktemp)"
PLANS_STATUS="$(curl --silent --show-error \
  --output "$PLANS_FILE" \
  --write-out "%{http_code}" \
  "http://127.0.0.1:${APP_PORT}/api/v1/auth/register/clinic/plans" || true)"
cat "$PLANS_FILE"
echo

if [[ "$PLANS_STATUS" != "200" ]] \
  || ! grep -Eq '"success"[[:space:]]*:[[:space:]]*true' "$PLANS_FILE" \
  || ! grep -Eq '"data"[[:space:]]*:[[:space:]]*\[' "$PLANS_FILE"; then
  echo "ERROR: el endpoint de planes no respondió JSON funcional antes del benchmark."
  docker logs "$APP" || true
  rm -f "$PLANS_FILE"
  exit 1
fi

rm -f "$PLANS_FILE"

echo "== Performance smoke QA =="
PERF_REQUESTS="${PERF_REQUESTS:-25}" \
PERF_MAX_AVG_SECONDS="${PERF_MAX_AVG_SECONDS:-0.750}" \
PERF_MAX_P95_SECONDS="${PERF_MAX_P95_SECONDS:-1.500}" \
bash scripts/performance-smoke.sh \
  "http://127.0.0.1:${APP_PORT}/api/v1/auth/register/clinic/plans"

echo "== Final QA container status =="
docker ps --filter "name=$APP" --filter "name=$POSTGRES" --filter "name=$REDIS" \
  --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

echo "Backend QA gate OK"
