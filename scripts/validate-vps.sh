#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
IMAGE="hipocratiq-backend-ci:${RUN_ID}"
RELEASE_IMAGE="hipocratiq-backend-releasecheck:${RUN_ID}"
NETWORK="hipocratiq-ci-${RUN_ID}"
POSTGRES="hipocratiq-ci-postgres-${RUN_ID}"
REDIS="hipocratiq-ci-redis-${RUN_ID}"
APP="hipocratiq-ci-app-${RUN_ID}"
LOG_DIR="${VALIDATION_LOG_DIR:-/tmp/hipocratiq-validation}"
LOG_FILE="${LOG_DIR}/backend-${RUN_ID}.log"
TEST_APP_KEY="base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY="

mkdir -p "$LOG_DIR"
exec > >(tee -a "$LOG_FILE") 2>&1

cleanup() {
  set +e
  docker rm -f "$APP" "$REDIS" "$POSTGRES" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  docker image rm -f "$IMAGE" "$RELEASE_IMAGE" >/dev/null 2>&1 || true
}
trap cleanup EXIT

for command in git docker curl; do
  command -v "$command" >/dev/null 2>&1 || {
    echo "ERROR: falta el comando requerido: $command" >&2
    exit 1
  }
done

docker info >/dev/null
docker compose version >/dev/null

echo "== HIPOCRATIQ Backend VPS validation =="
echo "SHA: $(git rev-parse HEAD)"
echo "Branch: $(git branch --show-current)"
echo "Log: $LOG_FILE"

if [[ -n "$(git status --porcelain)" ]]; then
  echo "ERROR: el checkout de validación debe estar limpio." >&2
  git status --short
  exit 1
fi

echo "[1/11] Build imagen CI"
docker build --pull -f Dockerfile.ci -t "$IMAGE" .

run_ci() {
  docker run --rm \
    -e APP_ENV=testing \
    -e APP_KEY="$TEST_APP_KEY" \
    -e OTP_HASH_KEY=ci-test-hash-key \
    -e OTP_FIXED_CODE=123456 \
    -e CACHE_STORE=array \
    -e SESSION_DRIVER=array \
    -e QUEUE_CONNECTION=sync \
    -e DB_CONNECTION=sqlite \
    -e DB_URL= \
    -e DB_DATABASE=:memory: \
    "$IMAGE" "$@"
}

echo "[2/11] Composer validate + audit"
run_ci composer validate --strict --no-interaction
run_ci composer audit --locked --no-interaction

echo "[2b/11] Baseline de versiones de seguridad"
run_ci php scripts/check-security-baseline.php

echo "[3/11] Laravel Pint"
run_ci vendor/bin/pint --test

echo "[4/11] PHPUnit completo"
docker run --rm \
  -e APP_ENV=testing \
  -e APP_KEY="$TEST_APP_KEY" \
  -e OTP_HASH_KEY=ci-test-hash-key \
  -e OTP_FIXED_CODE=123456 \
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e QUEUE_CONNECTION=sync \
  -e DB_CONNECTION=sqlite \
  -e DB_URL= \
  -e DB_DATABASE=:memory: \
  "$IMAGE" composer test

echo "[5/11] PostgreSQL 17 + migraciones limpias"
docker network create "$NETWORK" >/dev/null
docker run -d --name "$POSTGRES" --network "$NETWORK" \
  -e POSTGRES_DB=back_api \
  -e POSTGRES_USER=root \
  -e POSTGRES_PASSWORD=root \
  postgres:17-alpine >/dev/null

for attempt in {1..30}; do
  if docker exec "$POSTGRES" pg_isready -U root -d back_api >/dev/null 2>&1; then
    break
  fi
  if [[ "$attempt" -eq 30 ]]; then
    echo "ERROR: PostgreSQL CI no quedó listo." >&2
    docker logs "$POSTGRES" || true
    exit 1
  fi
  sleep 1
done

