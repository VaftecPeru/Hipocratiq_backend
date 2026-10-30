"""Offline tests: synthetic values only, never authentic credentials."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[2] / "scripts" / "audit_public_git_history.py"
SPEC = importlib.util.spec_from_file_location("redacted_git_audit", SOURCE)
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


class RedactedGitHistoryAuditTest(unittest.TestCase):
    def test_identifies_history_sensitive_files_without_contents(self):
        self.assertEqual(audit.classify_path(".env.production"), "TRACKED_ENV_FILE")
        self.assertEqual(audit.classify_path("backend/.env"), "TRACKED_ENV_FILE")
        self.assertEqual(audit.classify_path("config/id_ed25519"), "POTENTIAL_PRIVATE_KEY")
        self.assertEqual(audit.classify_path("backup/uat.dump"), "POSSIBLE_DATABASE_EXPORT")
        self.assertEqual(audit.classify_path("database/schema/pgsql-schema.sql"), None)
        self.assertEqual(audit.classify_path("docs/readme.md"), None)

    def test_examples_are_scanned_but_not_automatically_classified_as_exposed(self):
        self.assertEqual(audit.classify_path(".env.example"), "EXAMPLE_TEMPLATE")
        self.assertEqual(audit.template_sensitive_assignments(
            b"# DB_PASSWORD=fake\nDB_PASSWORD=\nREDIS_PASSWORD=''\n"
        ), 0)

    def test_historial_nonempty_example_is_detected_and_values_never_exposed(self):
        self.assertEqual(audit.template_sensitive_assignments(
            b"DB_PASSWORD=synthetic_only_123\nREDIS_PASSWORD=\"synthetic_only_456\"\n"
        ), 2)
        self.assertEqual(audit.template_sensitive_assignments(
            b"DB_PASSWORD=synthetic_only_123\nDB_PASSWORD=synthetic_only_123\n"
        ), 1)

    def test_historical_security_keys_cannot_be_omitted_from_audit(self):
        from scripts.check_public_env_template import SENSITIVE

        self.assertTrue(SENSITIVE.issubset(audit.SECRET_ASSIGNMENTS))
        self.assertEqual(
            audit.template_sensitive_assignments(
                b"OTP_HASH_KEY=synthetic-otp-only\\n"
                b"HIPOCRATIQ_UAT_ACCESS_PASSWORD=synthetic-uat-only\\n"
                b"WHATSAPP_ACCESS_TOKEN=synthetic-token-only\\n"
                b"GOOGLE_CLIENT_SECRET=synthetic-oauth-only\\n"
            ),
            4,
        )
        self.assertEqual(
            audit.template_sensitive_assignments(
                b"OTP_HASH_KEY=\\nHIPOCRATIQ_UAT_ACCESS_PASSWORD=''\\n"
            ),
            0,
        )

    def test_tracked_example_history_still_warns_after_current_file_is_clean(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            def command(*args):
                p = subprocess.run(["git", "-C", str(root), *args],
                                   capture_output=True, check=True)
                return p.stdout
            command("init", "-q")
            command("config", "user.email", "ci@example.invalid")
            command("config", "user.name", "Synthetic QA")
            example = root / ".env.example"
            fake = "synthetic_for_test_only_do_not_use"
            example.write_text("DB_PASSWORD=" + fake + "\n")
            command("add", ".env.example")
            command("commit", "-qm", "synthetic old example")
            example.write_text("DB_PASSWORD=\n")
            command("add", ".env.example")
            command("commit", "-qm", "synthetic cleared example")

            report = audit.run_audit(root)
            self.assertEqual(report["status"], "PRIVATE_REVIEW_REQUIRED")
            self.assertEqual(report["signal_counts"][
                "HISTORICAL_NONEMPTY_TEMPLATE_CREDENTIALS"], 1)
            evidence = json.dumps(report)
            self.assertNotIn(fake, evidence)
            self.assertNotIn(".env.example", evidence)
            self.assertFalse(report["secret_values_recorded"])
            self.assertFalse(report["sensitive_paths_recorded"])

    def test_does_not_claim_security_clearance_for_an_empty_signal_list(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            def command(*args):
                subprocess.run(["git", "-C", str(root), *args], check=True,
                               capture_output=True)
            command("init", "-q")
            command("config", "user.email", "ci@example.invalid")
            command("config", "user.name", "Synthetic QA")
            (root / "README.md").write_text("synthetic\n")
            command("add", "README.md")
            command("commit", "-qm", "safe fixture")
            report = audit.run_audit(root)
            self.assertEqual(report["status"], "NO_SCANNER_SIGNAL_NOT_CLEARANCE")


if __name__ == "__main__":
    unittest.main()
