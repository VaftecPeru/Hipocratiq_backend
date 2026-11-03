#!/usr/bin/env python3
"""Manually approved EC2 deployment via GitHub OIDC + SSM; NEVER via SSH.

This script is not callable from PR or main push. All external AWS credentials
come from temporary OIDC after an independent GitHub Environment approval.
Do not log SSM stdout/stderr: a remote diagnostic may contain sensitive data.
"""
from __future__ import annotations
import argparse
import json
import os
import re
import shlex
import subprocess
import sys
import tempfile
import time
from pathlib import Path

SHA = re.compile(r"[0-9a-f]{40}\Z")
ACCOUNT = re.compile(r"[0-9]{12}\Z")
REGION = re.compile(r"[a-z]{2}-[a-z]+-\d\Z")
INSTANCE = re.compile(r"i-[0-9a-f]{8,17}\Z")
VPC = re.compile(r"vpc-[0-9a-f]{8,17}\Z")
REF = re.compile(r"[A-Za-z0-9][A-Za-z0-9._:/-]{7,159}\Z")
B = "/srv/hipocratiq/backend"
F = "/srv/hipocratiq/frontend"

class DeploymentError(Exception):
    pass

def require(condition, code):
    if not condition:
        raise DeploymentError(code)

def validate_env(e):
    require(e.get("GITHUB_REPOSITORY") == "VaftecPeru/Hipocratiq_backend",
            "BAD_GITHUB_REPOSITORY")
    require(e.get("GITHUB_REF") == "refs/heads/main"
            and e.get("GITHUB_EVENT_NAME") == "workflow_dispatch",
            "MANUAL_PROTECTED_MAIN_ONLY")
    require(e.get("DEPLOY_ENABLED") == "true"
            and e.get("RELEASE_ACK") == "APPROVED_HIPOCRATIQ_CONTROLLED_PRODUCTION",
            "DEPLOY_NOT_EXPLICITLY_APPROVED")
    require(e.get("DEPLOY_BACKEND_SHA") == e.get("GITHUB_SHA")
            and SHA.fullmatch(e.get("DEPLOY_BACKEND_SHA", "")),
            "INVALID_BACKEND_RELEASE_SHA")
    require(SHA.fullmatch(e.get("DEPLOY_FRONTEND_SHA", "")),
            "INVALID_FRONTEND_RELEASE_SHA")
    for key in ("RELEASE_CHANGE_ID", "UAT_CLINICAL_EVIDENCE",
                "SECURITY_EVIDENCE", "UAT_RESTORE_EVIDENCE",
                "BACKUP_AT_REST_EVIDENCE"):
        require(REF.fullmatch(e.get(key, "")), "MISSING_RELEASE_EVIDENCE_" + key)
    account, region, instance, vpc, role = (e.get(k, "") for k in (
        "PROD_ACCOUNT_ID", "PROD_AWS_REGION", "PROD_INSTANCE_ID",
        "PROD_VPC_ID", "PROD_ROLE_ARN"
    ))
    require(ACCOUNT.fullmatch(account) and REGION.fullmatch(region)
            and INSTANCE.fullmatch(instance) and VPC.fullmatch(vpc),
            "INVALID_AWS_DEPLOY_TARGET")
    require(role == f"arn:aws:iam::{account}:role/HIPOCRATIQ-PROD-Deploy",
            "UNEXPECTED_AWS_DEPLOY_ROLE")
    require(e.get("RELEASE_BACKUP_AT_REST_VERIFIED") == "true",
            "ENCRYPTED_BACKUP_APPROVAL_MISSING")
    return {"account": account, "region": region, "instance": instance, "vpc": vpc}

