#!/usr/bin/env bash
# Non-destructive HTTP smoke for an isolated Laravel API and synthetic database.
set -Eeuo pipefail

BASE_URL="http://127.0.0.1:8000"
FRONT_ORIGIN="http://localhost:3000"
DENIED_ORIGIN="https://unauthorized-origin.invalid"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

require_status() {
  local expected="$1"
  local path="$2"
  local dest="$3"
  local received
  received="$(curl --silent --show-error --connect-timeout 5 --max-time 15 \
    --header 'Accept: application/json' \
    --output "$dest" --write-out '%{http_code}' "$BASE_URL$path")"
  if [[ "$received" != "$expected" ]]; then
    echo "::error::HTTP $path returned $received; expected $expected." >&2
    exit 1
  fi
  echo "HTTP $path: $received"
}

echo "=== HIPOCRATIQ isolated Laravel HTTP gate ==="
require_status 200 "/api/health/live" "$WORK_DIR/live.json"
php -r '
$p = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if (($p["success"] ?? false) !== true || ($p["data"]["status"] ?? null) !== "ok") {
    fwrite(STDERR, "Invalid live response\n"); exit(1);
}' "$WORK_DIR/live.json"

require_status 200 "/api/health/ready" "$WORK_DIR/ready.json"
php -r '
$p = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$d = $p["data"]["dependencies"] ?? [];
if (($p["success"] ?? false) !== true || ($d["database"] ?? null) !== "ok"
    || ($d["redis"] ?? null) !== "ok" || ($d["storage"] ?? null) !== "ok") {
    fwrite(STDERR, "DB/Redis/storage readiness failed\n"); exit(1);
}' "$WORK_DIR/ready.json"
echo "Dependencies: PostgreSQL + Redis + storage PASS"

for path in "/api/v1/auth/me" "/api/v1/fhir/Patient/1"; do
  require_status 401 "$path" "$WORK_DIR/guest.json"
  php -r '
  $p = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  if (($p["code"] ?? null) !== "UNAUTHENTICATED") {
      fwrite(STDERR, "Guest response contract did not deny access\n"); exit(1);
  }' "$WORK_DIR/guest.json"
done
echo "Unauthenticated clinical access: DENIED"

cors_preflight() {
  local origin="$1"
  local headers="$2"
  curl --silent --show-error --connect-timeout 5 --max-time 15 \
    --request OPTIONS \
    --header "Origin: $origin" \
    --header "Access-Control-Request-Method: POST" \
    --header "Access-Control-Request-Headers: Content-Type" \
    --dump-header "$headers" \
    --output /dev/null \
    "$BASE_URL/api/v1/auth/otp/request"
}
cors_preflight "$FRONT_ORIGIN" "$WORK_DIR/allowed-headers.txt"
if ! tr -d '\r' < "$WORK_DIR/allowed-headers.txt" | grep -Fqi "access-control-allow-origin: $FRONT_ORIGIN"; then
  echo "::error::Expected UAT frontend origin to be allowed by Laravel CORS" >&2
  exit 1
fi
cors_preflight "$DENIED_ORIGIN" "$WORK_DIR/denied-headers.txt"
if grep -qi '^access-control-allow-origin:' "$WORK_DIR/denied-headers.txt"; then
  echo "::error::Unexpected Access-Control-Allow-Origin for unauthorized site" >&2
  exit 1
fi
echo "CORS allowlist and untrusted-origin denial: PASS"
echo "UAT_HTTP_GATE=PASS (isolated loopback HTTP, no external HTTPS, no PHI, no AWS)"
