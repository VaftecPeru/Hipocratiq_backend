#!/usr/bin/env bash
set -Eeuo pipefail

PRODUCTION_DIR="${PRODUCTION_DIR:-/srv/hipocratiq/backend}"
HEALTH_URL="${HEALTH_URL:-https://hipocratiq.com/backend/api/health/ready}"
LOCK_FILE="${LOCK_FILE:-/tmp/hipocratiq-backend-deploy.lock}"
TARGET_BRANCH="main"

APP_CONTAINER="${APP_CONTAINER:-back-api-app}"
POSTGRES_CONTAINER="${POSTGRES_CONTAINER:-back-api-postgres}"
COMPOSE_PROJECT="${COMPOSE_PROJECT:-}"
BACKUP_ROOT="${BACKUP_ROOT:-/srv/hipocratiq/server-state/deploy-backups}"

for command in git docker curl flock awk sha256sum; do
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
UPDATED=0
BACKUP_FILE=""

container_label() {
  local container="$1"
  local label="$2"
  docker inspect --format "{{ index .Config.Labels \"$label\" }}" "$container" 2>/dev/null || true
}

container_env() {
  local container="$1"
  local key="$2"
  docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$container"     | awk -F= -v wanted="$key" '$1 == wanted {sub(/^[^=]*=/, ""); print; exit}'
}

detect_compose_project() {
  if [[ -n "$COMPOSE_PROJECT" ]]; then
    return
  fi

  if ! docker inspect "$APP_CONTAINER" >/dev/null 2>&1; then
    echo "ERROR: no existe el contenedor productivo esperado: $APP_CONTAINER" >&2
    exit 1
  fi

  COMPOSE_PROJECT="$(container_label "$APP_CONTAINER" "com.docker.compose.project")"

  if [[ -z "$COMPOSE_PROJECT" ]]; then
    echo "ERROR: no se pudo detectar el proyecto Docker Compose desde $APP_CONTAINER." >&2
    echo "Define COMPOSE_PROJECT explícitamente antes de desplegar." >&2
    exit 1
  fi
}

compose() {
  docker compose -p "$COMPOSE_PROJECT" "$@"
}

available_runtime_services() {
  local service
  local -a services=(app)

  for service in queue-worker scheduler; do
    if compose config --services 2>/dev/null | grep -qx "$service"; then
      services+=("$service")
    fi
  done

  printf '%s\n' "${services[@]}"
}

validate_running_runtime() {
  local project service running

  project="$(container_label "$APP_CONTAINER" "com.docker.compose.project")"
  service="$(container_label "$APP_CONTAINER" "com.docker.compose.service")"
  running="$(docker inspect --format '{{.State.Running}}' "$APP_CONTAINER" 2>/dev/null || true)"

  if [[ "$project" != "$COMPOSE_PROJECT" || "$service" != "app" || "$running" != "true" ]]; then
    echo "ERROR: el contenedor productivo no coincide con el proyecto Compose detectado." >&2
    echo "Proyecto esperado: $COMPOSE_PROJECT" >&2
    echo "Proyecto detectado: ${project:-<vacío>}" >&2
    echo "Servicio detectado: ${service:-<vacío>}" >&2
    echo "Running: ${running:-<desconocido>}" >&2
    exit 1
  fi
}

create_host_backup() {
  local db_user db_name db_password timestamp

  if ! docker inspect "$POSTGRES_CONTAINER" >/dev/null 2>&1; then
    echo "ERROR: no existe el contenedor PostgreSQL esperado: $POSTGRES_CONTAINER" >&2
    exit 1
  fi

  if [[ "$(docker inspect --format '{{.State.Running}}' "$POSTGRES_CONTAINER")" != "true" ]]; then
    echo "ERROR: PostgreSQL no está en ejecución." >&2
    exit 1
  fi

  db_user="$(container_env "$POSTGRES_CONTAINER" "POSTGRES_USER")"
  db_name="$(container_env "$POSTGRES_CONTAINER" "POSTGRES_DB")"
  db_password="$(container_env "$POSTGRES_CONTAINER" "POSTGRES_PASSWORD")"

  if [[ -z "$db_user" || -z "$db_name" || -z "$db_password" ]]; then
    echo "ERROR: no se pudieron leer las credenciales internas del contenedor PostgreSQL." >&2
    exit 1
  fi

  mkdir -p "$BACKUP_ROOT"
  timestamp="$(date -u +%Y%m%d_%H%M%S)"
  BACKUP_FILE="$BACKUP_ROOT/hipocratiq_predeploy_${timestamp}_${OLD_SHA:0:8}.dump"

  echo "Creando respaldo PostgreSQL host-level..."
  docker exec     -e "PGPASSWORD=$db_password"     "$POSTGRES_CONTAINER"     pg_dump       --host=127.0.0.1       --port=5432       --username="$db_user"       --dbname="$db_name"       --format=custom       --no-owner       --no-privileges     > "$BACKUP_FILE"

  if [[ ! -s "$BACKUP_FILE" ]]; then
    echo "ERROR: el respaldo PostgreSQL quedó vacío." >&2
    exit 1
  fi

  docker exec -i "$POSTGRES_CONTAINER" pg_restore --list < "$BACKUP_FILE" >/dev/null
  sha256sum "$BACKUP_FILE" > "$BACKUP_FILE.sha256"

  echo "Backup predeploy OK: $BACKUP_FILE"
  echo "SHA-256: $(awk '{print $1}' "$BACKUP_FILE.sha256")"
}

rollback_code() {
  set +e

  if [[ "$UPDATED" -eq 1 ]]; then
    echo "ROLLBACK: restaurando código a $OLD_SHA"
    git reset --hard "$OLD_SHA"

    mapfile -t rollback_services < <(available_runtime_services)
    compose up -d --build --no-deps "${rollback_services[@]}"
    compose exec -T app php artisan optimize:clear || true
  fi

  if [[ -n "$BACKUP_FILE" ]]; then
    echo "BACKUP DISPONIBLE: $BACKUP_FILE"
    echo "La base de datos NO se restaura automáticamente."
    echo "El restore debe ejecutarse de forma controlada si una migración lo requiere."
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

detect_compose_project
validate_running_runtime

echo "Proyecto Compose detectado: $COMPOSE_PROJECT"

git fetch --prune origin "$TARGET_BRANCH"
TARGET_SHA="$(git rev-parse "origin/$TARGET_BRANCH")"

if ! git merge-base --is-ancestor "$OLD_SHA" "$TARGET_SHA"; then
  echo "ERROR: origin/main no es fast-forward respecto al estado productivo actual." >&2
  exit 1
fi

echo "Destino: $TARGET_SHA"

create_host_backup

if [[ "$OLD_SHA" != "$TARGET_SHA" ]]; then
  git merge --ff-only "origin/$TARGET_BRANCH"
  UPDATED=1
fi

echo "Validando definición Docker Compose..."
compose config --quiet

for service in app queue-worker scheduler postgres redis; do
  if ! compose config --services | grep -qx "$service"; then
    echo "ERROR: el servicio requerido $service no existe en compose.yaml." >&2
    exit 1
  fi
done

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
echo "Proyecto Compose: $COMPOSE_PROJECT"
echo "Backup previo verificado: $BACKUP_FILE"
