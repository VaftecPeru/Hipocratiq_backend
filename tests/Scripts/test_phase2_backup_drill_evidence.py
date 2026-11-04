"""Synthetic regression tests: never perform a real PostgreSQL restore."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "phase2_backup_drill_evidence", ROOT / "scripts/phase2_backup_drill_evidence.py"
)
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


def fixture():
    return {"schema_version": 1, "target_environment": "isolated_nonproduction",
            **{name: True for name in audit.REQUIRED_TRUE}}


class DrillEvidenceTests(unittest.TestCase):
    def test_even_full_self_attestation_is_not_recovery_certification(self):
        result = audit.assess(fixture())
        self.assertIn("INDEPENDENT_REVIEW_PENDING", result["status"])
        self.assertFalse(result["recovery_certified"])
        self.assertFalse(result["release_approved"])
        self.assertFalse(result["database_connection_made"])

    def test_missing_any_control_is_no_go(self):
        for control in audit.REQUIRED_TRUE:
            with self.subTest(control=control):
                data = fixture()
                data[control] = False
                result = audit.assess(data)
                self.assertIn("NO_GO", result["status"])
                self.assertEqual(result["required_controls_attested_count"],
                                 len(audit.REQUIRED_TRUE) - 1)

    def test_rejects_production_destination_and_added_sensitive_fields(self):
        data = fixture()
        data["target_environment"] = "production"
        with self.assertRaises(audit.AttestationError):
            audit.assess(data)
        data = fixture()
        data["DB_PASSWORD"] = "synthetic-placeholder"
        with self.assertRaises(audit.AttestationError):
            audit.assess(data)

    def test_rejects_incomplete_or_non_boolean_attestation(self):
        data = fixture()
        data.pop("backup_sha256_verified")
        with self.assertRaises(audit.AttestationError):
            audit.assess(data)
        data = fixture()
        data["restore_command_succeeded"] = "true"
        with self.assertRaises(audit.AttestationError):
            audit.assess(data)

    def test_cli_invalid_input_fails_closed_without_echoing_contents(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "synthetic.json"
            path.write_text(json.dumps({"DB_PASSWORD": "fake"}))
            self.assertEqual(audit.main(["--attestation", str(path)]), 2)


if __name__ == "__main__":
    unittest.main()
