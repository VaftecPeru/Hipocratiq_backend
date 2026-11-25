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
pg=(docker run --rm -i --network host -e "PGPASSWORD=${DB_PASSWORD:?Missing synthetic DB password}" postgres:17-alpine)
sql() { "${pg[@]}" psql -X -qAt -v ON_ERROR_STOP=1 -h 127.0.0.1 -U "$user" -d "$1" -c "$2"; }

# Verify the connected database identity before any write, not just environment variables.
if [[ "$(sql "$source_db" "SELECT current_database() || '|' || current_user")" != "$source_db|$user" ]]; then
  echo "::error::Connected PostgreSQL identity does not match isolated CI fixtures."
  exit 2
fi

# This sentinel exists only on the ephemeral CI database, never production.
sql "$source_db" "CREATE TABLE ci_restore_sentinel (id integer PRIMARY KEY, marker text NOT NULL); INSERT INTO ci_restore_sentinel VALUES (1, 'synthetic-ci-only'), (2, 'synthetic-ci-only');"

table_inventory() { sql "$1" "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='public' ORDER BY tablename COLLATE \"C\""; }
migration_inventory() { sql "$1" "SELECT migration, batch FROM public.migrations ORDER BY id"; }
sentinel_inventory() { sql "$1" "SELECT id, marker FROM public.ci_restore_sentinel ORDER BY id"; }

# Compare structural definitions, not merely the number or names of tables.
constraint_inventory() { sql "$1" "SELECT json_build_array(c.relname, k.conname, k.contype, k.convalidated, pg_get_constraintdef(k.oid)) FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' ORDER BY c.relname COLLATE \"C\", k.conname COLLATE \"C\""; }
index_inventory() { sql "$1" "SELECT json_build_array(t.relname, i.relname, x.indisvalid, pg_get_indexdef(i.oid)) FROM pg_index x JOIN pg_class t ON t.oid=x.indrelid JOIN pg_class i ON i.oid=x.indexrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname='public' ORDER BY t.relname COLLATE \"C\", i.relname COLLATE \"C\""; }

expected_constraints="$(constraint_inventory "$source_db")"
expected_indexes="$(index_inventory "$source_db")"
[[ -n "$expected_constraints" && -n "$expected_indexes" ]]
expected_table_inventory="$(table_inventory "$source_db")"
expected_migration_inventory="$(migration_inventory "$source_db")"
expected_sentinel_inventory="$(sentinel_inventory "$source_db")"
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
"${pg[@]}" pg_restore -h 127.0.0.1 -U "$user" -d "$restore_db" --single-transaction --exit-on-error < "$tmp"
[[ "$dump_hash" == "$(sha256sum "$tmp" | cut -d' ' -f1)" ]]

actual_tables="$(sql "$restore_db" "SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public'")"
actual_migrations="$(sql "$restore_db" "SELECT count(*) FROM public.migrations")"

[[ "$actual_tables" == "$expected_tables" ]]
[[ "$actual_migrations" == "$expected_migrations" ]]
[[ "$(table_inventory "$restore_db")" == "$expected_table_inventory" ]]
[[ "$(migration_inventory "$restore_db")" == "$expected_migration_inventory" ]]
[[ "$(constraint_inventory "$restore_db")" == "$expected_constraints" ]]
[[ "$(index_inventory "$restore_db")" == "$expected_indexes" ]]
echo "POSTGRES17_CONSTRAINT_AND_INDEX_DEFINITIONS=VERIFIED"
[[ "$expected_sentinel_inventory" == $'1|synthetic-ci-only\n2|synthetic-ci-only' ]]
[[ "$(sentinel_inventory "$restore_db")" == "$expected_sentinel_inventory" ]]
echo "LARAVEL_SCHEMA_RESTORE=PASS; tables=$actual_tables; migrations=$actual_migrations"
echo "POSTGRES17_DUMP_SHA256_VERIFIED=true; synthetic_only=true"
echo "PRODUCTION_BACKUP_RESTORED=false; RELEASE_GO=NOT_GRANTED"
