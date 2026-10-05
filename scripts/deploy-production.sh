#!/usr/bin/env bash
set -Eeuo pipefail

PRODUCTION_DIR="${PRODUCTION_DIR:-/srv/hipocratiq/backend}"
COMPOSE_PROJECT="${COMPOSE_PROJECT:-hipocratiq-backend}"
HEALTH_URL="${HEALTH_URL:-https://hipocratiq.com/backend/api/health/ready}"
LOCK_FILE="${LOCK_FILE:-/tmp/hipocratiq-backend-deploy.lock}"
TARGET_BRANCH="main"

for command in git docker curl flock awk; do
  command -v "$command" >/dev/null 2>&1 || {
    echo "ERROR: falta el comando requerido: $command" >&2
    exit 1
  }
done

exec 9>"$LOCK_FILE"
flock -n 9 || {
  echo "ERROR: ya existe un despliegue Backend en ejecución." >&2
  exit 1
}

cd "$PRODUCTION_DIR"

if [[ "$(git branch --show-current)" != "$TARGET_BRANCH" ]]; then
  echo "ERROR: el repositorio productivo debe permanecer en main." >&2
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "ERROR: el repositorio productivo contiene cambios locales. No se sobrescriben automáticamente." >&2
  git status --short
  exit 1
fi

OLD_SHA="$(git rev-parse HEAD)"
BACKUP_ID=""
UPDATED=0

compose() {
  docker compose -p "$COMPOSE_PROJECT" "$@"
}

rollback_code() {
  set +e
  if [[ "$UPDATED" -eq 1 ]]; then
    echo "ROLLBACK: restaurando código a $OLD_SHA"
    git reset --hard "$OLD_SHA"
    compose up -d --build --no-deps app queue-worker scheduler
    compose exec -T app php artisan optimize:clear || true
  fi

  if [[ -n "$BACKUP_ID" ]]; then
    echo "BACKUP DISPONIBLE: $BACKUP_ID"
    echo "La base de datos NO se restaura automáticamente."
    echo "Si una migración requiere restore, ejecutar de forma controlada:"
    echo "docker compose -p $COMPOSE_PROJECT exec -T app php artisan backups:restore $BACKUP_ID --confirm=$BACKUP_ID"
  fi
}

on_error() {
  local line="$1"
  echo "ERROR: despliegue Backend falló cerca de la línea $line." >&2
  rollback_code
}
trap 'on_error "$LINENO"' ERR

echo "== HIPOCRATIQ Backend deploy =="
echo "Origen: $OLD_SHA"

git fetch --prune origin "$TARGET_BRANCH"
TARGET_SHA="$(git rev-parse "origin/$TARGET_BRANCH")"

if ! git merge-base --is-ancestor "$OLD_SHA" "$TARGET_SHA"; then
  echo "ERROR: origin/main no es fast-forward respecto al estado productivo actual." >&2
  exit 1
fi

echo "Destino: $TARGET_SHA"

echo "Creando respaldo PostgreSQL previo..."
BACKUP_OUTPUT="$(compose exec -T app php artisan backups:create --no-interaction 2>&1)"
printf '%s\n' "$BACKUP_OUTPUT"
BACKUP_ID="$(printf '%s\n' "$BACKUP_OUTPUT" | awk -F': ' '/Respaldo creado:/{print $2; exit}')"

if [[ -z "$BACKUP_ID" ]]; then
  echo "ERROR: no se pudo obtener el ID del respaldo previo." >&2
  exit 1
fi

compose exec -T app php artisan backups:verify "$BACKUP_ID" --no-interaction

if [[ "$OLD_SHA" != "$TARGET_SHA" ]]; then
  git merge --ff-only "origin/$TARGET_BRANCH"
  UPDATED=1
fi

echo "Construyendo imágenes Backend..."
compose build app queue-worker scheduler

echo "Aplicando migraciones con la nueva imagen..."
compose run --rm --no-deps app php artisan migrate --force --no-interaction

echo "Actualizando servicios..."
compose up -d --no-deps app queue-worker scheduler
compose exec -T app php artisan optimize:clear
compose exec -T app php artisan migrate:status --no-interaction >/dev/null

wait_for_service_health() {
  local service="$1"
  local container_id
  local status=""

  container_id="$(compose ps -q "$service")"
  if [[ -z "$container_id" ]]; then
    echo "ERROR: no se encontró contenedor para $service." >&2
    return 1
  fi

  for attempt in {1..30}; do
    if [[ "$(docker inspect --format '{{.State.Running}}' "$container_id")" != "true" ]]; then
      echo "ERROR: el servicio $service dejó de ejecutarse." >&2
      docker logs --tail 120 "$container_id" || true
      return 1
    fi

    status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$container_id")"

    if [[ "$status" == "healthy" ]]; then
      return 0
    fi

    if [[ "$status" == "unhealthy" ]]; then
      echo "ERROR: el servicio $service quedó unhealthy." >&2
      docker inspect --format '{{json .State.Health}}' "$container_id" || true
      docker logs --tail 120 "$container_id" || true
      return 1
    fi

    sleep 2
  done

  echo "ERROR: el servicio $service no alcanzó estado healthy; estado final: $status." >&2
  docker inspect --format '{{json .State.Health}}' "$container_id" || true
  docker logs --tail 120 "$container_id" || true
  return 1
}

echo "Validando salud de app, queue y scheduler..."
for service in app queue-worker scheduler; do
  wait_for_service_health "$service"
done

echo "Esperando readiness interno..."
READY=0
for attempt in {1..30}; do
  if curl --fail --silent --show-error http://127.0.0.1:8000/api/health/ready >/dev/null; then
    READY=1
    break
  fi
  sleep 2
done

if [[ "$READY" -ne 1 ]]; then
  echo "ERROR: readiness interno no quedó disponible." >&2
  exit 1
fi

if [[ -n "$HEALTH_URL" ]]; then
  curl --fail --silent --show-error "$HEALTH_URL" >/dev/null
fi

trap - ERR
echo "DEPLOY BACKEND OK"
echo "SHA: $(git rev-parse HEAD)"
echo "Backup previo verificado: $BACKUP_ID"
