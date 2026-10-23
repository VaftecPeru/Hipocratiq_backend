#!/usr/bin/env bash
set -Eeuo pipefail

PRODUCTION_DIR="${PRODUCTION_DIR:-/srv/hipocratiq/backend}"
COMPOSE_PROJECT="${COMPOSE_PROJECT:-}"
HEALTH_URL="${HEALTH_URL:-https://hipocratiq.com/backend/api/health/ready}"
LOCK_FILE="${LOCK_FILE:-/tmp/hipocratiq-backend-deploy.lock}"
BACKUP_ROOT="${BACKUP_ROOT:-/srv/hipocratiq/backups/predeploy}"
TARGET_BRANCH="main"

for command in git docker curl flock awk mkdir sha256sum; do
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

detect_compose_project() {
  local container project
  for container in back-api-app back-api-postgres back-api-redis; do
    project="$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}' "$container" 2>/dev/null || true)"
    if [[ -n "$project" && "$project" != "<no value>" ]]; then
      printf '%s\n' "$project"
      return 0
    fi
  done

  basename "$PRODUCTION_DIR"
}

if [[ -z "$COMPOSE_PROJECT" ]]; then
  COMPOSE_PROJECT="$(detect_compose_project)"
fi

compose() {
  docker compose -p "$COMPOSE_PROJECT" -f "$PRODUCTION_DIR/compose.yaml" "$@"
}

has_service() {
  compose config --services | grep -qx "$1"
}

runtime_services() {
  local service
  printf '%s\n' app
  for service in queue-worker scheduler; do
    if has_service "$service"; then
      printf '%s\n' "$service"
    fi
  done
}

OLD_SHA="$(git rev-parse HEAD)"
BACKUP_ID=""
BACKUP_PATH=""
BACKUP_SHA256=""
UPDATED=0

rollback_code() {
  set +e

  if [[ "$UPDATED" -eq 1 ]]; then
    echo "ROLLBACK: restaurando código a $OLD_SHA"
    git reset --hard "$OLD_SHA"

    for container in back-api-queue back-api-scheduler; do
      service_label="$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.service" }}' "$container" 2>/dev/null || true)"
      if [[ -n "$service_label" ]] && ! has_service "$service_label"; then
        echo "ROLLBACK: eliminando contenedor huérfano $container"
        docker rm -f "$container" >/dev/null 2>&1 || true
      fi
    done

    mapfile -t rollback_services < <(runtime_services)
    compose up -d --build --no-deps "${rollback_services[@]}"
    compose exec -T app php artisan optimize:clear || true
  fi

  if [[ -n "$BACKUP_PATH" ]]; then
    echo "BACKUP PRE-DEPLOY DISPONIBLE: $BACKUP_PATH"
    echo "SHA-256: $BACKUP_SHA256"
    echo "La base de datos NO se restaura automáticamente."
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
echo "Proyecto Compose detectado: $COMPOSE_PROJECT"

if ! has_service app || ! has_service postgres; then
  echo "ERROR: compose.yaml debe contener los servicios app y postgres." >&2
  compose config --services
  exit 1
fi

APP_CONTAINER="$(compose ps -q app)"
POSTGRES_CONTAINER="$(compose ps -q postgres)"

if [[ -z "$APP_CONTAINER" ]] || [[ "$(docker inspect --format '{{.State.Running}}' "$APP_CONTAINER")" != "true" ]]; then
  echo "ERROR: el servicio app actual no está en ejecución." >&2
  compose ps
  exit 1
fi

if [[ -z "$POSTGRES_CONTAINER" ]] || [[ "$(docker inspect --format '{{.State.Running}}' "$POSTGRES_CONTAINER")" != "true" ]]; then
  echo "ERROR: el servicio postgres actual no está en ejecución." >&2
  compose ps
  exit 1
fi

git fetch --prune origin "$TARGET_BRANCH"
TARGET_SHA="$(git rev-parse "origin/$TARGET_BRANCH")"

