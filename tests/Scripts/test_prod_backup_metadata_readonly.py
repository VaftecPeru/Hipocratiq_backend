"""Synthetic, offline regression tests for read-only production EBS evidence."""
import importlib.util
import json
from datetime import datetime, timezone
from pathlib import Path
from types import SimpleNamespace
import sys
import unittest

SCRIPT_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPT_DIR))
SPEC = importlib.util.spec_from_file_location(
    "prod_backup_metadata_readonly", SCRIPT_DIR / "prod_backup_metadata_readonly.py"
)
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)

NOW = datetime(2026, 11, 3, 12, 0, tzinfo=timezone.utc)
# Match the independently verified target used by the production preflight;
# all AWS responses below remain offline synthetic fixtures.
ACCOUNT = "390736325457"
INSTANCE = "i-0f6789726d40b4122"
VPC = "vpc-0245b564a2e609bf6"
VOL = "vol-0123456789abcdef0"
SNAP = "snap-0123456789abcdef0"


def approved_env():
    return {
        "GITHUB_REPOSITORY": "VaftecPeru/Hipocratiq_backend",
        "GITHUB_REF": "refs/heads/develop",
        "GITHUB_EVENT_NAME": "push",
        "AUDIT_ENABLED": "true",
        "AUDIT_PUSH_APPROVED": "true",
        "AUDIT_ACKNOWLEDGEMENT": "READ_ONLY_PROD_METADATA",
        "AUDIT_BACKUP_METADATA_ENABLED": "true",
        "AUDIT_BACKUP_METADATA_ACK": "READ_ONLY_EBS_SNAPSHOT_INVENTORY",
        "AUDIT_AWS_ACCOUNT_ID": ACCOUNT,
        "AUDIT_AWS_REGION": "us-east-2",
        "AUDIT_AWS_INSTANCE_ID": INSTANCE,
        "AUDIT_AWS_VPC_ID": VPC,
        "AUDIT_AWS_ROLE_ARN": f"arn:aws:iam::{ACCOUNT}:role/HIPOCRATIQ-PROD-ReadOnly",
    }


def base_data():
    return {
        "sts": {"Account": ACCOUNT,
                "Arn": f"arn:aws:sts::{ACCOUNT}:assumed-role/HIPOCRATIQ-PROD-ReadOnly/ci"},
        "instances": {"Reservations": [{"Instances": [{
            "InstanceId": INSTANCE, "VpcId": VPC,
            "BlockDeviceMappings": [{"Ebs": {"VolumeId": VOL}}],
        }]}]},
        "volumes": {"Volumes": [{
            "VolumeId": VOL, "Encrypted": True, "KmsKeyId": "not-for-report",
            "Attachments": [{"InstanceId": INSTANCE}],
        }]},
        "snapshots": {"Snapshots": [{
            "SnapshotId": SNAP, "VolumeId": VOL, "OwnerId": ACCOUNT,
            "State": "completed", "Encrypted": True,
            "StartTime": "2026-11-02T08:00:00Z",
            "Description": "must not be printed",
        }]},
    }


def mocked_runner(data, record):
    def run(command, **kwargs):
        record.append(command)
        if command[1] == "sts":
            key = "sts"
        elif "describe-instances" in command:
            key = "instances"
        elif "describe-volumes" in command:
            key = "volumes"
        elif "describe-snapshots" in command:
            key = "snapshots"
        else:
            raise AssertionError("Unexpected AWS action")
        return SimpleNamespace(returncode=0, stdout=json.dumps(data[key]))
    return run


