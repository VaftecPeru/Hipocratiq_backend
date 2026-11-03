"""No real GitHub/AWS calls. Check production deployment fails closed."""
import importlib.util
import json
import os
from pathlib import Path
import shlex
from types import SimpleNamespace
import unittest

ROOT = Path(__file__).resolve().parents[2] / "scripts"
SPEC = importlib.util.spec_from_file_location("ssm_approved_release", ROOT / "ssm_approved_release.py")
deploy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(deploy)

ACCOUNT = "123456789012"
INSTANCE = "i-0123456789abcdef0"
VPC = "vpc-0123456789abcdef0"
BE_SHA = "a" * 40
FE_SHA = "b" * 40

def approved():
    return {
        "GITHUB_REPOSITORY": "VaftecPeru/Hipocratiq_backend",
        "GITHUB_REF": "refs/heads/main",
        "GITHUB_EVENT_NAME": "workflow_dispatch",
        "GITHUB_SHA": BE_SHA,
        "DEPLOY_ENABLED": "true",
        "RELEASE_ACK": "APPROVED_HIPOCRATIQ_CONTROLLED_PRODUCTION",
        "DEPLOY_BACKEND_SHA": BE_SHA,
        "DEPLOY_FRONTEND_SHA": FE_SHA,
        "RELEASE_CHANGE_ID": "HIPOCRATIQ-APPROVED-100",
        "UAT_CLINICAL_EVIDENCE": "UAT-36-signed",
        "SECURITY_EVIDENCE": "Gitleaks-review-102",
        "UAT_RESTORE_EVIDENCE": "Restore-113-signed",
        "BACKUP_AT_REST_EVIDENCE": "Encryption-signed",
        "RELEASE_BACKUP_AT_REST_VERIFIED": "true",
        "PROD_ACCOUNT_ID": ACCOUNT,
        "PROD_AWS_REGION": "us-east-2",
        "PROD_INSTANCE_ID": INSTANCE,
        "PROD_VPC_ID": VPC,
        "PROD_ROLE_ARN": f"arn:aws:iam::{ACCOUNT}:role/HIPOCRATIQ-PROD-Deploy",
    }

class ControlledReleaseSafetyTests(unittest.TestCase):
    def test_manual_approved_context_is_necessary_not_authorization(self):
        env = approved()
        cfg = deploy.validate_env(env)
        self.assertEqual(cfg["instance"], INSTANCE)
        commands = deploy.command_lines(deploy.B, BE_SHA, env, False)
        joined = "\n".join(commands)
        self.assertIn("git rev-parse origin/main", joined)
        self.assertEqual(len(commands), 1)
        self.assertTrue(commands[0].startswith("bash -lc "))
        self.assertIn("git show", joined)
        self.assertIn("APPROVED_FOR_PRODUCTION", joined)
        self.assertNotIn("UAT-36-signed", deploy.command_lines(deploy.B, BE_SHA, env, False)[0])
        self.assertNotIn("BACKEND_SSM_STATUS=SUCCESS", joined)

    def test_push_pr_wrong_branch_or_readonly_role_are_disallowed(self):
        for key, value in (
            ("GITHUB_EVENT_NAME", "push"),
            ("GITHUB_REF", "refs/heads/develop"),
            ("GITHUB_REPOSITORY", "VaftecPeru/Hipocratiq_Frontend"),
            ("PROD_ROLE_ARN", f"arn:aws:iam::{ACCOUNT}:role/HIPOCRATIQ-PROD-ReadOnly"),
            ("RELEASE_ACK", ""),
            ("RELEASE_BACKUP_AT_REST_VERIFIED", "false"),
        ):
            env = approved()
            env[key] = value
            with self.subTest(key=key), self.assertRaises(deploy.DeploymentError):
                deploy.validate_env(env)

    def test_path_and_command_strings_cannot_be_arbitrary(self):
        env = approved()
        with self.assertRaises(deploy.DeploymentError):
            deploy.command_lines("/tmp/elsewhere", BE_SHA, env, False)
        with self.assertRaises(deploy.DeploymentError):
            deploy.command_lines(deploy.B, "main; rm -rf /", env, False)
        env["RELEASE_CHANGE_ID"] = "approved-id; fake-payload"
        with self.assertRaises(deploy.DeploymentError):
            deploy.validate_env(env)

    def test_aws_call_is_allowlisted(self):
        for forbidden in (
            ["ssm", "start-session"], ["ec2", "terminate-instances"],
            ["ec2", "create-snapshot"], ["secretsmanager", "get-secret-value"],
            ["iam", "pass-role"], ["backup", "start-restore-job"],
        ):
            with self.assertRaises(deploy.DeploymentError):
                deploy.aws_call(forbidden, "us-east-2")

    def test_verify_account_instance_vpc_ssm_online_before_command(self):
        cfg = deploy.validate_env(approved())
        observed = []
        def runner(cmd, **_kw):
            observed.append(tuple(cmd[1:3]))
            action = cmd[2]
            payloads = {
                "get-caller-identity": {
                    "Account": ACCOUNT,
                    "Arn": f"arn:aws:sts::{ACCOUNT}:assumed-role/HIPOCRATIQ-PROD-Deploy/ci",
                },
                "describe-instances": {"Reservations": [{"Instances": [{
                    "InstanceId": INSTANCE,
                    "VpcId": VPC,
                    "State": {"Name": "running"},
                }]}]},
                "describe-instance-information": {"InstanceInformationList": [{
                    "InstanceId": INSTANCE, "PingStatus": "Online",
                }]},
            }
            return SimpleNamespace(returncode=0, stdout=json.dumps(payloads[action]), stderr="")
        deploy.preflight_aws(cfg, runner)
        self.assertEqual(observed, [
            ("sts", "get-caller-identity"),
            ("ec2", "describe-instances"),
            ("ssm", "describe-instance-information")
        ])

    def test_wrong_instance_blocks_any_remote_command(self):
        cfg = deploy.validate_env(approved())
        def runner(cmd, **_kw):
            action = cmd[2]
            payload = {
                "get-caller-identity": {
                    "Account": ACCOUNT,
                    "Arn": f"arn:aws:sts::{ACCOUNT}:assumed-role/HIPOCRATIQ-PROD-Deploy/ci"
                },
                "describe-instances": {"Reservations": [{"Instances": [{
                    "InstanceId": INSTANCE, "VpcId": "vpc-00000000",
                    "State": {"Name": "running"}
                }]}]},
            }[action]
            return SimpleNamespace(returncode=0, stdout=json.dumps(payload), stderr="")
        with self.assertRaises(deploy.DeploymentError):
            deploy.preflight_aws(cfg, runner)

    def test_failed_ssm_does_not_print_remote_output_or_claim_success(self):
        cfg = deploy.validate_env(approved())
        seen = []
        def runner(cmd, **_kw):
            seen.append(cmd[2])
            if cmd[2] == "send-command":
                return SimpleNamespace(returncode=0, stdout=json.dumps({
                    "Command": {"CommandId": "12345678-1234-1234-1234-123456789abc"}
                }), stderr="")
            return SimpleNamespace(returncode=0, stdout=json.dumps({
                "Status": "Failed", "StandardErrorContent": "private-never-log",
            }), stderr="")
        with self.assertRaises(deploy.DeploymentError):
            deploy.send_and_wait(cfg, ["echo no_secrets"], "BACKEND", runner, sleep=lambda _: None)
        self.assertEqual(seen, ["send-command", "get-command-invocation"])

if __name__ == "__main__":
    unittest.main()
