#!/usr/bin/env bash
# Verify a PostgreSQL 17 backup/restore of the ACTUAL migrated Laravel schema
# using disposable synthetic GitHub Actions data only. No AWS/PHI access.
set -Eeuo pipefail

if [[ "${CI:-}" != true || "${APP_ENV:-}" != testing ||
      "${DB_HOST:-}" != 127.0.0.1 || "${DB_DATABASE:-}" != hipocratiq_integrated_ci ||
      "${DB_USERNAME:-}" != hipocratiq_ci ]]; then
  echo "::error::Schema restore is restricted to isolated CI fixtures."
  exit 2
fi

source_db="$DB_DATABASE"
restore_db="hipocratiq_schema_restore_ci"
user="$DB_USERNAME"
pg=(docker run --rm --network host -e "PGPASSWORD=${DB_PASSWORD:?Missing synthetic DB password}" postgres:17-alpine)
sql() { "${pg[@]}" psql -X -qAt -v ON_ERROR_STOP=1 -h 127.0.0.1 -U "$user" -d "$1" -c "$2"; }

# This sentinel exists only on the ephemeral CI database, never production.
sql "$source_db" "CREATE TABLE ci_restore_sentinel (id integer PRIMARY KEY, marker text NOT NULL); INSERT INTO ci_restore_sentinel VALUES (1, 'synthetic-ci-only'), (2, 'synthetic-ci-only');"

expected_tables="$(sql "$source_db" "SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public'")"
expected_migrations="$(sql "$source_db" "SELECT count(*) FROM public.migrations")"
[[ "$expected_tables" =~ ^[0-9]+$ && "$expected_tables" -gt 10 ]]
[[ "$expected_migrations" =~ ^[0-9]+$ && "$expected_migrations" -gt 0 ]]

tmp="$(mktemp -t hipocratiq-ci-pg17-XXXXXXXX)"
trap 'rm -f "$tmp"' EXIT
"${pg[@]}" pg_dump -h 127.0.0.1 -U "$user" -d "$source_db" --format=custom > "$tmp"
test -s "$tmp"
dump_hash="$(sha256sum "$tmp" | cut -d' ' -f1)"
[[ "$dump_hash" =~ ^[a-f0-9]{64}$ ]]

"${pg[@]}" createdb -h 127.0.0.1 -U "$user" "$restore_db"
"${pg[@]}" pg_restore -h 127.0.0.1 -U "$user" -d "$restore_db" --exit-on-error < "$tmp"
[[ "$dump_hash" == "$(sha256sum "$tmp" | cut -d' ' -f1)" ]]

actual_tables="$(sql "$restore_db" "SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public'")"
actual_migrations="$(sql "$restore_db" "SELECT count(*) FROM public.migrations")"
sentinel="$(sql "$restore_db" "SELECT count(*) || ':' || count(DISTINCT id) || ':' || min(marker) FROM ci_restore_sentinel")"

[[ "$actual_tables" == "$expected_tables" ]]
[[ "$actual_migrations" == "$expected_migrations" ]]
[[ "$sentinel" == '2:2:synthetic-ci-only' ]]
echo "LARAVEL_SCHEMA_RESTORE=PASS; tables=$actual_tables; migrations=$actual_migrations"
echo "POSTGRES17_DUMP_SHA256_VERIFIED=true; synthetic_only=true"
echo "PRODUCTION_BACKUP_RESTORED=false; RELEASE_GO=NOT_GRANTED"
