"""Offline regressions for GitHub Actions AWS UAT configuration diagnosis."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "uat_aws_actions_config_preflight.py"
spec = importlib.util.spec_from_file_location("uat_config", SCRIPT)
uat_config = importlib.util.module_from_spec(spec)
spec.loader.exec_module(uat_config)

SAFE = {
    "GITHUB_REPOSITORY": "VaftecPeru/Hipocratiq_backend",
    "HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED": "true",
    "HIPOCRATIQ_UAT_AWS_ACCOUNT_ID": "123456789012",
    "HIPOCRATIQ_UAT_AWS_REGION": "us-east-2",
    "HIPOCRATIQ_UAT_INSTANCE_ID": "i-0123456789abcdef0",
    "HIPOCRATIQ_PROD_INSTANCE_ID": "i-abcdef12345678901",
    "HIPOCRATIQ_UAT_VPC_ID": "vpc-0123456789abcdef0",
    "HIPOCRATIQ_PROD_VPC_ID": "vpc-abcdef12345678901",
    "HIPOCRATIQ_UAT_READONLY_ROLE_ARN":
        "arn:aws:iam::123456789012:role/HIPOCRATIQ-UAT-ReadOnly",
    "HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH": "false",
}


class OfflineUatReadinessTests(unittest.TestCase):
    def test_offline_config_workflow_refreshes_on_develop_only_and_never_uses_oidc(self):
        workflow = (SCRIPT.parents[2] / ".github/workflows"
                    / "uat-aws-configuration-diagnostic.yml").read_text(
                        encoding="utf-8")
        self.assertIn("  push:\n    branches: [develop]\n", workflow)
        self.assertIn("  pull_request:\n    branches: [develop]\n", workflow)
        self.assertIn("  workflow_dispatch:\n", workflow)
        self.assertIn("name: AWS UAT configuration diagnostic (offline, no credentials)", workflow)
        self.assertNotIn("id-token: write", workflow)
        self.assertNotIn("aws-actions/configure-aws-credentials", workflow)
        self.assertNotIn("environment: hipocratiq-uat-readonly", workflow)
        self.assertIn("hipocratiq-aws-uat-config-offline-only", workflow)

    def test_absent_variables_block_real_aws_with_clear_keys(self):
        report = uat_config.check_variables({"GITHUB_REPOSITORY": SAFE["GITHUB_REPOSITORY"]})
        self.assertEqual(report["status"], "BLOCKED_NO_AWS_DIAGNOSTIC")
        self.assertEqual(len(report["configuration_checks"]), 8)
        self.assertEqual(report["aws_api_requests"], 0)
        self.assertFalse(report["cloud_resources_verified"])
        self.assertFalse(report["actual_oidc_assumption_verified"])
        self.assertEqual(report["visibility_scope"], "workflow_without_github_environment")
        self.assertIn("may exist in GitHub Environment scope", report["visibility_warning"])

    def test_syntactically_valid_configuration_does_not_claim_aws_success(self):
        report = uat_config.check_variables(SAFE)
        self.assertEqual(report["status"], "CONFIG_SYNTAX_PASS_NOT_AWS_VERIFIED")
        self.assertEqual(report["blocker_codes"], [])
        self.assertTrue(report["manual_preconditions_unverified"])
        self.assertFalse(report["cloud_resources_verified"])

    def test_disabled_opt_in_blocks(self):
        for value in ("false", "TRUE", "", "1"):
            with self.subTest(value=value):
                x = uat_config.check_variables(dict(SAFE, HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED=value))
                self.assertIn("BLOCKED", x["status"])

    def test_wrong_role_and_account_cannot_pass(self):
        for key, value in (
            ("HIPOCRATIQ_UAT_READONLY_ROLE_ARN", "arn:aws:iam::123456789012:role/Admin"),
            ("HIPOCRATIQ_UAT_AWS_ACCOUNT_ID", "000000000"),
            ("HIPOCRATIQ_UAT_AWS_REGION", "us/invalid"),
        ):
            with self.subTest(field=key):
                x = uat_config.check_variables(dict(SAFE, **{key: value}))
                self.assertIn("INVALID_" + key, x["blocker_codes"])

    def test_uat_and_production_ids_must_be_different(self):
        for uat, prod, code in (
            ("HIPOCRATIQ_UAT_INSTANCE_ID", "HIPOCRATIQ_PROD_INSTANCE_ID", "UAT_PRODUCTION_EC2_IDENTICAL"),
            ("HIPOCRATIQ_UAT_VPC_ID", "HIPOCRATIQ_PROD_VPC_ID", "UAT_PRODUCTION_VPC_IDENTICAL"),
        ):
            with self.subTest(resource=code):
                env = dict(SAFE)
                env[uat] = env[prod]
                self.assertIn(code, uat_config.check_variables(env)["blocker_codes"])

    def test_automatic_push_is_discouraged_for_infrastructure_reads(self):
        env = dict(SAFE, HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH="true")
        self.assertIn("DISABLE_AUTOMATIC_DEVELOP_PUSH_INVENTORY",
                      uat_config.check_variables(env)["blocker_codes"])

    def test_untrusted_repo_is_denied(self):
        x = uat_config.check_variables(dict(SAFE, GITHUB_REPOSITORY="foreign/repository"))
        self.assertIn("UNEXPECTED_REPOSITORY", x["blocker_codes"])

    def test_artifacts_summary_and_log_never_contain_account_or_identifiers(self):
        with tempfile.TemporaryDirectory() as tmp:
            env = dict(SAFE, UAT_READINESS_REPORT_PATH=str(Path(tmp) / "report.json"),
                       GITHUB_STEP_SUMMARY=str(Path(tmp) / "summary.md"),
                       AUTH_SECRET="sensitive-secret-test-sentinel")
            report = uat_config.check_variables(env)
            uat_config.report_no_sensitive_values(report, env)
            data = (Path(tmp) / "report.json").read_text()
            summary = (Path(tmp) / "summary.md").read_text()
            json.loads(data)
            for private in (SAFE["HIPOCRATIQ_UAT_AWS_ACCOUNT_ID"],
                            SAFE["HIPOCRATIQ_UAT_INSTANCE_ID"],
                            SAFE["HIPOCRATIQ_PROD_VPC_ID"],
                            SAFE["HIPOCRATIQ_UAT_READONLY_ROLE_ARN"],
                            "sensitive-secret-test-sentinel"):
                self.assertNotIn(private, data)
                self.assertNotIn(private, summary)
            self.assertIn("This offline job has no GitHub Environment", summary)


if __name__ == "__main__":
    unittest.main()
