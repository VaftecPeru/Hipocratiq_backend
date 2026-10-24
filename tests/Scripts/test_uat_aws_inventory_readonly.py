"""Offline tests: any AWS call is mocked and no real IAM or EC2 access occurs."""
import importlib.util
import json
import os
from pathlib import Path
import types
import unittest
from unittest.mock import Mock

SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "uat_aws_inventory_readonly.py"
spec = importlib.util.spec_from_file_location("uat_inventory", SCRIPT)
inventory = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inventory)

VALID = {
    "GITHUB_REPOSITORY": "VaftecPeru/Hipocratiq_backend",
    "GITHUB_REF": "refs/heads/develop",
    "GITHUB_EVENT_NAME": "push",
    "UAT_INVENTORY_ENABLED": "true",
    "UAT_ACKNOWLEDGEMENT": "READ_ONLY_UAT_INVENTORY",
    "UAT_PUSH_APPROVED": "true",
    "UAT_AWS_ACCOUNT_ID": "123456789012",
    "UAT_AWS_REGION": "us-east-2",
    "UAT_INSTANCE_ID": "i-0123456789abcdef0",
    "PROD_INSTANCE_ID": "i-abcdef12345678901",
    "UAT_VPC_ID": "vpc-0123456789abcdef0",
    "PROD_VPC_ID": "vpc-abcdef12345678901",
    "UAT_READONLY_ROLE_ARN":
        "arn:aws:iam::123456789012:role/HIPOCRATIQ-UAT-ReadOnly",
}
INSTANCE = {
    "InstanceId": VALID["UAT_INSTANCE_ID"],
    "State": {"Name": "running"},
    "VpcId": VALID["UAT_VPC_ID"],
    "Tags": [
        {"Key": "Environment", "Value": "uat"},
        {"Key": "Project", "Value": "HIPOCRATIQ"},
        {"Key": "Name", "Value": "hipocratiq-uat"},
    ],
    "BlockDeviceMappings": [{"Ebs": {"VolumeId": "vol-0123456789abcdef0"}}],
    "SecurityGroups": [{"GroupId": "sg-0123456789abcdef0"}],
}


def stub_responses(instance=None, volumes=None, rules=None, identity=None,
                   ec2_health=None, ssm_ping=None):
    payloads = {
        ("sts", "get-caller-identity"):
            identity or {
                "Account": VALID["UAT_AWS_ACCOUNT_ID"],
                "Arn": ("arn:aws:sts::123456789012:assumed-role/"
                        "HIPOCRATIQ-UAT-ReadOnly/github-actions"),
            },
        ("ec2", "describe-instances"):
            {"Reservations": [{"Instances": [instance or INSTANCE]}]},
        ("ec2", "describe-volumes"):
            {"Volumes": volumes if volumes is not None else [
                {"VolumeId": "vol-0123456789abcdef0", "Encrypted": True}
            ]},
        ("ec2", "describe-security-group-rules"):
            {"SecurityGroupRules": rules if rules is not None else [
                {"IsEgress": False, "IpProtocol": "tcp", "FromPort": 443,
                 "ToPort": 443, "CidrIpv4": "0.0.0.0/0"}
            ]},
        ("ec2", "describe-instance-status"):
            {"InstanceStatuses": ec2_health if ec2_health is not None else [
                {"InstanceStatus": {"Status": "ok"}, "SystemStatus": {"Status": "ok"}}
            ]},
        ("ssm", "describe-instance-information"):
            {"InstanceInformationList": ssm_ping if ssm_ping is not None else [
                {"PingStatus": "Online"}
            ]},
    }
    seen = []

    def run(command, **kwargs):
        seen.append(command)
        key = tuple(command[1:3])
        assert key in payloads, f"Unsafe or unexpected AWS action: {key}"
        return types.SimpleNamespace(returncode=0, stdout=json.dumps(payloads[key]))

    return run, seen