docker run --rm --network "$NETWORK" \
  -e APP_ENV=testing \
  -e APP_KEY="$TEST_APP_KEY" \
  -e OTP_HASH_KEY=ci-test-hash-key \
  -e OTP_FIXED_CODE=123456 \
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e QUEUE_CONNECTION=sync \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST="$POSTGRES" \
  -e DB_PORT=5432 \
  -e DB_DATABASE=back_api \
  -e DB_USERNAME=root \
  -e DB_PASSWORD=root \
  -e DB_URL= \
  "$IMAGE" php artisan migrate:fresh --force --no-interaction

echo "[5b/11] Redis 8 aislado + infraestructura"
REDIS_PASSWORD_QA="hipocratiq-qa-redis"
docker run -d --name "$REDIS" --network "$NETWORK" \
  redis:8.2.7-alpine redis-server --requirepass "$REDIS_PASSWORD_QA" >/dev/null

for attempt in {1..30}; do
  if docker exec "$REDIS" redis-cli -a "$REDIS_PASSWORD_QA" --no-auth-warning ping 2>/dev/null | grep -qx PONG; then
    break
  fi
  if [[ "$attempt" -eq 30 ]]; then
    echo "ERROR: Redis CI no quedó listo." >&2
    docker logs "$REDIS" || true
    exit 1
  fi
  sleep 1
done

docker run --rm --network "$NETWORK" \
  -e APP_ENV=testing \
  -e APP_KEY="$TEST_APP_KEY" \
  -e OTP_HASH_KEY=ci-test-hash-key \
  -e OTP_FIXED_CODE=123456 \
  -e CACHE_STORE=redis \
  -e CACHE_LIMITER_STORE=redis_limiter \
  -e SESSION_DRIVER=redis \
  -e SESSION_CONNECTION=session \
  -e SESSION_STORE=redis_session \
  -e QUEUE_CONNECTION=sync \
  -e DB_CONNECTION=sqlite \
  -e DB_URL= \
  -e DB_DATABASE=:memory: \
  -e REDIS_HOST="$REDIS" \
  -e REDIS_PORT=6379 \
  -e REDIS_PASSWORD="$REDIS_PASSWORD_QA" \
  -e REDIS_DB=0 \
  -e REDIS_CACHE_DB=1 \
  -e REDIS_SESSION_DB=2 \
  -e REDIS_LIMITER_DB=3 \
  -e REDIS_LOCKS_DB=4 \
  "$IMAGE" php artisan test tests/Feature/RedisInfrastructureTest.php

echo "[6/11] Backup create/verify/restore aislado"
docker run --rm --network "$NETWORK" \
  -e APP_ENV=testing \
  -e APP_KEY="$TEST_APP_KEY" \
  -e OTP_HASH_KEY=ci-test-hash-key \
  -e OTP_FIXED_CODE=123456 \
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e QUEUE_CONNECTION=sync \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST="$POSTGRES" \
  -e DB_PORT=5432 \
  -e DB_DATABASE=back_api \
  -e DB_USERNAME=root \
  -e DB_PASSWORD=root \
  -e DB_URL= \
  -e PGPASSWORD=root \
  -e BACKUP_SERVICE_ENABLED=true \
  -e BACKUP_SERVICE_PROVIDER=postgresql \
  -e BACKUP_STORAGE_DISK=local \
  -e BACKUP_PREFIX=ci-validation \
  "$IMAGE" bash -lc '
    set -Eeuo pipefail
    psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
      --command="DROP TABLE IF EXISTS backup_restore_probe; CREATE TABLE backup_restore_probe (id integer PRIMARY KEY); INSERT INTO backup_restore_probe (id) VALUES (314159);"

    backup_output="$(php artisan backups:create --no-interaction)"
    printf "%s\n" "$backup_output"
    backup_id="$(printf "%s\n" "$backup_output" | awk -F": " "/Respaldo creado:/{print \$2; exit}")"

    if [[ -z "$backup_id" ]]; then
      echo "ERROR: no se pudo extraer el ID del backup de prueba." >&2
      exit 1
    fi

    php artisan backups:verify "$backup_id" --no-interaction
    psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
      --command="DROP TABLE backup_restore_probe;"
    php artisan backups:restore "$backup_id" --confirm="$backup_id" --no-interaction

    recovered="$(psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
      --tuples-only --no-align --command="SELECT id FROM backup_restore_probe WHERE id = 314159;")"
    [[ "$recovered" == "314159" ]] || {
      echo "ERROR: el restore no recuperó el dato centinela." >&2
      exit 1
    }
  '

