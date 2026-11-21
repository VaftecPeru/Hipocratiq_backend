"""Offline regression: AWS inventory never starts from PRs or unauthorized pushes."""
from pathlib import Path
import re
import unittest

WORKFLOW = Path(__file__).resolve().parents[2] / ".github/workflows/uat-aws-readonly-inventory.yml"


class AwsInventoryWorkflowActivationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = WORKFLOW.read_text(encoding="utf-8")

    def test_develop_push_is_not_path_filtered(self):
        # Without this, setting up IAM/Environment cannot produce a real job
        # until an unrelated PR happens to touch one of a few file paths.
        self.assertRegex(
            self.text,
            r"(?m)^  push:\n    branches: \[develop\]\n  workflow_dispatch:",
        )

    def test_real_aws_job_is_explicitly_gated_and_not_run_on_pr(self):
        job = self.text.split("\n  aws-uat-inventory:", 1)[1]
        first = job.split("\n    steps:", 1)[0]
        for expected in (
            "github.ref == 'refs/heads/develop'",
            "vars.HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED == 'true'",
            "github.event_name == 'workflow_dispatch'",
            "github.event_name == 'push'",
            "vars.HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH == 'true'",
            "environment: hipocratiq-uat-readonly",
            "id-token: write",
        ):
            with self.subTest(requirement=expected):
                self.assertIn(expected, first)

    def test_workflow_enforces_protection_before_oidc(self):
        body = self.text.split("\n  aws-uat-inventory:", 1)[1]
        first_guard = body.index("python3 scripts/uat_aws_inventory_readonly.py --preflight")
        branch_guard = body.index("python3 scripts/uat_github_protected_develop_preflight.py")
        oidc_assume = body.index("aws-actions/configure-aws-credentials@v4")
        real_inventory = body.index("python3 scripts/uat_aws_inventory_readonly.py\n")
        self.assertLess(first_guard, branch_guard)
        self.assertLess(branch_guard, oidc_assume)
        self.assertLess(oidc_assume, real_inventory)

    def test_no_aws_permissions_for_mock_job(self):
        offline = self.text.split("\n  offline-safety:", 1)[1].split(
            "\n  aws-uat-inventory:", 1
        )[0]
        self.assertNotIn("id-token: write", offline)
        self.assertNotIn("aws-actions/configure-aws-credentials", offline)
        self.assertIn("tests/Scripts/test_uat_aws_inventory_workflow_activation.py", offline)


if __name__ == "__main__":
    unittest.main()
