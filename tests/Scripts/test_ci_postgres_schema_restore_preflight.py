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


if __name__ == "__main__":
    unittest.main()
