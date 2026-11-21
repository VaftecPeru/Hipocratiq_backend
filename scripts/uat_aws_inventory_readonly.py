#!/usr/bin/env python3
"""Read-only AWS EC2 UAT inventory. Never modifies infrastructure or data."""
import argparse
import ipaddress
import json
import os
import re
import subprocess
import sys
from pathlib import Path

SAFE_ACTIONS = {
    ("sts", "get-caller-identity"),
    ("ec2", "describe-instances"),
    ("ec2", "describe-volumes"),
    ("ec2", "describe-security-group-rules"),
    ("ec2", "describe-instance-status"),
    ("ssm", "describe-instance-information"),
}
RISK_PORTS = {22, 5432, 6379, 3306, 8000, 8080}
# Treat ANY ingress CIDR outside these strictly private/non-routable ranges as
# potentially Internet reachable, including /32 IPv4 and /128 IPv6 rules.
# Security groups cannot safely assume that a narrow public CIDR is harmless.
NONPUBLIC_NETWORKS = tuple(ipaddress.ip_network(value) for value in (
    "10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16",
    "127.0.0.0/8", "169.254.0.0/16",
    "::1/128", "fc00::/7", "fe80::/10",
))


class GateError(Exception):
    pass


def check(condition, message):
    if not condition:
        raise GateError(message)


def preflight(env):
    """Called before AWS OIDC assumption; does not make network requests."""
    check(env.get("GITHUB_REPOSITORY") == "VaftecPeru/Hipocratiq_backend",
          "Only the approved HIPOCRATIQ repository is authorized.")
    check(env.get("GITHUB_REF") == "refs/heads/develop", "Only develop is authorized.")
    check(env.get("GITHUB_EVENT_NAME") in ("push", "workflow_dispatch"),
          "PRs and unapproved trigger types must never call AWS.")
    check(env.get("UAT_INVENTORY_ENABLED") == "true", "UAT inventory not enabled.")
    check(env.get("UAT_ACKNOWLEDGEMENT") == "READ_ONLY_UAT_INVENTORY",
          "Explicit read-only UAT acknowledgement is required.")
    if env.get("GITHUB_EVENT_NAME") == "push":
        check(env.get("UAT_PUSH_APPROVED") == "true",
              "Push-based UAT inventory was not explicitly approved.")
    account = env.get("UAT_AWS_ACCOUNT_ID", "")
    region = env.get("UAT_AWS_REGION", "")
    instance = env.get("UAT_INSTANCE_ID", "")
    production = env.get("PROD_INSTANCE_ID", "")
    uat_vpc = env.get("UAT_VPC_ID", "")
    prod_vpc = env.get("PROD_VPC_ID", "")
    role = env.get("UAT_READONLY_ROLE_ARN", "")
    check(bool(re.fullmatch(r"\d{12}", account)), "Invalid UAT account ID.")
    check(bool(re.fullmatch(r"[a-z]{2}-[a-z]+-\d", region)), "Invalid AWS region.")
    for label, value, pattern in [
        ("UAT instance", instance, r"i-[0-9a-f]{8,17}"),
        ("production exclusion instance", production, r"i-[0-9a-f]{8,17}"),
        ("UAT VPC", uat_vpc, r"vpc-[0-9a-f]{8,17}"),
        ("production exclusion VPC", prod_vpc, r"vpc-[0-9a-f]{8,17}"),
    ]:
        check(bool(re.fullmatch(pattern, value)), f"Missing or invalid {label}.")
    check(instance != production, "UAT and production share an EC2 instance: denied.")
    check(uat_vpc != prod_vpc, "UAT and production share a VPC: denied.")
    check(bool(re.fullmatch(
        rf"arn:aws:iam::{account}:role/(?:[\w+=,.@/-]*/)?HIPOCRATIQ-UAT-ReadOnly",
        role,
    )), "Role must be HIPOCRATIQ-UAT-ReadOnly for the approved account.")
    return {"account": account, "region": region, "instance": instance,
            "uat_vpc": uat_vpc, "role": role}


