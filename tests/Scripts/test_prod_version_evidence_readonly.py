"""Synthetic safety checks: the scanner never accesses actual AWS or patient data."""
import importlib.util
import io
import json
import os
from pathlib import Path
from types import SimpleNamespace
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / "scripts/prod_version_evidence_readonly.py"
SPEC = importlib.util.spec_from_file_location("prod_version_evidence_readonly", SCRIPT)
probe = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(probe)

class Headers:
    def __init__(self, values):
        self.values = values
    def get(self, key, default=""):
        return self.values.get(key, default)

class FakeResponse:
    def __init__(self, status, headers, data=b""):
        self.status = status
        self.headers = Headers(headers)
        self.data = data
    def __enter__(self):
        return self
    def __exit__(self, *_):
        return False
    def read(self, size):
        return self.data[:size]

class FakeOpener:
    def __init__(self, responses):
        self.responses = responses
        self.requests = []
    def open(self, req, timeout):
        self.requests.append((req.full_url, req.get_method()))
        return self.responses[req.full_url]

class VersionEvidenceSafetyTest(unittest.TestCase):
    def test_public_probe_without_sha_never_invents_deployed_versions(self):
        opener = FakeOpener({
            probe.TARGETS["frontend"]: FakeResponse(200, {"Content-Type": "text/html"}),
            probe.TARGETS["backend"]: FakeResponse(
                200, {"Content-Type": "application/json"},
                b'{"success":true,"data":{"status":"ok","patient":"never print"}}',
            ),
        })
        report = probe.public_probe(opener)
        self.assertEqual(report["status"], "RUNTIME_SHA_NOT_VERIFIED")
        self.assertIsNone(report["endpoints"]["frontend"]["reported_sha"])
        self.assertIsNone(report["endpoints"]["backend"]["reported_sha"])
        self.assertNotIn("patient", json.dumps(report))
        self.assertEqual(sorted(opener.requests),
                         sorted([(url, "GET") for url in probe.TARGETS.values()]))

    def test_sha_header_is_only_self_reported_and_requires_both_components(self):
        sha = "a" * 40
        opener = FakeOpener({
            probe.TARGETS["frontend"]: FakeResponse(
                200, {"X-Hipocratiq-Release-Sha": sha}),
            probe.TARGETS["backend"]: FakeResponse(
                200, {"Content-Type": "application/json"},
                json.dumps({"data": {"release_sha": sha}}).encode()),
        })
        report = probe.public_probe(opener)
        self.assertEqual(report["status"], "APPLICATION_REPORTED_SHA_REQUIRES_INDEPENDENT_VALIDATION")
        self.assertEqual(report["endpoints"]["frontend"]["reported_sha"], sha)
        self.assertEqual(report["endpoints"]["backend"]["reported_sha"], sha)
        self.assertFalse(report["production_changed"])

    def test_short_invalid_sha_is_not_accepted(self):
        opener = FakeOpener({
            probe.TARGETS["frontend"]: FakeResponse(
                200, {"X-Hipocratiq-Release-Sha": "deadbeef"}),
            probe.TARGETS["backend"]: FakeResponse(200, {"Content-Type": "text/plain"}),
        })
        self.assertEqual(probe.public_probe(opener)["status"], "RUNTIME_SHA_NOT_VERIFIED")

    def test_aws_production_requires_explicit_opt_in_and_restricts_commands(self):
        with self.assertRaises(probe.EvidenceError):
            probe.aws_probe({})
        with self.assertRaises(probe.EvidenceError):
            probe.aws_call(["ssm", "send-command", "--document-name", "AWS-RunShellScript"])
        with self.assertRaises(probe.EvidenceError):
            probe.aws_call(["ec2", "create-snapshot"])

    def test_approved_aws_metadata_still_does_not_certify_container_sha(self):
        values = {
            "GITHUB_REPOSITORY": "VaftecPeru/Hipocratiq_backend",
            "GITHUB_REF": "refs/heads/develop",
            "GITHUB_EVENT_NAME": "push",
            "AUDIT_ENABLED": "true",
            "AUDIT_PUSH_APPROVED": "true",
            "AUDIT_ACKNOWLEDGEMENT": "READ_ONLY_PROD_METADATA",
            "AUDIT_AWS_ACCOUNT_ID": "390736325457",
            "AUDIT_AWS_REGION": "us-east-2",
            "AUDIT_AWS_INSTANCE_ID": "i-0f6789726d40b4122",
            "AUDIT_AWS_VPC_ID": "vpc-0245b564a2e609bf6",
            "AUDIT_AWS_ROLE_ARN": "arn:aws:iam::390736325457:role/HIPOCRATIQ-PROD-ReadOnly",
        }
        responses = {
            "sts": {
                "Account": values["AUDIT_AWS_ACCOUNT_ID"],
                "Arn": "arn:aws:sts::390736325457:assumed-role/HIPOCRATIQ-PROD-ReadOnly/ci",
            },
            "instances": {"Reservations": [{"Instances": [{
                "InstanceId": values["AUDIT_AWS_INSTANCE_ID"],
                "VpcId": values["AUDIT_AWS_VPC_ID"],
                "State": {"Name": "running"},
            }]}]},
            "status": {"InstanceStatuses": [{
                "InstanceStatus": {"Status": "ok"},
                "SystemStatus": {"Status": "ok"},
            }]},
            "ssm": {"InstanceInformationList": [{
                "InstanceId": values["AUDIT_AWS_INSTANCE_ID"],
                "PingStatus": "Online",
            }]},
        }
        seen = []
        def runner(command, **kwargs):
            seen.append(command)
            key = ("sts" if "sts" in command
                   else "instances" if "describe-instances" in command
                   else "status" if "describe-instance-status" in command
                   else "ssm")
            return SimpleNamespace(returncode=0, stdout=json.dumps(responses[key]))

        report = probe.aws_probe(values, runner=runner)
        self.assertTrue(report["ec2_status_ok"])
        self.assertTrue(report["ssm_agent_online"])
        self.assertIsNone(report["deployed_backend_sha"])
        self.assertIsNone(report["deployed_frontend_sha"])
        self.assertEqual(report["status"], "INFRASTRUCTURE_IDENTIFIED_SOURCE_SHA_UNVERIFIED")
        self.assertTrue(all(cmd[1] in {"sts", "ec2", "ssm"} for cmd in seen))
        self.assertTrue(report["no_ssm_commands_or_ssh"])

    def test_never_uses_production_workflow_on_pr_or_wrong_account(self):
        config = {
            "GITHUB_REPOSITORY": "VaftecPeru/Hipocratiq_backend",
            "GITHUB_REF": "refs/pull/9/merge",
            "GITHUB_EVENT_NAME": "pull_request",
            "AUDIT_ENABLED": "true", "AUDIT_PUSH_APPROVED": "true",
            "AUDIT_ACKNOWLEDGEMENT": "READ_ONLY_PROD_METADATA",
        }
        with self.assertRaises(probe.EvidenceError):
            probe.aws_probe(config)

if __name__ == "__main__":
    unittest.main()
