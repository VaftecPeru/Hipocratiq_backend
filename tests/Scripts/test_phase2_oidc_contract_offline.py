"""Synthetic negative tests for the offline IAM/OIDC production contract."""
import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "phase2_oidc_contract_offline", ROOT / "scripts/phase2_oidc_contract_offline.py"
)
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


class ContractTests(unittest.TestCase):
    def prepare(self, directory):
        root = Path(directory)
        (root / "infra/aws").mkdir(parents=True)
        (root / ".github/workflows").mkdir(parents=True)
        shutil.copy(ROOT / "infra/aws/prod-readonly-oidc-role.json",
                    root / "infra/aws/prod-readonly-oidc-role.json")
        shutil.copy(ROOT / ".github/workflows/production-version-evidence-readonly.yml",
                    root / ".github/workflows/production-version-evidence-readonly.yml")
        return root

    def test_approved_code_contract_is_not_live_aws_evidence(self):
        with tempfile.TemporaryDirectory() as directory:
            result = audit.assess(self.prepare(directory))
            self.assertIn("ADMIN_VERIFICATION_REQUIRED", result["status"])
            self.assertFalse(result["aws_live_role_verified"])
            self.assertFalse(result["release_approved"])

    def test_rejects_mutating_policy_and_wildcard_trust(self):
        for old, new in (
            ("ec2:DescribeSnapshots", "ec2:DeleteSnapshot"),
            (audit.SUB, "repo:VaftecPeru/Hipocratiq_backend:*"),
        ):
            with self.subTest(old=old), tempfile.TemporaryDirectory() as directory:
                root = self.prepare(directory)
                target = root / "infra/aws/prod-readonly-oidc-role.json"
                target.write_text(target.read_text().replace(old, new))
                with self.assertRaises(audit.ContractError):
                    audit.assess(root)

    def test_rejects_missing_branch_and_optin(self):
        for old in (
            "github.ref == 'refs/heads/develop'",
            "vars.HIPOCRATIQ_PROD_METADATA_ACK == 'READ_ONLY_PROD_METADATA'",
            "github.event_name == 'push'",
        ):
            with self.subTest(clause=old), tempfile.TemporaryDirectory() as directory:
                root = self.prepare(directory)
                target = root / ".github/workflows/production-version-evidence-readonly.yml"
                target.write_text(target.read_text().replace(old, "false"))
                with self.assertRaises(audit.ContractError):
                    audit.assess(root)


if __name__ == "__main__":
    unittest.main()
