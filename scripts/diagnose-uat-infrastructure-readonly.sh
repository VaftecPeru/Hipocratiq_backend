#!/usr/bin/env bash
# HIPOCRATIQ: metadata-only inspection of the historical B-109 UAT installation.
# READ-ONLY: never inspect environment values, databases, logs or clinical data.
# No network calls, Git fetch, Docker exec/start, SMS, migrations or filesystem writes.
set -uo pipefail

if [[ "${1:-}" == "--help" ]]; then
  cat <<'HELP'
READ-ONLY INVENTORY — metadata only; no infrastructure mutations.
Usage: bash scripts/diagnose-uat-infrastructure-readonly.sh
Optional overrides:
  HIPOCRATIQ_QA_BACKEND  /srv/hipocratiq/qa-20261013/backend-b109
  HIPOCRATIQ_QA_FRONTEND /srv/hipocratiq/qa-20261016/frontend-b108
  HIPOCRATIQ_QA_PG       hipocratiq-b109-postgres
  HIPOCRATIQ_QA_NETWORK  hipocratiq-b109-net
  HIPOCRATIQ_QA_ENV_FILE /tmp/hipocratiq-b109.env

Reads only filesystem metadata, Git HEAD, Docker metadata, disk/RAM and port LISTEN.
It does not connect to the API, database, AWS, SMS or production services.
Never pipe sensitive .env contents, Docker Config.Env, patient data or secrets.
Run only after explicitly confirming the host is an authorized UAT inspection target.
HELP
  exit 0
fi

if [[ "$#" -ne 0 ]]; then
  printf 'ERROR: Unsupported parameters; use --help.\n' >&2
  exit 2
fi

qa_backend="${HIPOCRATIQ_QA_BACKEND:-/srv/hipocratiq/qa-20261013/backend-b109}"
qa_frontend="${HIPOCRATIQ_QA_FRONTEND:-/srv/hipocratiq/qa-20261016/frontend-b108}"
qa_pg="${HIPOCRATIQ_QA_PG:-hipocratiq-b109-postgres}"
qa_network="${HIPOCRATIQ_QA_NETWORK:-hipocratiq-b109-net}"
qa_env="${HIPOCRATIQ_QA_ENV_FILE:-/tmp/hipocratiq-b109.env}"

blockers=0
warnings=0
ok() { printf 'PASS | %s\n' "$*"; }
block() { printf 'BLOCKED | %s\n' "$*"; blockers=$((blockers + 1)); }
warn() { printf 'UNVERIFIED | %s\n' "$*"; warnings=$((warnings + 1)); }

printf '\n=== HIPOCRATIQ B-109 UAT | READ-ONLY INFRASTRUCTURE INVENTORY ===\n'
printf 'Inspection policy: no clinical data, secrets, AWS calls or system changes.\n'
printf 'Timestamp UTC: '
date -u '+%Y-%m-%dT%H:%M:%SZ'
printf 'Host: '
hostname -s 2>/dev/null || printf 'unknown\n'
printf 'Kernel: '
uname -sr 2>/dev/null || printf 'unknown\n'

