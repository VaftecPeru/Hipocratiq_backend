#!/usr/bin/env bash
# P0-102: GitHub-hosted ephemeral-runner scan; ONLY an age-encrypted report can be uploaded.
# The encrypted report is for restricted private triage; it is NOT a security release approval.
set -Eeuo pipefail
set +x
umask 077

fail() { echo "$1" >&2; exit 2; }

[[ "${GITHUB_ACTIONS:-}" == 'true' ]] || fail 'P0_102_ENCRYPTED_AUDIT=REFUSED_OUTSIDE_GITHUB_ACTIONS'
case "${GITHUB_REPOSITORY:-}" in
  VaftecPeru/Hipocratiq_backend|VaftecPeru/Hipocratiq_Frontend) ;;
  *) fail 'P0_102_ENCRYPTED_AUDIT=REFUSED_UNEXPECTED_REPOSITORY' ;;
esac
[[ "${GITHUB_REF:-}" == 'refs/heads/security/p0-102-full-history-gitleaks-20261113' ]] ||
  fail 'P0_102_ENCRYPTED_AUDIT=REFUSED_UNAPPROVED_BRANCH'
[[ "${GITHUB_EVENT_NAME:-}" == 'push' || "${GITHUB_EVENT_NAME:-}" == 'workflow_dispatch' ]] ||
  fail 'P0_102_ENCRYPTED_AUDIT=REFUSED_EVENT'
[[ -n "${AGE_PUBLIC_RECIPIENT:-}" && "$AGE_PUBLIC_RECIPIENT" == age1* ]] ||
  fail 'P0_102_ENCRYPTED_AUDIT=RECIPIENT_MISSING_OR_INVALID; ask the custodian to set Actions variable HIPOCRATIQ_P0_102_AGE_RECIPIENT'
[[ -n "${RUNNER_TEMP:-}" && -n "${GITHUB_WORKSPACE:-}" && -n "${GITHUB_OUTPUT:-}" ]] ||
  fail 'P0_102_ENCRYPTED_AUDIT=MISSING_RUNNER_CONTEXT'
command -v age >/dev/null 2>&1 || fail 'P0_102_ENCRYPTED_AUDIT=AGE_UNAVAILABLE'
command -v docker >/dev/null 2>&1 || fail 'P0_102_ENCRYPTED_AUDIT=DOCKER_UNAVAILABLE'
command -v git >/dev/null 2>&1 || fail 'P0_102_ENCRYPTED_AUDIT=GIT_UNAVAILABLE'

# Validate recipient without revealing it; private keys MUST NOT be on the runner.
printf 'recipient check\n' | age --encrypt --recipient "$AGE_PUBLIC_RECIPIENT" --output /dev/null >/dev/null 2>&1 ||
  fail 'P0_102_ENCRYPTED_AUDIT=INVALID_AGE_RECIPIENT'

cd -- "$GITHUB_WORKSPACE"
git fetch --no-tags --prune origin '+refs/heads/*:refs/remotes/origin/*' >/dev/null 2>&1 ||
  fail 'P0_102_ENCRYPTED_AUDIT=BRANCH_REFS_UNAVAILABLE'
git fetch --tags origin >/dev/null 2>&1 || fail 'P0_102_ENCRYPTED_AUDIT=TAG_REFS_UNAVAILABLE'
[[ "$(git rev-parse --is-shallow-repository)" == 'false' ]] ||
  fail 'P0_102_ENCRYPTED_AUDIT=SHALLOW_HISTORY_NO_GO'

private_dir="$(mktemp -d "$RUNNER_TEMP/p0-102-private-XXXXXXXX")"
chmod 700 -- "$private_dir"
cleanup() { rm -rf -- "$private_dir"; }
trap cleanup EXIT

scan_result='SCANNER_ERROR'
set +e
docker run --rm --network none --user "$(id -u):$(id -g)" \
  -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/repo \
  -v "$GITHUB_WORKSPACE:/repo:ro" -v "$private_dir:/private:rw" -w /repo \
  ghcr.io/gitleaks/gitleaks@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f \
  git --no-banner '--log-opts=--all' --report-format json --report-path /private/findings.json . \
  > "$private_dir/scanner.log" 2>&1
scan_code=$?
set -e

case "$scan_code" in
  0)
    scan_result='NO_SIGNAL_NOT_SECURITY_CLEARANCE'
    # Gitleaks may not create a JSON report if no findings exist.
    if [[ ! -s "$private_dir/findings.json" ]]; then printf '[]\n' > "$private_dir/findings.json"; fi
    ;;
  1)
    scan_result='PRIVATE_TRIAGE_REQUIRED_NO_GO'
    [[ -s "$private_dir/findings.json" ]] || fail 'P0_102_ENCRYPTED_AUDIT=SCANNER_REPORT_MISSING_NO_GO'
    ;;
  *) fail 'P0_102_ENCRYPTED_AUDIT=SCANNER_FAILED_CLOSED_NO_GO' ;;
esac

# Manifest and any candidate values stay INSIDE the encrypted archive.
printf 'repository=%s\nhead_sha=%s\nrun_id=%s\nscan_result=%s\n' \
  "$GITHUB_REPOSITORY" "${GITHUB_SHA:-unknown}" "${GITHUB_RUN_ID:-unknown}" "$scan_result" \
  > "$private_dir/manifest.txt"

output_dir="$RUNNER_TEMP/p0-102-encrypted-artifacts"
mkdir -p -- "$output_dir"
chmod 700 -- "$output_dir"
archive="$output_dir/p0-102-gitleaks-report.tar.age"
# tar streams to age: NO plaintext tarball or plaintext artifact is created.
tar -C "$private_dir" -cf - manifest.txt findings.json scanner.log \
  | age --encrypt --recipient "$AGE_PUBLIC_RECIPIENT" --output "$archive" \
    > /dev/null 2>&1 || fail 'P0_102_ENCRYPTED_AUDIT=ENCRYPTION_FAILED_NO_GO'
[[ -s "$archive" ]] || fail 'P0_102_ENCRYPTED_AUDIT=ENCRYPTED_ARTIFACT_EMPTY_NO_GO'
chmod 600 -- "$archive"

# Erase unencrypted report on the ephemeral runner before the artifact upload step.
cleanup
trap - EXIT
printf 'encrypted_ready=true\nscan_result=%s\n' "$scan_result" >> "$GITHUB_OUTPUT"
echo 'P0_102_ENCRYPTED_AUDIT=CIPHERTEXT_READY_FOR_UPLOAD'
echo "P0_102_SECURITY_STATUS=$scan_result"