def aws_json(args, config, runner=None):
    if tuple(args[:2]) not in SAFE_ACTIONS:
        raise GateError("Attempted AWS action is not on the read-only allowlist.")
    cmd = ["aws", *args, "--region", config["region"], "--output", "json", "--no-cli-pager"]
    try:
        result = (runner or subprocess.run)(
            cmd, check=False, capture_output=True, text=True, timeout=35
        )
    except (OSError, subprocess.TimeoutExpired):
        raise GateError("AWS read-only request failed or timed out.") from None
    if result.returncode != 0:
        raise GateError(f"AWS {args[0]} {args[1]} failed. Check UAT read-only IAM.")
    try:
        return json.loads(result.stdout)
    except (ValueError, TypeError):
        raise GateError("AWS returned invalid JSON.") from None


def public_sensitive_port(rule):
    if rule.get("IsEgress") is not False:
        return False
    try:
        public = any(
            not any(
                network.subnet_of(private)
                for private in NONPUBLIC_NETWORKS
                if network.version == private.version
            )
            for network in (
                ipaddress.ip_network(cidr, strict=False)
                for cidr in (rule.get("CidrIpv4"), rule.get("CidrIpv6"))
                if cidr
            )
        )
    except ValueError:
        raise GateError("Invalid AWS security group CIDR.") from None
    if not public:
        return False
    if str(rule.get("IpProtocol")) == "-1":
        return True
    if rule.get("IpProtocol") not in ("tcp", "6"):
        return False
    start, end = rule.get("FromPort"), rule.get("ToPort")
    if start is None or end is None:
        return True
    return any(int(start) <= port <= int(end) for port in RISK_PORTS)