def aws_call(args, region, runner=subprocess.run):
    allowed = {
        ("sts", "get-caller-identity"),
        ("ec2", "describe-instances"),
        ("ssm", "describe-instance-information"),
        ("ssm", "send-command"),
        ("ssm", "get-command-invocation"),
    }
    require(tuple(args[:2]) in allowed, "AWS_ACTION_NOT_ALLOWLISTED")
    try:
        proc = runner(
            ["aws", *args, "--region", region, "--output", "json", "--no-cli-pager"],
            capture_output=True, text=True, timeout=50, check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise DeploymentError("AWS_COMMAND_UNAVAILABLE") from None
    require(proc.returncode == 0, "AWS_COMMAND_FAILED")  # never echo stderr
    try:
        result = json.loads(proc.stdout)
    except (TypeError, ValueError):
        raise DeploymentError("AWS_JSON_INVALID") from None
    require(isinstance(result, dict), "AWS_JSON_NOT_OBJECT")
    return result

def preflight_aws(cfg, runner=subprocess.run):
    region = cfg["region"]
    caller = aws_call(["sts", "get-caller-identity"], region, runner)
    require(caller.get("Account") == cfg["account"], "AWS_ACCOUNT_MISMATCH")
    require(str(caller.get("Arn", "")).startswith(
        f"arn:aws:sts::{cfg['account']}:assumed-role/HIPOCRATIQ-PROD-Deploy/"
    ), "AWS_ROLE_MISMATCH")
    data = aws_call(["ec2", "describe-instances", "--instance-ids", cfg["instance"]],
                    region, runner)
    instances = [i for r in data.get("Reservations", [])
                 for i in r.get("Instances", [])]
    require(len(instances) == 1
            and instances[0].get("InstanceId") == cfg["instance"]
            and instances[0].get("VpcId") == cfg["vpc"]
            and instances[0].get("State", {}).get("Name") == "running",
            "AWS_PRODUCTION_INSTANCE_NOT_VERIFIED")
    ssm = aws_call(["ssm", "describe-instance-information",
                    "--filters", f"Key=InstanceIds,Values={cfg['instance']}"],
                   region, runner)
    entries = ssm.get("InstanceInformationList", [])
    require(len(entries) == 1 and entries[0].get("InstanceId") == cfg["instance"]
            and entries[0].get("PingStatus") == "Online", "SSM_AGENT_NOT_ONLINE")

def command_lines(repo_dir, sha, e, is_frontend):
    """Only fixed, escaped values; no arbitrary command input from dispatch."""
    require(repo_dir in {B, F} and SHA.fullmatch(sha), "INVALID_TARGET_REPO")
    # Approved scripts from target Git SHA, NOT possibly outdated host checkout.
    # git show the approved script without changing production working tree.
    variables = {
        "RELEASE_APPROVAL": "APPROVED_FOR_PRODUCTION",
        "RELEASE_APPROVED_SHA": sha,
        "RELEASE_CHANGE_ID": e["RELEASE_CHANGE_ID"],
        "UAT_RESTORE_EVIDENCE": e["UAT_RESTORE_EVIDENCE"],
        "BACKUP_AT_REST_VERIFIED": "true",
    }
    if is_frontend:
        variables["WEB_UAT_EVIDENCE"] = e["UAT_CLINICAL_EVIDENCE"]
    env_prefix = " ".join(k + "=" + shlex.quote(v) for k, v in variables.items())
    qdir, qsha = shlex.quote(repo_dir), shlex.quote(sha)
    return [
        "set -Eeuo pipefail",
        "umask 077",
        f"cd {qdir}",
        "test \"$(git branch --show-current)\" = main",
        "test -z \"$(git status --porcelain)\"",
        "git fetch --prune origin main",
        f"test \"$(git rev-parse origin/main)\" = {qsha}",
        "script_file=$(mktemp /tmp/hipocratiq-approved-deploy.XXXXXXXX.sh)",
        "trap 'rm -f \"$script_file\"' EXIT",
        f"git show {qsha}:scripts/deploy-production.sh > \"$script_file\"",
        "test -s \"$script_file\"",
        "chmod 700 \"$script_file\"",
        f"{env_prefix} bash \"$script_file\"",
    ]

def send_and_wait(cfg, commands, label, runner=subprocess.run, sleep=time.sleep):
    # AWS-RunShellScript runs on ONE verified SSM-enabled instance only.
    # No CloudWatch/S3 command output configured; only status reaches Actions.
    params = {"commands": commands, "executionTimeout": ["3600"]}
    with tempfile.TemporaryDirectory(prefix="hipocratiq-ssm-") as directory:
        f = Path(directory) / "ssm-params.json"
        f.write_text(json.dumps(params), encoding="utf-8")
        f.chmod(0o600)
        result = aws_call([
            "ssm", "send-command", "--document-name", "AWS-RunShellScript",
            "--instance-ids", cfg["instance"], "--comment",
            "HIPOCRATIQ approved " + label + " release",
            "--parameters", "file://" + str(f),
            "--timeout-seconds", "3600",
        ], cfg["region"], runner)
    command_id = result.get("Command", {}).get("CommandId")
    require(isinstance(command_id, str)
            and re.fullmatch(r"[A-Za-z0-9-]{15,55}", command_id),
            "SSM_COMMAND_NOT_CONFIRMED")
    for _ in range(180):  # 60 min max; no output details in public CI logs
        sleep(20)
        try:
            status = aws_call([
                "ssm", "get-command-invocation",
                "--command-id", command_id, "--instance-id", cfg["instance"]
            ], cfg["region"], runner).get("Status")
        except DeploymentError:
            # Eventual consistency of GetCommandInvocation; bounded retry.
            continue
        if status == "Success":
            print(label + "_SSM_STATUS=SUCCESS")
            return
        if status in {"Failed", "Cancelled", "TimedOut", "Cancelling", "Delivery Timed Out",
                      "Execution Timed Out", "Undeliverable", "Terminated"}:
            raise DeploymentError(label + "_SSM_NOT_SUCCESSFUL")
    raise DeploymentError(label + "_SSM_STATUS_UNCONFIRMED")

def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=["validate", "deploy"], required=True)
    args = parser.parse_args(argv)
    try:
        e = os.environ
        cfg = validate_env(e)
        backend_command = command_lines(B, e["DEPLOY_BACKEND_SHA"], e, False)
        frontend_command = command_lines(F, e["DEPLOY_FRONTEND_SHA"], e, True)
        if args.mode == "validate":
            print("CONTROLLED_DEPLOY_PREFLIGHT=VALIDATED_NO_AWS_CALLS")
            print("PRODUCTION_CHANGED=false")
            return 0
        preflight_aws(cfg)
        # Backend first; if it fails, Frontend is NEVER touched.
        # If frontend fails after backend success, halt and request operator
        # intervention. Backend database migrations are NOT auto-reversible.
        send_and_wait(cfg, backend_command, "BACKEND")
        send_and_wait(cfg, frontend_command, "FRONTEND")
        print("CONTROLLED_DEPLOY=SSM_SCRIPTS_SUCCEEDED")
        print("REVIEW_POST_DEPLOY_HEALTH_AND_RUNTIME_SHA=REQUIRED")
        return 0
    except DeploymentError as ex:
        print("CONTROLLED_DEPLOY=NO_GO_" + str(ex), file=sys.stderr)
        return 2

if __name__ == "__main__":
    raise SystemExit(main())
