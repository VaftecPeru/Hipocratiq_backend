#!/usr/bin/env bash
# Negative preflight tests, followed by an isolated mocked rollback regression.
# Complete synthetic approvals are used ONLY with a mock-only PATH and temp workspace.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RESULT="$(mktemp)"
trap 'rm -f "$RESULT"' EXIT

run_must_block() {
  local name="$1"
  shift
  if "$@" >"$RESULT" 2>&1; then
    echo "ERROR: unsafe deploy preflight accepted $name" >&2
    exit 1
  fi
  if ! grep -Fq "ERROR NO-GO:" "$RESULT"; then
    echo "ERROR: unexpected failure path for $name" >&2
    sed -n '1,10p' "$RESULT" >&2
    exit 1
  fi
  echo "NO-GO fail-closed PASS: $name"
}

run_must_block "missing-all-approvals" \
  env -u RELEASE_APPROVAL -u RELEASE_APPROVED_SHA \
  -u RELEASE_CHANGE_ID -u UAT_RESTORE_EVIDENCE \
  -u BACKUP_AT_REST_VERIFIED \
  bash "$ROOT/scripts/deploy-production.sh"

run_must_block "invalid-SHA-even-with-other-approvals" \
  env RELEASE_APPROVAL=APPROVED_FOR_PRODUCTION \
      RELEASE_APPROVED_SHA=not-a-release-sha \
      RELEASE_CHANGE_ID=qa-only-negative-test \
      UAT_RESTORE_EVIDENCE=qa-only-negative-test \
      BACKUP_AT_REST_VERIFIED=true \
  bash "$ROOT/scripts/deploy-production.sh"

run_must_block "missing-UAT-restore-evidence" \
  env RELEASE_APPROVAL=APPROVED_FOR_PRODUCTION \
      RELEASE_APPROVED_SHA=0000000000000000000000000000000000000000 \
      RELEASE_CHANGE_ID=qa-only-negative-test \
      UAT_RESTORE_EVIDENCE= \
      BACKUP_AT_REST_VERIFIED=true \
  bash "$ROOT/scripts/deploy-production.sh"

run_must_block "no-backup-encryption-attestation" \
  env RELEASE_APPROVAL=APPROVED_FOR_PRODUCTION \
      RELEASE_APPROVED_SHA=0000000000000000000000000000000000000000 \
      RELEASE_CHANGE_ID=qa-only-negative-test \
      UAT_RESTORE_EVIDENCE=qa-only-negative-test \
      BACKUP_AT_REST_VERIFIED=false \
  bash "$ROOT/scripts/deploy-production.sh"

echo "DEPLOY_PREFLIGHT_NEGATIVE_CASES=PASS; no AWS, Docker, migrations, or Git modifications."


# Regression: an explicit health-check exit must invoke rollback, using only
# mocks. No real Git, Docker, network, AWS, data, or host directories are used.
(
  set -Eeuo pipefail
  TMP="$(mktemp -d)"
  trap 'rm -rf "$TMP"' EXIT
  mkdir -p "$TMP/bin" "$TMP/repo"
  for tool in bash mkdir chmod grep awk sha256sum basename date; do
    ln -s "$(command -v "$tool")" "$TMP/bin/$tool"
  done
  cat >"$TMP/bin/git" <<'MOCK'
#!/bin/bash
case "$1 $2" in
  'branch --show-current') echo main;;
  'status --porcelain') ;;
  'rev-parse HEAD')
    if grep -q '^reset ' "$MOCK_TRACE"; then echo "$MOCK_OLD"
    elif grep -q '^merge ' "$MOCK_TRACE" && [[ "${MOCK_FINAL_SHA_FAIL:-}" == "true" ]]; then exit 87
    elif grep -q '^merge ' "$MOCK_TRACE"; then echo "$MOCK_NEW"
    else echo "$MOCK_OLD"
    fi;;
  'rev-parse origin/main') echo "$MOCK_NEW";;
  'merge-base --is-ancestor'|'fetch --prune') ;;
  'merge --ff-only') printf 'merge %s\n' "$3" >> "$MOCK_TRACE"; [[ "$3" == "$MOCK_NEW" ]];;
  'reset --hard') printf 'reset %s\n' "$3" >> "$MOCK_TRACE"; [[ "$3" == "$MOCK_OLD" ]];;
  *) exit 89;;
esac
MOCK
  cat >"$TMP/bin/docker" <<'MOCK'
