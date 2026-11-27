#!/usr/bin/env bash
# P0-102: Run ONLY on an authorized, isolated workstation with full Git history.
# The report may contain credential values. Never upload it to GitHub or shared storage.
set -Eeuo pipefail
umask 077

if [[ -n "${GITHUB_ACTIONS:-}" || -n "${CI:-}" ]]; then
  echo "REFUSED: private triage is prohibited on CI/public runners" >&2
  exit 2
fi
if [[ $# -ne 1 || -z "$1" ]]; then
  echo "Usage: bash scripts/security/p0-102-private-gitleaks.sh /absolute/private/report-directory" >&2
  exit 2
fi
command -v git >/dev/null || exit 2
command -v docker >/dev/null || exit 2
root="$(git rev-parse --show-toplevel)"
[[ "$(git -C "$root" rev-parse --is-shallow-repository)" == false ]] || {
  echo "REFUSED: shallow Git history" >&2; exit 2;
}
report_root="$1"
[[ "$report_root" == /* ]] || { echo "REFUSED: report path must be absolute" >&2; exit 2; }
mkdir -p -- "$report_root"
report_root="$(cd -- "$report_root" && pwd -P)"
case "$report_root/" in
  "$root/"*) echo "REFUSED: output cannot live inside Git checkout" >&2; exit 2 ;;
esac
[[ -d "$report_root" && ! -L "$report_root" ]] || exit 2
session="$(mktemp -d "$report_root/p0-102-XXXXXXXX")"
chmod 700 "$session"
echo "PRIVATE_REPORT_DIRECTORY=$session"
echo "The output may contain secrets. Restrict access and destroy it under your retention policy."
set +e
docker run --rm --network none --user "$(id -u):$(id -g)" \
  -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory \
  -e GIT_CONFIG_VALUE_0=/repo \
  -v "$root:/repo:ro" -v "$session:/private:rw" -w /repo \
  ghcr.io/gitleaks/gitleaks@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f \
  git --no-banner '--log-opts=--all' --report-format json \
  --report-path /private/findings.json . > "$session/scan.log" 2>&1
code=$?
set -e
case "$code" in
  0) echo "NO_FINDINGS_NOT_SECURITY_CLEARANCE" ;;
  1) echo "FINDINGS_REQUIRE_PRIVATE_TRIAGE" ;;
  *) echo "SCANNER_ERROR_REVIEW_PRIVATE_LOG" ;;
esac
exit "$code"
