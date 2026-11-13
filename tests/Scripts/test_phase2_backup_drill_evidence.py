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

    def test_duplicate_json_fields_are_rejected_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "duplicate.json"
            raw = json.dumps(fixture())
            # A conflicting, repeated control must not be silently accepted.
            raw = raw.replace('"restore_command_succeeded": true',
                              '"restore_command_succeeded": false, "restore_command_succeeded": true')
            path.write_text(raw, encoding="utf-8")
            self.assertEqual(audit.main(["--attestation", str(path)]), 2)

    def test_duplicate_nested_json_keys_are_rejected(self):
        with self.assertRaisesRegex(audit.AttestationError, "DUPLICATE_EVIDENCE_FIELD"):
            json.loads('{"wrapper":{"flag":false,"flag":true}}',
                       object_pairs_hook=audit.reject_duplicate_keys)

    def test_nonstandard_json_constants_are_rejected(self):
        for constant in ("NaN", "Infinity", "-Infinity"):
            with self.subTest(constant=constant):
                with tempfile.TemporaryDirectory() as directory:
                    path = Path(directory) / "nonstandard.json"
                    raw = json.dumps(fixture())
                    raw = raw.replace('"schema_version": 1',
                                      '"schema_version": ' + constant)
                    path.write_text(raw, encoding="utf-8")
                    self.assertEqual(audit.main(["--attestation", str(path)]), 2)

    def test_nested_nonstandard_constant_is_rejected_before_schema_validation(self):
        with self.assertRaisesRegex(audit.AttestationError, "NON_STANDARD_JSON_CONSTANT"):
            json.loads('{"nested":{"value":NaN}}',
                       object_pairs_hook=audit.reject_duplicate_keys,
                       parse_constant=audit.reject_nonfinite_constant)

    def test_cli_invalid_input_fails_closed_without_echoing_contents(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "synthetic.json"
            path.write_text(json.dumps({"DB_PASSWORD": "fake"}))
            self.assertEqual(audit.main(["--attestation", str(path)]), 2)


if __name__ == "__main__":
    unittest.main()