#!/bin/bash
case "$1" in
  inspect)
    case "$3" in
      *State.Running*) echo true;;
      *State.Health*) echo healthy;;
      *compose.project*) echo synthetic;;
      *compose.service*) echo '';;
      *) exit 89;;
    esac;;
  compose)
    shift 5
    case "$1 $2" in
      'config --services') printf 'app\npostgres\nfrontend\n';;
      'ps -q') echo "mock-$3";;
      'exec -T'|'run --rm'|'build app'|'up -d'|'logs --tail=120') ;;
      *) exit 89;;
    esac;;
  exec)
    if [[ "$2" == '-e' ]]; then
      case "$5" in
        psql) echo 1;;
        pg_dump) echo 'synthetic-backup';;
        *) exit 89;;
      esac
    else
      echo synthetic
    fi;;
  *) exit 89;;
esac
MOCK
  printf '#!/bin/bash\nexit 1\n' >"$TMP/bin/curl"
  printf '#!/bin/bash\nexit 0\n' >"$TMP/bin/flock"
  printf '#!/bin/bash\nexit 0\n' >"$TMP/bin/sleep"
  chmod 700 "$TMP/bin/git" "$TMP/bin/docker" "$TMP/bin/curl" "$TMP/bin/flock" "$TMP/bin/sleep"
  OLD=1111111111111111111111111111111111111111
  NEW=2222222222222222222222222222222222222222
  rc=0
  env -i PATH="$TMP/bin" MOCK_OLD="$OLD" MOCK_NEW="$NEW" MOCK_TRACE="$TMP/trace" \
    PRODUCTION_DIR="$TMP/repo" COMPOSE_PROJECT=synthetic LOCK_FILE="$TMP/lock" \
    BACKUP_ROOT="$TMP/backups" HEALTH_URL=https://invalid.invalid/ \
    RELEASE_APPROVAL=APPROVED_FOR_PRODUCTION RELEASE_APPROVED_SHA="$NEW" \
    RELEASE_CHANGE_ID=synthetic-test UAT_RESTORE_EVIDENCE=synthetic-test \
    BACKUP_AT_REST_VERIFIED=true WEB_UAT_EVIDENCE=synthetic-test \
    "$TMP/bin/bash" "$ROOT/scripts/deploy-production.sh" >"$TMP/log" 2>&1 || rc=$?
  [[ "$rc" -eq 1 ]]
  grep -Fxq "merge $NEW" "$TMP/trace"
  grep -Fxq "reset $OLD" "$TMP/trace"
  grep -Fq 'ROLLBACK:' "$TMP/log"
  grep -Fq 'no quedó disponible' "$TMP/log"
  if grep -Fq 'DEPLOY BACKEND OK' "$TMP/log"; then
    echo 'Unexpected successful release' >&2
    exit 1
  fi
  echo 'MOCKED_EXPLICIT_HEALTH_FAILURE_ROLLBACK=PASS'

  # Regression: if final SHA capture fails after all health checks pass,
  # EXIT must preserve failure status, roll the app back, and never report success.
  printf '#!/bin/bash\\nexit 0\\n' >"$TMP/bin/curl"
  rc=0
  env -i PATH="$TMP/bin" MOCK_OLD="$OLD" MOCK_NEW="$NEW" \\
    MOCK_FINAL_SHA_FAIL=true MOCK_TRACE="$TMP/final-sha-trace" \\
    PRODUCTION_DIR="$TMP/repo" COMPOSE_PROJECT=synthetic LOCK_FILE="$TMP/lock" \\
    BACKUP_ROOT="$TMP/backups" HEALTH_URL= \\
    RELEASE_APPROVAL=APPROVED_FOR_PRODUCTION RELEASE_APPROVED_SHA="$NEW" \\
    RELEASE_CHANGE_ID=synthetic-test UAT_RESTORE_EVIDENCE=synthetic-test \\
    BACKUP_AT_REST_VERIFIED=true WEB_UAT_EVIDENCE=synthetic-test \\
    "$TMP/bin/bash" "$ROOT/scripts/deploy-production.sh" >"$TMP/final-sha-log" 2>&1 || rc=$?
  [[ "$rc" -ne 0 ]]
  grep -Fxq "merge $NEW" "$TMP/final-sha-trace"
  grep -Fxq "reset $OLD" "$TMP/final-sha-trace"
  grep -Fq 'ROLLBACK:' "$TMP/final-sha-log"
  if grep -Fq 'DEPLOY BACKEND OK' "$TMP/final-sha-log"; then
    echo 'Unexpected successful release after final SHA failure' >&2
    exit 1
  fi
  echo 'MOCKED_FINAL_SHA_FAILURE_ROLLBACK=PASS'
)