class UatInventoryReadOnlyTests(unittest.TestCase):
    def test_valid_preflight_and_no_network(self):
        config = inventory.preflight(VALID)
        self.assertEqual(config["instance"], VALID["UAT_INSTANCE_ID"])
        network = Mock(side_effect=AssertionError("AWS should never be called"))
        self.assertEqual(inventory.main(["--preflight"], VALID, network), 0)
        network.assert_not_called()

    def test_preflight_fails_closed_without_explicit_opt_in(self):
        for field, invalid in [
            ("UAT_INVENTORY_ENABLED", ""),
            ("GITHUB_REF", "refs/heads/main"),
            ("GITHUB_REPOSITORY", "Untrusted/other"),
            ("UAT_ACKNOWLEDGEMENT", ""),
            ("UAT_PUSH_APPROVED", "false"),
            ("GITHUB_EVENT_NAME", "pull_request"),
            ("UAT_READONLY_ROLE_ARN", "arn:aws:iam::123456789012:role/Admin"),
            ("UAT_AWS_ACCOUNT_ID", "999"),
            ("UAT_INSTANCE_ID", VALID["PROD_INSTANCE_ID"]),
            ("UAT_VPC_ID", VALID["PROD_VPC_ID"]),
        ]:
            with self.subTest(field=field):
                data = dict(VALID, **{field: invalid})
                with self.assertRaises(inventory.GateError):
                    inventory.preflight(data)

    def test_valid_instance_requires_only_readonly_aws_describe_calls(self):
        runner, seen = stub_responses()
        result = inventory.inspect(inventory.preflight(VALID), runner)
        self.assertEqual(result["status"], "INVENTORY_PASS_NOT_RELEASE_APPROVAL")
        self.assertEqual(len(seen), 6)
        self.assertEqual({tuple(cmd[1:3]) for cmd in seen}, inventory.SAFE_ACTIONS)
        self.assertTrue(all("--no-cli-pager" in command for command in seen))

    def test_rejects_non_uat_tags_without_probing_other_resources(self):
        wrong = dict(INSTANCE, Tags=[{"Key": "Environment", "Value": "production"}])
        runner, seen = stub_responses(instance=wrong)
        with self.assertRaisesRegex(inventory.GateError, "Environment"):
            inventory.inspect(inventory.preflight(VALID), runner)
        self.assertEqual(len(seen), 2)

    def test_rejects_unauthorized_iam_identity(self):
        runner, seen = stub_responses(identity={
            "Account": VALID["UAT_AWS_ACCOUNT_ID"],
            "Arn": "arn:aws:sts::123456789012:assumed-role/ProductionRole/unsafe",
        })
        with self.assertRaisesRegex(inventory.GateError, "dedicated"):
            inventory.inspect(inventory.preflight(VALID), runner)
        self.assertEqual(len(seen), 1)

    def test_open_ssh_or_database_port_is_blocking(self):
        for port in (22, 5432, 6379, 8000):
            with self.subTest(port=port):
                runner, _ = stub_responses(rules=[{
                    "IsEgress": False, "IpProtocol": "tcp", "FromPort": port,
                    "ToPort": port, "CidrIpv4": "0.0.0.0/0",
                }])
                result = inventory.inspect(inventory.preflight(VALID), runner)
                self.assertEqual(result["status"], "NO_GO")
                self.assertTrue(any("Public inbound" in value
                                    for value in result["blockers"]))

    def test_ipv6_all_ports_public_is_blocking(self):
        runner, _ = stub_responses(rules=[{
            "IsEgress": False, "IpProtocol": "-1", "CidrIpv6": "::/0",
        }])
        result = inventory.inspect(inventory.preflight(VALID), runner)
        self.assertEqual(result["status"], "NO_GO")

    def test_broad_ipv4_and_ipv6_ranges_are_rejected(self):
        for cidr, name in [("0.0.0.0/1", "CidrIpv4"), ("2000::/3", "CidrIpv6")]:
            with self.subTest(cidr=cidr):
                runner, _ = stub_responses(rules=[{
                    "IsEgress": False, "IpProtocol": "tcp",
                    "FromPort": 22, "ToPort": 22, name: cidr,
                }])
                report = inventory.inspect(inventory.preflight(VALID), runner)
                self.assertEqual(report["status"], "NO_GO")

    def test_narrow_public_ipv4_and_ipv6_cidrs_are_blocking(self):
        # The old gate flagged only broad prefixes. A /32 SSH rule still
        # exposes the host to that public address and must block release.
        examples = [
            ("CidrIpv4", "8.8.8.8/32", 22),
            ("CidrIpv4", "1.1.1.0/24", 5432),
            ("CidrIpv6", "2606:4700::1111/128", 6379),
            ("CidrIpv6", "2606:4700::/48", 8000),
        ]
        for field, cidr, port in examples:
            with self.subTest(cidr=cidr, port=port):
                runner, _ = stub_responses(rules=[{
                    "IsEgress": False, "IpProtocol": "tcp",
                    "FromPort": port, "ToPort": port, field: cidr,
                }])
                result = inventory.inspect(inventory.preflight(VALID), runner)
                self.assertEqual(result["status"], "NO_GO")
                self.assertTrue(any("Public inbound" in x
                                    for x in result["blockers"]))

    def test_private_ipv4_and_ipv6_sensitive_ingress_is_not_public(self):
        for field, cidr in [
            ("CidrIpv4", "10.0.3.0/24"),
            ("CidrIpv4", "172.16.5.128/25"),
            ("CidrIpv4", "192.168.1.0/24"),
            ("CidrIpv6", "fd00:abcd::/64"),
        ]:
            with self.subTest(cidr=cidr):
                runner, _ = stub_responses(rules=[{
                    "IsEgress": False, "IpProtocol": "tcp",
                    "FromPort": 22, "ToPort": 22, field: cidr,
                }])
                result = inventory.inspect(inventory.preflight(VALID), runner)
                self.assertEqual(result["status"],
                                 "INVENTORY_PASS_NOT_RELEASE_APPROVAL")

    def test_narrow_public_https_and_outbound_ssh_are_not_exposure(self):
        runner, _ = stub_responses(rules=[
            {"IsEgress": False, "IpProtocol": "tcp",
             "FromPort": 443, "ToPort": 443, "CidrIpv4": "8.8.8.8/32"},
            {"IsEgress": True, "IpProtocol": "tcp",
             "FromPort": 22, "ToPort": 22, "CidrIpv4": "0.0.0.0/0"},
        ])
        report = inventory.inspect(inventory.preflight(VALID), runner)
        self.assertEqual(report["status"],
                         "INVENTORY_PASS_NOT_RELEASE_APPROVAL")

    def test_malformed_ingress_cidr_is_fail_closed(self):
        runner, _ = stub_responses(rules=[{
            "IsEgress": False, "IpProtocol": "tcp",
            "FromPort": 22, "ToPort": 22, "CidrIpv4": "invalid-cidr",
        }])
        with self.assertRaisesRegex(inventory.GateError, "Invalid AWS security group CIDR"):
            inventory.inspect(inventory.preflight(VALID), runner)

    def test_public_https_only_does_not_fail_network_gate(self):
        runner, _ = stub_responses()
        result = inventory.inspect(inventory.preflight(VALID), runner)
        self.assertFalse(result["blockers"])

    def test_unencrypted_ebs_is_blocking(self):
        runner, _ = stub_responses(volumes=[
            {"VolumeId": "vol-0123456789abcdef0", "Encrypted": False}
        ])
        result = inventory.inspect(inventory.preflight(VALID), runner)
        self.assertEqual(result["status"], "NO_GO")
        self.assertTrue(any("encrypted" in x for x in result["blockers"]))

    def test_unhealthy_ec2_status_check_is_blocking(self):
        runner, _ = stub_responses(ec2_health=[
            {"InstanceStatus": {"Status": "impaired"},
             "SystemStatus": {"Status": "ok"}}
        ])
        report = inventory.inspect(inventory.preflight(VALID), runner)
        self.assertEqual(report["status"], "NO_GO")
        self.assertTrue(any("status checks" in x for x in report["blockers"]))

    def test_ssm_agent_offline_is_blocking_without_remote_execution(self):
        runner, seen = stub_responses(ssm_ping=[{"PingStatus": "Offline"}])
        report = inventory.inspect(inventory.preflight(VALID), runner)
        self.assertEqual(report["status"], "NO_GO")
        self.assertTrue(any("SSM agent" in x for x in report["blockers"]))
        self.assertFalse(any("send-command" in " ".join(cmd) for cmd in seen))

    def test_allowlist_blocks_mutative_aws_calls_before_subprocess(self):
        with self.assertRaisesRegex(inventory.GateError, "allowlist"):
            inventory.aws_json(["ec2", "start-instances"], inventory.preflight(VALID),
                               Mock(side_effect=AssertionError("unsafe AWS call")))


if __name__ == "__main__":
    unittest.main()