class SnapshotMetadataInventoryTests(unittest.TestCase):
    def test_read_only_complete_metadata_does_not_grant_recovery_clearance(self):
        calls = []
        evidence = audit.probe(approved_env(), mocked_runner(base_data(), calls), NOW)
        self.assertEqual(evidence["attached_ebs_volumes"], 1)
        self.assertEqual(evidence["ebs_encrypted_volumes"], 1)
        self.assertEqual(evidence["ebs_volumes_with_completed_snapshot"], 1)
        self.assertEqual(evidence["ebs_volumes_with_recent_encrypted_snapshot_30d"], 1)
        self.assertEqual(evidence["snapshots_listed"], 1)
        self.assertTrue(evidence["recent_ebs_snapshot_metadata_coverage"])
        self.assertFalse(evidence["restore_tested"])
        self.assertFalse(evidence["database_backup_tested"])
        self.assertFalse(evidence["security_or_release_approval_granted"])
        text = json.dumps(evidence)
        for private_value in (ACCOUNT, INSTANCE, VPC, VOL, SNAP,
                              "not-for-report", "must not be printed"):
            self.assertNotIn(private_value, text)
        allowed = {
            ("sts", "get-caller-identity"),
            ("ec2", "describe-instances"),
            ("ec2", "describe-volumes"),
            ("ec2", "describe-snapshots"),
        }
        self.assertEqual({tuple(c[1:3]) for c in calls}, allowed)
        self.assertEqual(len(calls), 4)

    def test_no_unapproved_aws_calls_and_no_modify_commands(self):
        for bad in (
            {},
            {**approved_env(), "AUDIT_BACKUP_METADATA_ENABLED": "false"},
            {**approved_env(), "AUDIT_BACKUP_METADATA_ACK": ""},
            {**approved_env(), "GITHUB_EVENT_NAME": "pull_request"},
            {**approved_env(), "AUDIT_AWS_ACCOUNT_ID": "000000000000"},
        ):
            with self.subTest(name=str(sorted(bad))):
                calls = []
                with self.assertRaises(audit.EvidenceError):
                    audit.probe(bad, mocked_runner(base_data(), calls), NOW)
                self.assertFalse(calls)
        from prod_version_evidence_readonly import aws_call, EvidenceError
        for cmd in (["ssm", "send-command"], ["ec2", "create-snapshot"],
                    ["ec2", "delete-snapshot"], ["ec2", "modify-volume"],
                    ["backup", "start-restore-job"]):
            with self.assertRaises(EvidenceError):
                aws_call(cmd)

    def test_absence_of_snapshots_yields_no_false_success(self):
        data = base_data()
        data["snapshots"] = {"Snapshots": []}
        report = audit.probe(approved_env(), mocked_runner(data, []), NOW)
        self.assertEqual(report["completed_snapshots"], 0)
        self.assertEqual(report["ebs_volumes_with_completed_snapshot"], 0)
        self.assertFalse(report["recent_ebs_snapshot_metadata_coverage"])
        self.assertFalse(report["restore_tested"])

    def test_unencrypted_snapshots_or_old_snapshots_do_not_count_as_recent(self):
        data = base_data()
        data["snapshots"]["Snapshots"][0]["Encrypted"] = False
        result = audit.probe(approved_env(), mocked_runner(data, []), NOW)
        self.assertEqual(result["encrypted_completed_snapshots"], 0)
        self.assertFalse(result["recent_ebs_snapshot_metadata_coverage"])
        data["snapshots"]["Snapshots"][0]["Encrypted"] = True
        data["snapshots"]["Snapshots"][0]["StartTime"] = "2026-09-01T08:00:00Z"
        result = audit.probe(approved_env(), mocked_runner(data, []), NOW)
        self.assertEqual(result["completed_snapshots"], 1)
        self.assertFalse(result["recent_ebs_snapshot_metadata_coverage"])

    def test_rejects_other_accounts_and_other_volumes(self):
        for place, override in (
            ("sts", {"Account": "000000000000", "Arn": "other"}),
            ("instances", {"Reservations": []}),
            ("snapshots", {"Snapshots": [{"SnapshotId": SNAP, "VolumeId": VOL,
                                           "OwnerId": "000000000000",
                                           "State": "completed", "Encrypted": True}]}),
            ("volumes", {"Volumes": []}),
        ):
            with self.subTest(place=place):
                data = base_data()
                data[place] = override
                with self.assertRaises(audit.EvidenceError):
                    audit.probe(approved_env(), mocked_runner(data, []), NOW)

    def test_duplicate_snapshot_id_fails_closed_in_one_page(self):
        data = base_data()
        data["snapshots"]["Snapshots"].append(dict(data["snapshots"]["Snapshots"][0]))
        with self.assertRaisesRegex(audit.EvidenceError, "DUPLICATE_SNAPSHOT_ID"):
            audit.probe(approved_env(), mocked_runner(data, []), NOW)

    def test_repeated_page_token_fails_closed(self):
        data = base_data()
        data["snapshots"] = {"Snapshots": [], "NextToken": "same-token"}
        with self.assertRaises(audit.EvidenceError):
            audit.probe(approved_env(), mocked_runner(data, []), NOW)

    def test_disallows_unbounded_volume_inventory(self):
        data = base_data()
        data["instances"]["Reservations"][0]["Instances"][0]["BlockDeviceMappings"] = (
            [{"Ebs": {"VolumeId": VOL}}] * (audit.MAX_VOLUMES + 1)
        )
        with self.assertRaises(audit.EvidenceError):
            audit.probe(approved_env(), mocked_runner(data, []), NOW)


if __name__ == "__main__":
    unittest.main()
