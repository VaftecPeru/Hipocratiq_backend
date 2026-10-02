#!/usr/bin/env bash
set -euo pipefail

URL="${1:-http://127.0.0.1:8000/api/v1/auth/register/clinic/plans}"
REQUESTS="${PERF_REQUESTS:-25}"
MAX_AVG="${PERF_MAX_AVG_SECONDS:-0.750}"
MAX_P95="${PERF_MAX_P95_SECONDS:-1.500}"

samples="$(mktemp)"
sorted="$(mktemp)"
trap 'rm -f "$samples" "$sorted"' EXIT

for warmup in 1 2 3; do
  body="$(mktemp)"
  result="$(curl --silent --show-error --output "$body" --write-out '%{http_code} %{time_total}' "$URL")"
  status="${result%% *}"

  if [[ "$status" != "200" ]]; then
    echo "Performance warmup failed: HTTP $status on request $warmup." >&2
    cat "$body" >&2 || true
    rm -f "$body"
    exit 1
  fi

  rm -f "$body"
done

for ((i=1; i<=REQUESTS; i++)); do
  body="$(mktemp)"
  result="$(curl --silent --show-error --output "$body" --write-out '%{http_code} %{time_total}' "$URL")"
  status="${result%% *}"
  elapsed="${result#* }"

  if [[ "$status" != "200" ]]; then
    echo "Performance smoke failed: HTTP $status on request $i." >&2
    cat "$body" >&2 || true
    rm -f "$body"
    exit 1
  fi

  rm -f "$body"
  printf '%s\n' "$elapsed" >> "$samples"
done

sort -n "$samples" > "$sorted"
count="$(wc -l < "$sorted" | tr -d ' ')"
rank="$(( (95 * count + 99) / 100 ))"
p95="$(sed -n "${rank}p" "$sorted")"
avg="$(awk '{sum += $1} END {if (NR == 0) print "0"; else printf "%.6f", sum / NR}' "$samples")"

echo "HIPOCRATIQ performance smoke"
echo "URL: $URL"
echo "Requests: $count"
echo "Average: ${avg}s (max ${MAX_AVG}s)"
echo "P95: ${p95}s (max ${MAX_P95}s)"

awk -v value="$avg" -v maximum="$MAX_AVG" 'BEGIN { exit(value <= maximum ? 0 : 1) }' || {
  echo "Average latency exceeded the Phase 4 threshold." >&2
  exit 1
}

awk -v value="$p95" -v maximum="$MAX_P95" 'BEGIN { exit(value <= maximum ? 0 : 1) }' || {
  echo "P95 latency exceeded the Phase 4 threshold." >&2
  exit 1
}
