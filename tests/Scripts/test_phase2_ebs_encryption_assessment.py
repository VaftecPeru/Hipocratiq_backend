"""Synthetic tests for EBS encryption evaluation without AWS credentials."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "phase2_ebs_encryption_assessment", ROOT / "scripts/phase2_ebs_encryption_assessment.py"
)
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


def fixture(encrypted=1, total=1, with_kms=1):
    return {
        "gate": "HIPOCRATIQ_PRODUCTION_EBS_SNAPSHOT_METADATA_ONLY",
        "status": "READ_ONLY_METADATA_COLLECTED_RECOVERY_UNVERIFIED",
        "aws_account_and_instance_verified": True,
        "attached_ebs_volumes": total,
        "ebs_encrypted_volumes": encrypted,
        "ebs_kms_key_reference_present_count": with_kms,
        "all_attached_volumes_encrypted": encrypted == total,
        "snapshot_created": False,
        "production_changed": False,
        "security_or_release_approval_granted": False,
    }


class EncryptionAssessmentTests(unittest.TestCase):
    def test_unencrypted_volume_requires_approval_and_not_modification(self):
        result = audit.assess(fixture(encrypted=0, with_kms=0))
        self.assertEqual(result["unencrypted_volumes"], 1)
        self.assertIn("MIGRATION_APPROVAL_REQUIRED", result["status"])
        self.assertFalse(result["remediation_executed"])
        self.assertFalse(result["security_or_release_approval_granted"])

    def test_encrypted_metadata_is_not_production_clearance(self):
        result = audit.assess(fixture())
        self.assertIn("HUMAN_VERIFICATION_REQUIRED", result["status"])
        self.assertFalse(result["security_or_release_approval_granted"])
        self.assertFalse(result["backup_restored"])

    def test_missing_key_reference_demands_review(self):
        result = audit.assess(fixture(with_kms=0))
        self.assertIn("INCOMPLETE", result["status"])

    def test_fail_closed_on_invalid_or_production_mutated_report(self):
        for field, value in (
            ("ebs_encrypted_volumes", 2),
            ("all_attached_volumes_encrypted", False),
            ("production_changed", True),
            ("snapshot_created", True),
            ("aws_account_and_instance_verified", False),
            ("attached_ebs_volumes", 0),
            ("ebs_encrypted_volumes", True),
        ):
            with self.subTest(field=field):
                data = fixture()
                data[field] = value
                with self.assertRaises(audit.AssessmentError):
                    audit.assess(data)

    def test_rejects_missing_report_fields(self):
        data = fixture()
        data.pop("gate")
        with self.assertRaises(audit.AssessmentError):
            audit.assess(data)


if __name__ == "__main__":
    unittest.main()