# All user-selected paths must stay outside the two production source checkouts.
safe_qa_checkout() {
  local selected="$1" canonical
  canonical="$(realpath -m -- "$selected" 2>/dev/null)" || return 1
  [[ "$canonical" == /srv/hipocratiq/qa-*/* ||
     "$canonical" == /srv/hipocratiq-ci/* ]] || return 1
  [[ "$canonical" != /srv/hipocratiq/backend* &&
     "$canonical" != /srv/hipocratiq/frontend* ]] || return 1
  [[ -d "$canonical" ]] || return 1
  return 0
}

printf '\n=== 1. QA checkouts (Git HEAD only; no fetch or checkout) ===\n'
for entry in "Backend|$qa_backend" "Frontend|$qa_frontend"; do
  label="${entry%%|*}"
  path="${entry#*|}"
  if ! safe_qa_checkout "$path"; then
    block "$label: QA path missing, outside allowlist or points at production; cannot inspect."
    continue
  fi
  if ! command -v git >/dev/null 2>&1; then
    warn "$label: Git unavailable."
    continue
  fi
  sha="$(git -C "$path" rev-parse --verify HEAD 2>/dev/null)" || sha=""
  branch="$(git -C "$path" branch --show-current 2>/dev/null)" || branch=""
  if [[ "$sha" =~ ^[0-9a-f]{40}$ ]]; then
    ok "$label checkout present, commit $sha, branch ${branch:-detached}."
  else
    block "$label checkout exists but HEAD could not be verified."
  fi
done

printf '\n=== 2. Host capacity and network listeners (metadata only) ===\n'
if command -v df >/dev/null 2>&1 && [[ -d /srv/hipocratiq ]]; then
  df -h /srv/hipocratiq | awk 'NR==2 {print "DISK | Capacity="$2" Used="$3" Available="$4" Utilization="$5}'
else
  warn "Disk capacity not measured."
fi
if command -v free >/dev/null 2>&1; then
  free -h | awk '/^Mem:/ {print "MEMORY | Total="$2" Available="$7}'
else
  warn "Memory capacity not measured."
fi
if command -v ss >/dev/null 2>&1; then
  ss -H -ltn 2>/dev/null |
    awk '$4 ~ /:(443|8000|3000|5432|6379)$/ {print "LISTEN | "$4" (service identity not verified)"}' || true
else
  warn "Port listeners not measured."
fi

printf '\n=== 3. QA environment file permissions (NEVER content) ===\n'
if [[ -f "$qa_env" ]]; then
  permissions="$(stat -c '%a' -- "$qa_env" 2>/dev/null)" || permissions=""
  if [[ "$permissions" =~ ^[0-7]{3,4}$ ]]; then
    printf 'ENV_METADATA | Exists; mode %s (file values were not read)\n' "$permissions"
    if (( (8#$permissions & 7) != 0 )); then
      block "UAT env file grants permissions to other users; restrict access."
    else
      ok "UAT env metadata present and no 'other' bits set."
    fi
  else
    warn "UAT env file exists but file mode was not readable."
  fi
else
  block "UAT env file not present at configured QA path."
fi

printf '\n=== 4. Docker metadata and network isolation (NO exec) ===\n'
if ! command -v docker >/dev/null 2>&1 || ! docker info --format '{{.ServerVersion}}' >/dev/null 2>&1; then
  block "Docker CLI/daemon unavailable for a read-only metadata check."
else
  ok "Docker daemon metadata accessible."
  # Keep the QA target names explicit; production container lookup is metadata-only.
  if [[ ! "$qa_pg" =~ ^hipocratiq-[a-z0-9-]*(qa|uat|b109)[a-z0-9-]*$ ]]; then
    block "PostgreSQL target name is not explicitly QA/UAT."
  elif docker container inspect "$qa_pg" >/dev/null 2>&1; then
    pg_state="$(docker inspect --format '{{.State.Status}}' "$qa_pg" 2>/dev/null)" || pg_state="unknown"
    pg_health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}not-configured{{end}}' "$qa_pg" 2>/dev/null)" || pg_health="unknown"
    printf 'PG_METADATA | QA container %s state=%s health=%s\n' "$qa_pg" "$pg_state" "$pg_health"
    [[ "$pg_state" == running ]] && ok "UAT PostgreSQL container is running." || block "UAT PostgreSQL container is not running."
    [[ "$pg_health" == healthy ]] && ok "UAT PostgreSQL reports healthy." || warn "UAT PostgreSQL health is not confirmed healthy."
    qa_mount="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Source}}{{end}}{{end}}' "$qa_pg" 2>/dev/null)" || qa_mount=""
    if docker container inspect back-api-postgres >/dev/null 2>&1; then
      prod_mount="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Source}}{{end}}{{end}}' back-api-postgres 2>/dev/null)" || prod_mount=""
      if [[ -n "$qa_mount" && -n "$prod_mount" ]]; then
        if [[ "$qa_mount" == "$prod_mount" ]]; then
          block "QA and production PostgreSQL share the same data mount!"
        else
          ok "QA and production PostgreSQL data mount paths differ."
        fi
      else
        warn "Could not verify both PostgreSQL data mounts; manual isolation check required."
      fi
    else
      warn "Known production DB container name not found; data-volume comparison unavailable."
    fi
  else
    block "Expected UAT PostgreSQL container not found."
  fi

  if [[ ! "$qa_network" =~ ^hipocratiq-[a-z0-9-]*(qa|uat|b109)[a-z0-9-]*$ ]]; then
    block "Network target name is not explicitly QA/UAT."
  elif docker network inspect "$qa_network" >/dev/null 2>&1; then
    members="$(docker network inspect --format '{{range .Containers}}{{.Name}} {{end}}' "$qa_network" 2>/dev/null)" || members=""
    printf 'QA_NETWORK | %s | members: %s\n' "$qa_network" "${members:-none}"
    contaminated=0
    for member in $members; do
      if [[ "$member" == back-api-* || "$member" == front-web-app ]]; then
        block "Production-named container $member is attached to the QA network."
        contaminated=1
      fi
    done
    [[ "$contaminated" -eq 0 ]] && ok "No recognized production container attached to QA network."
  else
    block "Expected dedicated UAT Docker network not found."
  fi
fi

printf '\n=== 5. Read-only diagnostic outcome ===\n'
printf 'Findings: BLOCKED=%d UNVERIFIED=%d\n' "$blockers" "$warnings"
printf 'No network probes, login, OTP, database queries or mutations were executed.\n'
printf 'TLS/DNS/API/SMS/migrations/restore/clinical UAT: NOT VERIFIED by this inventory.\n'
printf 'PRODUCTION_RELEASE_GATE=NO_GO\n'
[[ "$blockers" -eq 0 ]]
