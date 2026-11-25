-- Reparse only source CHECK definitions on empty temporary tables.
-- This is a PostgreSQL parser round-trip, not textual removal of casts or conditions.
-- Run exclusively on the synthetic CI source, never on production.
BEGIN;
SET LOCAL search_path TO pg_catalog;
CREATE TEMP TABLE ci_check_roundtrip_result (
    table_name text NOT NULL, constraint_name text NOT NULL,
    constraint_type text NOT NULL, validated boolean NOT NULL,
    definition text NOT NULL
) ON COMMIT DROP;
DO $roundtrip$
DECLARE
    item record;
    normalized text;
    normalized_valid boolean;
BEGIN
    FOR item IN
        SELECT c.relname AS table_name, k.conname AS constraint_name,
               k.convalidated AS validated,
               pg_get_constraintdef(k.oid, false) AS definition
          FROM pg_catalog.pg_constraint k
          JOIN pg_catalog.pg_class c ON c.oid = k.conrelid
          JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public' AND k.contype = 'c'
         ORDER BY c.relname, k.conname
    LOOP
        EXECUTE format('CREATE TEMP TABLE ci_check_probe (LIKE public.%I) ON COMMIT DROP', item.table_name);
        EXECUTE format('ALTER TABLE pg_temp.ci_check_probe ADD CONSTRAINT %I %s',
                       item.constraint_name, item.definition);
        SELECT pg_get_constraintdef(k.oid, false), k.convalidated
          INTO STRICT normalized, normalized_valid
          FROM pg_catalog.pg_constraint k
         WHERE k.conrelid = 'pg_temp.ci_check_probe'::regclass
           AND k.conname = item.constraint_name AND k.contype = 'c';
        IF normalized_valid IS DISTINCT FROM item.validated THEN
            RAISE EXCEPTION 'CHECK validity changed during parser round-trip';
        END IF;
        INSERT INTO pg_temp.ci_check_roundtrip_result
        VALUES (item.table_name, item.constraint_name, 'c', item.validated, normalized);
        DROP TABLE pg_temp.ci_check_probe;
    END LOOP;
END
$roundtrip$;
SELECT json_build_array(table_name, constraint_name, constraint_type, validated, definition)::text
  FROM pg_temp.ci_check_roundtrip_result
UNION ALL
SELECT json_build_array(c.relname, k.conname, k.contype, k.convalidated,
                       pg_get_constraintdef(k.oid, false))::text
  FROM pg_catalog.pg_constraint k
  JOIN pg_catalog.pg_class c ON c.oid = k.conrelid
  JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND k.contype <> 'c'
ORDER BY 1;
ROLLBACK;
