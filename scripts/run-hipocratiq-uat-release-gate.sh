#!/usr/bin/env bash
set -Eeuo pipefail

BACKEND_QA="${BACKEND_QA:-/srv/hipocratiq/qa-20261013/backend-b109}"
FRONTEND_QA="${FRONTEND_QA:-/srv/hipocratiq/qa-20261016/frontend-b108}"
BACKEND_BRANCH="${BACKEND_BRANCH:-develop}"
FRONTEND_BRANCH="${FRONTEND_BRANCH:-develop}"
ENV_FILE="${ENV_FILE:-/tmp/hipocratiq-b109.env}"
NETWORK="${NETWORK:-hipocratiq-b109-net}"
POSTGRES="${POSTGRES:-hipocratiq-b109-postgres}"
TARGET_COUNTRY="${TARGET_COUNTRY:-MX}"
EVIDENCE_ROOT="${EVIDENCE_ROOT:-/srv/hipocratiq/qa-20261016/evidence-release}"
UAT_BACKEND_URL="${HIPOCRATIQ_UAT_BACKEND_URL:-}"
SUPPORT_PORTAL_URL="${SUPPORT_PORTAL_URL:-https://hipocratiq.com}"

# El preflight debe completarse ANTES de iniciar Docker, migraciones, pruebas o SMS.
# Fail-closed: el entorno UAT y la URL de pruebas se configuran antes de ejecutar.
if [[ -z "$UAT_BACKEND_URL" ]]; then
  printf '%s\\n' "ERROR: configura HIPOCRATIQ_UAT_BACKEND_URL con un backend UAT HTTPS antes de iniciar." >&2
  exit 1