def inspect(config, runner=None):
    identity = aws_json(["sts", "get-caller-identity"], config, runner)
    check(str(identity.get("Account")) == config["account"],
          "STS account differs from approved UAT account.")
    check(str(identity.get("Arn", "")).startswith(
        f"arn:aws:sts::{config['account']}:assumed-role/HIPOCRATIQ-UAT-ReadOnly/"
    ), "AWS principal is not the dedicated UAT read-only role.")

    response = aws_json(
        ["ec2", "describe-instances", "--instance-ids", config["instance"]], config, runner
    )
    instances = [i for r in response.get("Reservations", [])
                 for i in r.get("Instances", [])]
    check(len(instances) == 1 and instances[0].get("InstanceId") == config["instance"],
          "Instance does not match the exact approved UAT EC2 ID.")
    instance = instances[0]
    tags = {str(t.get("Key", "")).lower(): str(t.get("Value", "")).lower()
            for t in instance.get("Tags", [])}
    check(tags.get("environment") in ("uat", "qa", "staging"),
          "Instance Environment tag is not UAT.")
    check(tags.get("project") in ("hipocratiq", "hipocratiq-ia"),
          "Instance Project tag does not identify HIPOCRATIQ.")
    check("prod" not in tags.get("name", ""),
          "Instance Name tag indicates production.")
    check(instance.get("VpcId") == config["uat_vpc"],
          "Instance VPC differs from approved, isolated UAT VPC.")

    blockers = []
    state = instance.get("State", {}).get("Name", "unknown")
    if state != "running":
        blockers.append("UAT EC2 is not running")

    health = aws_json([
        "ec2", "describe-instance-status", "--instance-ids", config["instance"],
        "--include-all-instances",
    ], config, runner).get("InstanceStatuses", [])
    if len(health) != 1 or (
        health[0].get("InstanceStatus", {}).get("Status") != "ok" or
        health[0].get("SystemStatus", {}).get("Status") != "ok"
    ):
        blockers.append("EC2 instance/system status checks are not both OK")

    ssm = aws_json([
        "ssm", "describe-instance-information", "--filters",
        "Key=InstanceIds,Values=" + config["instance"],
    ], config, runner).get("InstanceInformationList", [])
    if len(ssm) != 1 or ssm[0].get("PingStatus") != "Online":
        blockers.append("SSM agent is not Online (no remote command was run)")

    volume_ids = sorted({
        mapping.get("Ebs", {}).get("VolumeId")
        for mapping in instance.get("BlockDeviceMappings", [])
        if mapping.get("Ebs", {}).get("VolumeId")
    })
    if not volume_ids:
        blockers.append("No attached EBS volumes found")
    else:
        volumes = aws_json(
            ["ec2", "describe-volumes", "--volume-ids", *volume_ids], config, runner
        ).get("Volumes", [])
        if len(volumes) != len(volume_ids):
            blockers.append("Not all EBS volumes could be verified")
        if any(v.get("Encrypted") is not True for v in volumes):
            blockers.append("One or more EBS volumes are not encrypted")

    groups = sorted({
        g.get("GroupId") for g in instance.get("SecurityGroups", [])
        if g.get("GroupId")
    })
    if not groups:
        blockers.append("Instance has no inspectable security groups")
    else:
        rules = aws_json([
            "ec2", "describe-security-group-rules",
            "--filters", "Name=group-id,Values=" + ",".join(groups),
        ], config, runner).get("SecurityGroupRules", [])
        if any(public_sensitive_port(rule) for rule in rules):
            blockers.append("Public inbound SSH, database, Redis or application port found")

    return {
        "gate": "HIPOCRATIQ_AWS_UAT_READONLY",
        "status": "NO_GO" if blockers else "INVENTORY_PASS_NOT_RELEASE_APPROVAL",
        "instance_id": config["instance"],
        "verified_aws_account": True,
        "verified_uat_role_and_tags": True,
        "approved_uat_ids_differ_from_declared_production_ids": True,
        "isolation_caveat": "Production IDs were administrator-supplied, not queried; separation requires independent verification.",
        "ec2_state": state,
        "ec2_system_and_instance_health_checked": True,
        "ssm_agent_ping_checked": True,
        "checked_ebs_volumes": len(volume_ids),
        "checked_security_groups": len(groups),
        "blockers": blockers,
        "limitations": [
            "Metadata cannot prove Docker, PostgreSQL, Redis, TLS, SMS OTP or restore readiness.",
            "The declared production exclusion IDs were not independently verified.",
            "SSM DescribeInstanceInformation only; NO SendCommand, Session, SSH or writes.",
            "Clinical HTTPS UAT and customer acceptance must be validated separately.",
        ],
    }


def emit(report, env):
    if env.get("UAT_REPORT_PATH"):
        file = Path(env["UAT_REPORT_PATH"])
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n",
                        encoding="utf-8")
    if env.get("GITHUB_STEP_SUMMARY"):
        with Path(env["GITHUB_STEP_SUMMARY"]).open("a", encoding="utf-8") as f:
            f.write("## AWS UAT read-only inventory\n\n")
            f.write("Result: " + report["status"] + "\n\n")
            for blocker in report.get("blockers", []):
                f.write("- Blocking issue: " + blocker + "\n")
            f.write("\nNot a production deployment approval.\n")
    print("UAT_AWS_INVENTORY=" + report["status"])


def main(argv=None, env=None, runner=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--preflight", action="store_true")
    arguments = parser.parse_args(argv)
    env = os.environ if env is None else env
    try:
        config = preflight(env)
        if arguments.preflight:
            print("UAT_AWS_PREFLIGHT=PASS_NO_NETWORK")
            return 0
        report = inspect(config, runner)
        emit(report, env)
        return 0 if not report["blockers"] else 2
    except GateError as err:
        if arguments.preflight:
            print("UAT_AWS_PREFLIGHT=DENIED: " + str(err), file=sys.stderr)
        else:
            emit({"status": "NO_GO", "blockers": [str(err)]}, env)
        return 3


if __name__ == "__main__":
    sys.exit(main())