echo "[7/11] UAT clínico explícito"
docker run --rm \
  -e APP_ENV=testing \
  -e APP_KEY="$TEST_APP_KEY" \
  -e OTP_HASH_KEY=ci-test-hash-key \
  -e OTP_FIXED_CODE=123456 \
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e QUEUE_CONNECTION=sync \
  -e DB_CONNECTION=sqlite \
  -e DB_URL= \
  -e DB_DATABASE=:memory: \
  "$IMAGE" php artisan test tests/Feature/Phase4ClinicalUatTest.php tests/Feature/DoctorWorkspaceTest.php tests/Feature/ClinicEpidemiologyConsentTest.php

echo "[8/11] Performance smoke (PostgreSQL 17 aislado)"
docker run -d --name "$APP" --network "$NETWORK" \
  -e APP_ENV=testing \
  -e APP_KEY="$TEST_APP_KEY" \
  -e OTP_HASH_KEY=ci-test-hash-key \
  -e OTP_FIXED_CODE=123456 \
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e QUEUE_CONNECTION=sync \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST="$POSTGRES" \
  -e DB_PORT=5432 \
  -e DB_DATABASE=back_api \
  -e DB_USERNAME=root \
  -e DB_PASSWORD=root \
  -e DB_URL= \
  "$IMAGE" bash -lc \
  'php artisan migrate:fresh --force --no-interaction && php artisan db:seed --force --no-interaction && php artisan config:cache && php artisan serve --host=0.0.0.0 --port=8000' >/dev/null

for attempt in {1..30}; do
  if docker run --rm --network "$NETWORK" "$IMAGE" curl --fail --silent "http://$APP:8000/api/health/live" >/dev/null 2>&1; then
    break
  fi
  if [[ "$attempt" -eq 30 ]]; then
    echo "ERROR: servidor de performance no quedó listo." >&2
    docker logs "$APP" || true
    docker exec "$APP" sh -lc 'test -f storage/logs/laravel.log && tail -n 120 storage/logs/laravel.log || true' || true
    exit 1
  fi
  sleep 1
done

if ! docker run --rm --network "$NETWORK" \
  -e PERF_REQUESTS="${PERF_REQUESTS:-25}" \
  -e PERF_MAX_AVG_SECONDS="${PERF_MAX_AVG_SECONDS:-0.750}" \
  -e PERF_MAX_P95_SECONDS="${PERF_MAX_P95_SECONDS:-1.500}" \
  "$IMAGE" bash scripts/performance-smoke.sh "http://$APP:8000/api/v1/auth/register/clinic/plans"; then
  echo "ERROR: performance smoke falló; diagnóstico del contenedor:" >&2
  docker logs "$APP" || true
  docker exec "$APP" sh -lc 'test -f storage/logs/laravel.log && tail -n 160 storage/logs/laravel.log || true' || true
  exit 1
fi

echo "[9/11] Compose + deploy script"
docker compose --env-file .env.example config --quiet
bash -n scripts/deploy-production.sh

echo "[10/11] Build imagen de producción"
docker build -t "$RELEASE_IMAGE" .
docker run --rm "$RELEASE_IMAGE" pg_dump --version
docker run --rm "$RELEASE_IMAGE" pg_restore --version

echo "[11/11] Route list + readiness contract"
run_ci php artisan route:list >/dev/null
run_ci php artisan test tests/Feature/HealthEndpointTest.php

echo "BACKEND VPS VALIDATION OK"
echo "SHA: $(git rev-parse HEAD)"
echo "Evidence: $LOG_FILE"
