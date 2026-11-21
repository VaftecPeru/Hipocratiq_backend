#!/usr/bin/env bash
# Negative-only tests: no valid approval or SHA is ever supplied.
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
