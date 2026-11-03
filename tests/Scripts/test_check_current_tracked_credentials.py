"""Offline current-tree security tests: synthetic strings only."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[2] / "scripts/check_current_tracked_credentials.py"
SPEC = importlib.util.spec_from_file_location("tracked_guard", SOURCE)
guard = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(guard)


class CurrentTrackedCredentialsTest(unittest.TestCase):
    def build_repo(self, root, files):
        subprocess.run(["git", "-C", str(root), "init", "-q"], check=True,
                       capture_output=True)
        for path, value in files.items():
            target = root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(value)
        subprocess.run(["git", "-C", str(root), "add", "-A"], check=True,
                       capture_output=True)

    def test_redacted_examples_and_safe_source_pass_without_granting_clearance(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            self.build_repo(root, {
                ".env.example": b"DB_PASSWORD=\nREDIS_PASSWORD=''\n",
                "src/main.php": b"<?php echo 'synthetic';\n",
                "docs/README.md": b"STRIPE_SECRET=REDACTED_EXAMPLE_NOT_A_CREDENTIAL\n",
            })
            report = guard.scan(root)
            self.assertEqual(report["status"], "NO_CURRENT_TREE_SCANNER_SIGNAL_NOT_CLEARANCE")
            self.assertEqual(report["tracked_files"], 3)
            self.assertFalse(report["security_clearance_granted"])

    def test_detects_runtime_env_and_private_key_without_disclosing_file_names(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            self.build_repo(root, {
                ".env.production": b"DB_PASSWORD=synthetic_value_only\n",
                "config/signing.key": b"synthetic_no_real_key\n",
                "README.md": b"safe",
            })
            report = guard.scan(root)
            self.assertEqual(report["status"], "NO_GO")
            self.assertEqual(report["findings_by_category"]["TRACKED_RUNTIME_ENV"], 1)
            self.assertEqual(
                report["findings_by_category"]["TRACKED_SENSITIVE_EXPORT_OR_KEY"], 1
            )
            self.assertNotIn(".env.production", json.dumps(report))
            self.assertNotIn("synthetic_value_only", json.dumps(report))

    def test_detects_full_token_shapes_without_logging_values(self):
        synthetic = (
            b"prefix " + b"AKIA" + b"A" * 16 + b"\n"
            + b"ghp_" + b"b" * 36 + b"\n"
            + b"sk_live_" + b"C" * 24 + b"\n"
            + b"-----BEGIN " + b"PRIVATE KEY-----\n"
        )
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            self.build_repo(root, {"src/app.php": synthetic})
            report = guard.scan(root)
            for category in (
                "AWS_ACCESS_KEY_ID_FORMAT", "GITHUB_TOKEN_FORMAT",
                "STRIPE_LIVE_SECRET_FORMAT", "PRIVATE_KEY_PEM_HEADER",
            ):
                self.assertEqual(report["findings_by_category"][category], 1)
            self.assertNotIn("ghp_" + "b" * 36, json.dumps(report))
            self.assertFalse(report["filenames_or_credentials_recorded"])

    def test_source_string_concatenations_are_not_leaks(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            self.build_repo(root, {
                "tests/mock.py": b'part1 = "AKIA"\npart2 = "A" * 16\n',
            })
            self.assertFalse(guard.scan(root)["findings_by_category"])

    def test_refuses_incomplete_repository_or_unscanned_large_source(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            with self.assertRaises(guard.CurrentTreeSecurityError):
                guard.scan(root)
            self.build_repo(root, {
                "src/large.py": b"x" * (guard.MAX_TEXT_BYTES + 1),
            })
            report = guard.scan(root)
            self.assertEqual(report["status"], "NO_GO")
            self.assertEqual(
                report["findings_by_category"]["OVERSIZED_TEXT_FILE_NOT_SCANNED"], 1
            )


if __name__ == "__main__":
    unittest.main()
