"""Fail-closed tests for the PostgreSQL #113 *synthetic-only* schema drill.

These tests never connect to PostgreSQL, Docker, AWS or clinical records.
"""
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "ci_postgres_schema_restore.sh"


class PostgresRestorePreflightTests(unittest.TestCase):
    def test_rejects_non_ci_and_non_synthetic_database_environments(self):
        valid = {
            "CI": "true",
            "APP_ENV": "testing",
            "DB_HOST": "127.0.0.1",
            "DB_DATABASE": "hipocratiq_integrated_ci",
            "DB_USERNAME": "hipocratiq_ci",
            "DB_PASSWORD": "synthetic-fixture-not-real",
        }
        scenarios = [
            {"CI": "false"},
            {"CI": ""},
            {"APP_ENV": "production"},
            {"APP_ENV": ""},
            {"DB_HOST": "localhost"},
            {"DB_HOST": "10.0.0.5"},
            {"DB_HOST": ""},
            {"DB_DATABASE": "hipocratiq_production"},
            {"DB_DATABASE": ""},
            {"DB_USERNAME": "postgres"},
            {"DB_USERNAME": ""},
        ]
        with tempfile.TemporaryDirectory(prefix="hipocratiq-p113-") as directory:
            temporary = Path(directory)
            marker = temporary / "external-command-invoked"
            fake_bin = temporary / "bin"
            fake_bin.mkdir()
            # Any accidental Docker invocation leaves evidence of a failed isolation gate.
            docker = fake_bin / "docker"
            docker.write_text(
                "#!/bin/sh\nprintf 'invoked' > " + repr(str(marker)) + "\nexit 91\n",
                encoding="utf-8",
            )
            docker.chmod(0o755)
            for changes in scenarios:
                with self.subTest(changes=changes):
                    marker.unlink(missing_ok=True)
                    env = dict(valid, **changes)
                    env["PATH"] = str(fake_bin) + os.pathsep + os.environ.get("PATH", "/usr/bin:/bin")
                    result = subprocess.run(
                        ["bash", str(SCRIPT)],
                        cwd=ROOT, env=env,
                        capture_output=True, text=True,
                        timeout=5, check=False,
                    )
                    self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
                    self.assertIn(
                        "restricted to isolated CI fixtures",
                        result.stdout + result.stderr,
                    )
                    self.assertFalse(marker.exists(), "Guard invoked Docker before refusing an unsafe environment")


    def test_rejects_unexpected_connected_database_before_mutation(self):
        with tempfile.TemporaryDirectory(prefix="hipocratiq-p113-identity-") as directory:
            temporary = Path(directory)
            marker = temporary / "docker-calls"
            docker = temporary / "docker"
            docker.write_text(
                "#!/bin/sh\n"
                "printf 'call\\n' >> " + repr(str(marker)) + "\n"
                "printf 'unexpected_database|hipocratiq_ci\\n'\n",
                encoding="utf-8",
            )
            docker.chmod(0o755)
            env = {
                "CI": "true", "APP_ENV": "testing", "DB_HOST": "127.0.0.1",
                "DB_DATABASE": "hipocratiq_integrated_ci", "DB_USERNAME": "hipocratiq_ci",
                "DB_PASSWORD": "synthetic-fixture-not-real",
                "PATH": str(temporary) + os.pathsep + os.environ.get("PATH", "/usr/bin:/bin"),
            }
            result = subprocess.run(
                ["bash", str(SCRIPT)], cwd=ROOT, env=env, capture_output=True,
                text=True, timeout=5, check=False,
            )
            self.assertEqual(result.returncode, 2)
            self.assertIn("identity does not match isolated CI fixtures", result.stdout)
            self.assertEqual(marker.read_text(encoding="utf-8"), "call\n")


    def test_schema_drill_reports_stage_and_rejects_changed_catalog(self):
        # Simulated Docker/psql responses exercise the real shell control flow.
        fake_docker = """#!/usr/bin/env python3
import os
import sys
import json
args = sys.argv[1:]
if 'pg_dump' in args:
    print('synthetic dump')
elif 'pg_restore' in args:
    sys.stdin.read()
elif 'createdb' in args:
    pass
elif 'psql' in args:
    db = args[args.index('-d') + 1]
    query = args[args.index('-c') + 1]
    restored = db == 'hipocratiq_schema_restore_ci'
    if 'current_database()' in query:
        print(db + '|hipocratiq_ci')
    elif 'CREATE TABLE ci_restore_sentinel' in query:
        pass
    elif 'pg_get_constraintdef' in query:
        rows = [['table_a', 'check_a', 'c', True, 'constraint-changed' if restored and os.environ.get('MISMATCH') == 'constraints' else 'constraint-original'], ['table_b', 'pk_b', 'p', True, 'PRIMARY KEY (id)']]
        if restored and os.environ.get('MISMATCH') == 'reordered':
            rows.reverse()
        if restored and os.environ.get('MISMATCH') == 'missing':
            rows.pop()
        if restored and os.environ.get('MISMATCH') == 'validity':
            rows[0][-2] = False
        for row in rows:
            print(json.dumps(row))
    elif 'pg_get_indexdef' in query:
        rows = [['table_a', 'index_a', True, 'index-changed' if restored and os.environ.get('MISMATCH') == 'indexes' else 'index-original'], ['table_b', 'index_b', True, 'index-other']]
        if restored and os.environ.get('MISMATCH') == 'reordered':
            rows.reverse()
        for row in rows:
            print(json.dumps(row))
    elif 'count(*)' in query:
        print('12' if 'pg_tables' in query else '2')
    elif 'SELECT tablename' in query:
        print('migrations')
    elif 'SELECT migration, batch' in query:
        print('migration|1')
    elif 'SELECT id, marker' in query:
        print('1|synthetic-ci-only')
        print('2|synthetic-ci-only')
    else:
        sys.exit(91)
else:
    sys.exit(92)
"""
        with tempfile.TemporaryDirectory(prefix="hipocratiq-p113-catalog-") as directory:
            docker = Path(directory) / "docker"
            docker.write_text(fake_docker, encoding="utf-8")
            docker.chmod(0o755)
            env = {
                "CI": "true", "APP_ENV": "testing", "DB_HOST": "127.0.0.1",
                "DB_DATABASE": "hipocratiq_integrated_ci", "DB_USERNAME": "hipocratiq_ci",
                "DB_PASSWORD": "synthetic-fixture-not-real",
                "PATH": directory + os.pathsep + os.environ.get("PATH", "/usr/bin:/bin"),
            }
            for mismatch in ("", "reordered", "constraints", "indexes", "missing", "validity"):
                with self.subTest(mismatch=mismatch):
                    result = subprocess.run(
                        ["bash", str(SCRIPT)], cwd=ROOT, env=dict(env, MISMATCH=mismatch),
                        capture_output=True, text=True, timeout=20, check=False,
                    )
                    if mismatch not in ("", "reordered"):
                        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
                        self.assertIn("stage=restore-" + ("indexes" if mismatch == "indexes" else "constraints"), result.stderr)
                        self.assertIn("CATALOG_MISMATCH", result.stderr)
                        if mismatch == "validity":
                            self.assertIn("validity_changes=1", result.stderr)
                        if mismatch == "missing":
                            self.assertIn("missing=1 extra=0", result.stderr)
                        self.assertNotIn("LARAVEL_SCHEMA_RESTORE=PASS", result.stdout)
                        self.assertNotIn("constraint-changed", result.stderr)
                        self.assertNotIn("index-changed", result.stderr)
                    else:
                        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                        self.assertIn("POSTGRES17_CONSTRAINT_AND_INDEX_DEFINITIONS=VERIFIED", result.stdout)
                        self.assertIn("LARAVEL_SCHEMA_RESTORE=PASS", result.stdout)


if __name__ == "__main__":
    unittest.main()