fi
if [[ ! "$UAT_BACKEND_URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?(/[^?#[:space:]]*)?$ ]]; then
  printf '%s\\n' "ERROR: HIPOCRATIQ_UAT_BACKEND_URL debe ser una URL HTTPS válida sin credenciales ni parámetros." >&2
  exit 1
fi
_backend_host="${UAT_BACKEND_URL#https://}"
_backend_host="${_backend_host%%/*}"
_backend_host="${_backend_host%%:*}"
if [[ ! "${_backend_host,,}" =~ (^|[.-])(uat|qa|staging)([.-]|$) ]]; then
  printf '%s\\n' "ERROR: se exige un hostname UAT/QA/staging. Producción no está permitida." >&2
  exit 1
fi


timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="${EVIDENCE_ROOT}/${timestamp}"
SUMMARY="${RUN_DIR}/release-gate-summary.txt"

mkdir -p "$RUN_DIR"

log() {
  printf '%s\n' "$*" | tee -a "$SUMMARY"
}

die() {
  log "ERROR: $*"
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "falta el comando requerido: $1"
}

for cmd in git docker grep sed awk sha256sum; do
  require_command "$cmd"
done

[[ "$TARGET_COUNTRY" == "MX" ]] || die "Este release gate está fijado a MX como mercado principal."

[[ -d "$BACKEND_QA/.git" || -f "$BACKEND_QA/.git" ]] || die "No existe backend QA: $BACKEND_QA"
[[ -d "$FRONTEND_QA/.git" || -f "$FRONTEND_QA/.git" ]] || die "No existe frontend QA: $FRONTEND_QA"
[[ -f "$ENV_FILE" ]] || die "No existe env UAT: $ENV_FILE"

docker network inspect "$NETWORK" >/dev/null 2>&1 || die "No existe red Docker UAT: $NETWORK"
docker inspect "$POSTGRES" >/dev/null 2>&1 || die "No existe PostgreSQL UAT: $POSTGRES"

log "======================================================"
log " HIPOCRATIQ - RELEASE GATE UAT INTEGRAL"
log " País principal: MX (+52)"
log "======================================================"

log ""
log "=== 1/8 ACTUALIZAR BACKEND B-109 ==="

cd "$BACKEND_QA"
[[ -z "$(git status --porcelain)" ]] || {
  git status --short | tee -a "$SUMMARY"
  die "backend QA contiene cambios locales"
}

git fetch origin "refs/heads/${BACKEND_BRANCH}:refs/remotes/origin/${BACKEND_BRANCH}"
git merge --ff-only "refs/remotes/origin/${BACKEND_BRANCH}"

BACKEND_SHA="$(git rev-parse HEAD)"
log "Backend SHA: $BACKEND_SHA"

log ""
log "=== 2/8 POSTGRESQL UAT ==="

if [[ "$(docker inspect -f '{{.State.Running}}' "$POSTGRES")" != "true" ]]; then
  docker start "$POSTGRES" >/dev/null
fi

ready=0
for _ in $(seq 1 30); do
  if docker exec "$POSTGRES" pg_isready -U hipocratiq_b109 -d hipocratiq_b109_uat >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[[ "$ready" -eq 1 ]] || die "PostgreSQL UAT no está disponible"
log "PostgreSQL UAT: PASS"

log ""
log "=== 3/8 BACKEND QA AUTOMATIZADO ==="

docker run --rm   --network "$NETWORK"   --user "$(id -u):$(id -g)"   --env-file "$ENV_FILE"   -e HOME=/tmp   -e XDG_CONFIG_HOME=/tmp/xdg   -v "$BACKEND_QA:/app"   -w /app   backend-app   sh -lc '
    set -Eeuo pipefail
    mkdir -p /tmp/xdg

    php -l app/Console/Commands/RunAwsOtpUatGate.php
    vendor/bin/pint --test

    php artisan test --compact tests/Feature/Auth/AwsOtpReadinessTest.php
    php artisan test --compact tests/Feature/Auth/AwsNotifyOtpFlowTest.php
    php artisan test --compact tests/Feature/Auth/OtpAuthenticationTest.php
    php artisan test --compact tests/Feature/Auth/LoginTest.php
    php artisan test --compact tests/Feature/Auth/AwsOtpUatGateCommandTest.php
  ' 2>&1 | tee -a "$SUMMARY"

log "Backend QA automatizado: PASS"

log ""
log "=== 4/8 AWS NOTIFY MX + SMS + SANCTUM ==="
log "Se solicitarán de forma oculta un número +52 y el OTP recibido."

docker run --rm -it   --network "$NETWORK"   --user "$(id -u):$(id -g)"   --env-file "$ENV_FILE"   -e HOME=/tmp   -e XDG_CONFIG_HOME=/tmp/xdg   -v "$BACKEND_QA:/app"   -w /app   backend-app   php artisan otp:aws-uat-gate --target-country=MX

log "B-109 AWS Notify MX/Sanctum: PASS"

log ""
log "=== 5/8 ACTUALIZAR FRONTEND B-108 ==="

cd "$FRONTEND_QA"
[[ -z "$(git status --porcelain)" ]] || {
  git status --short | tee -a "$SUMMARY"
  die "frontend QA contiene cambios locales"
}

git fetch origin "refs/heads/${FRONTEND_BRANCH}:refs/remotes/origin/${FRONTEND_BRANCH}"
git merge --ff-only "refs/remotes/origin/${FRONTEND_BRANCH}"

FRONTEND_SHA="$(git rev-parse HEAD)"
log "Frontend SHA: $FRONTEND_SHA"

log ""
log "=== 6/8 QA WEB B-108 ==="

VALIDATION_LOG_DIR="$RUN_DIR/web"   bash scripts/validate-vps.sh 2>&1 | tee -a "$SUMMARY"

log "Web QA: PASS"

log ""
log "=== 7/8 QA MOBILE + APK/AAB UAT ==="

VALIDATION_LOG_DIR="$RUN_DIR/mobile" BACKEND_URL="$UAT_BACKEND_URL" SUPPORT_PORTAL_URL="$SUPPORT_PORTAL_URL"   bash scripts/validate-mobile-vps.sh 2>&1 | tee -a "$SUMMARY"

APK="$(find "$RUN_DIR/mobile" -type f -name 'HIPOCRATIQ-validation.apk' -print | sort | tail -n 1)"
AAB="$(find "$RUN_DIR/mobile" -type f -name 'HIPOCRATIQ-validation.aab' -print | sort | tail -n 1)"

[[ -n "$APK" && -s "$APK" ]] || die "No se generó APK UAT"
[[ -n "$AAB" && -s "$AAB" ]] || die "No se generó AAB UAT"

log "APK: $APK"
log "AAB: $AAB"
sha256sum "$APK" "$AAB" | tee -a "$SUMMARY"

log ""
log "=== 8/8 RESULTADO ==="
log "B-109: READY TO CLOSE"
log "B-108: QA PASS"
log "Mercado principal móvil: MX (+52)"
log "APK/AAB UAT: GENERADOS"
log "Backend UAT: $UAT_BACKEND_URL"
log "Backend SHA: $BACKEND_SHA"
log "Frontend SHA: $FRONTEND_SHA"
log ""
log "NO SE REALIZÓ MERGE."
log "Estado siguiente: READY_FOR_USER_TESTS_AND_DEVELOP_AUTHORIZATION"
log "Resumen: $SUMMARY"