if ! git merge-base --is-ancestor "$OLD_SHA" "$TARGET_SHA"; then
  echo "ERROR: origin/main no es fast-forward respecto al estado productivo actual." >&2
  exit 1
fi

echo "Destino: $TARGET_SHA"

echo "Creando respaldo PostgreSQL previo independiente de Laravel..."
mkdir -p "$BACKUP_ROOT"
BACKUP_ID="$(date -u +%Y%m%dT%H%M%SZ)-${OLD_SHA:0:12}"
BACKUP_PATH="$BACKUP_ROOT/$BACKUP_ID.dump"

DB_USER_RUNTIME="$(docker exec "$APP_CONTAINER" sh -lc 'printf %s "$DB_USERNAME"')"
DB_NAME_RUNTIME="$(docker exec "$APP_CONTAINER" sh -lc 'printf %s "$DB_DATABASE"')"
DB_PASSWORD_RUNTIME="$(docker exec "$APP_CONTAINER" sh -lc 'printf %s "$DB_PASSWORD"')"

if [[ -z "$DB_USER_RUNTIME" || -z "$DB_NAME_RUNTIME" ]]; then
  echo "ERROR: no se pudieron resolver las credenciales de base de datos desde el contenedor app." >&2
  exit 1
fi

docker exec -e PGPASSWORD="$DB_PASSWORD_RUNTIME" "$POSTGRES_CONTAINER" \
  psql --username="$DB_USER_RUNTIME" --dbname="$DB_NAME_RUNTIME" --tuples-only --no-align --command='select 1' \
  | grep -qx '1'

docker exec -e PGPASSWORD="$DB_PASSWORD_RUNTIME" "$POSTGRES_CONTAINER" \
  pg_dump --format=custom --no-owner --no-privileges \
  --username="$DB_USER_RUNTIME" --dbname="$DB_NAME_RUNTIME" \
  > "$BACKUP_PATH"

unset DB_PASSWORD_RUNTIME

if [[ ! -s "$BACKUP_PATH" ]]; then
  echo "ERROR: el respaldo PostgreSQL quedó vacío." >&2
  exit 1
fi

compose exec -T postgres pg_restore --list < "$BACKUP_PATH" >/dev/null
BACKUP_SHA256="$(sha256sum "$BACKUP_PATH" | awk '{print $1}')"

echo "Backup verificado: $BACKUP_PATH"
echo "SHA-256: $BACKUP_SHA256"

if [[ "$OLD_SHA" != "$TARGET_SHA" ]]; then
  git merge --ff-only "origin/$TARGET_BRANCH"
  UPDATED=1
fi

mapfile -t services < <(runtime_services)

echo "Construyendo imágenes Backend: ${services[*]}"
compose build "${services[@]}"

echo "Aplicando migraciones con la nueva imagen..."
compose run --rm --no-deps app php artisan migrate --force --no-interaction

echo "Actualizando servicios..."
compose up -d --no-deps "${services[@]}"
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

echo "Validando salud de servicios activos..."
for service in "${services[@]}"; do
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
  compose logs --tail=120 app || true
  exit 1
fi

if [[ -n "$HEALTH_URL" ]]; then
  echo "Esperando readiness público..."
  PUBLIC_READY=0
  for attempt in {1..30}; do
    if curl --fail --silent --show-error "$HEALTH_URL" >/dev/null; then
      PUBLIC_READY=1
      break
    fi
    sleep 2
  done

  if [[ "$PUBLIC_READY" -ne 1 ]]; then
    echo "ERROR: readiness público no quedó disponible." >&2
    compose logs --tail=120 app || true
    exit 1
  fi
fi

trap - ERR
echo "DEPLOY BACKEND OK"
echo "SHA: $(git rev-parse HEAD)"
echo "Backup previo verificado: $BACKUP_PATH"
echo "Backup SHA-256: $BACKUP_SHA256"
