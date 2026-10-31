#!/usr/bin/env python3
"""Non-invasive evidence: public HTTPS metadata and AWS EC2/SSM describe only.

NO deployment, credentials, patient data, SSH, SSM SendCommand or DB calls.
Neither HTTP availability nor EC2 metadata proves which Docker code is running.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener

DOMAIN = "https://hipocratiq.com"
TARGETS = {
    "frontend": DOMAIN + "/",
    "backend": DOMAIN + "/backend/api/health/ready",
}
SHA_PATTERN = re.compile(r"[0-9a-f]{40}\Z")
ACCOUNT_PATTERN = re.compile(r"\d{12}\Z")
ID_PATTERN = re.compile(r"i-[0-9a-f]{8,17}\Z")
VPC_PATTERN = re.compile(r"vpc-[0-9a-f]{8,17}\Z")
REGION_PATTERN = re.compile(r"[a-z]{2}-[a-z]+-\d\Z")

class EvidenceError(Exception):
    pass

class DoNotRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def public_probe(opener=None):
    """Never log cookies, bodies, redirect targets or application secrets."""
    client = opener or build_opener(DoNotRedirect)
    report = {}
    for name, url in TARGETS.items():
        result = {"url": url, "reachable": False, "http_status": None,
                  "reported_sha": None, "sha_source": None}
        try:
            request = Request(url, method="GET",
                              headers={"User-Agent": "Hipocratiq-CI-ReadOnly/1.0",
                                       "Accept": "application/json,text/html"})
            with client.open(request, timeout=12) as response:
                result["http_status"] = int(response.status)
                result["reachable"] = 200 <= response.status < 300
                # Only documented, non-sensitive release headers. Never echo
                # arbitrary response headers or scrape HTML/user information.
                header_sha = response.headers.get("X-Hipocratiq-Release-Sha", "").lower()
                if SHA_PATTERN.fullmatch(header_sha):
                    result["reported_sha"] = header_sha
                    result["sha_source"] = "application_release_header"
                else:
                    # Some health endpoints may offer a version identifier.
                    # Parsing is bounded; response body is never retained.
                    if name == "backend" and "json" in response.headers.get("Content-Type", ""):
                        body = response.read(4097)
                        if len(body) <= 4096:
                            data = json.loads(body)
                            if isinstance(data, dict):
                                candidate = data.get("release_sha")
                                if not candidate and isinstance(data.get("data"), dict):
                                    candidate = data["data"].get("release_sha")
                                if isinstance(candidate, str) and SHA_PATTERN.fullmatch(candidate.lower()):
                                    result["reported_sha"] = candidate.lower()
                                    result["sha_source"] = "application_health_json"
        except (HTTPError, URLError, TimeoutError, OSError, ValueError, json.JSONDecodeError):
            # Never imply service is down: network failures can be runner-specific.
            pass
        report[name] = result

    identified = all(item["reported_sha"] for item in report.values())
    return {
        "gate": "HIPOCRATIQ_PUBLIC_RELEASE_IDENTIFICATION",
        "status": "APPLICATION_REPORTED_SHA_REQUIRES_INDEPENDENT_VALIDATION"
                  if identified else "RUNTIME_SHA_NOT_VERIFIED",
        "endpoints": report,
        "aws_accessed": False,
        "clinical_data_accessed": False,
        "production_changed": False,
        "limitations": [
            "An HTTP response is not proof of the current EC2 Docker image or its source commit.",
            "Missing release SHA is NO EVIDENCE, not proof of a particular GitHub main version.",
            "No clinical login, OTP, cookies or patient records were queried.",
        ],
    }

def aws_call(command, runner=None):
    safe = {
        ("sts", "get-caller-identity"),
        ("ec2", "describe-instances"),
        ("ec2", "describe-instance-status"),
        ("ssm", "describe-instance-information"),
    }
    if tuple(command[:2]) not in safe:
        raise EvidenceError("AWS action not authorized by read-only allowlist")
    proc = (runner or subprocess.run)(
        ["aws", *command, "--region", os.environ.get("AUDIT_AWS_REGION", ""),
         "--output", "json", "--no-cli-pager"],
        capture_output=True, text=True, timeout=25, check=False,
    )
    if proc.returncode:
        raise EvidenceError("AWS describe request unsuccessful")
    try:
        return json.loads(proc.stdout)
    except (ValueError, TypeError):
        raise EvidenceError("Malformed AWS describe response") from None

def aws_probe(env, runner=None):
    """EC2 identification, NOT deployed source/version identification."""
    account = env.get("AUDIT_AWS_ACCOUNT_ID", "")
    region = env.get("AUDIT_AWS_REGION", "")
    instance = env.get("AUDIT_AWS_INSTANCE_ID", "")
    vpc = env.get("AUDIT_AWS_VPC_ID", "")
    role = env.get("AUDIT_AWS_ROLE_ARN", "")
    if not (env.get("GITHUB_REPOSITORY") == "VaftecPeru/Hipocratiq_backend"
            and env.get("GITHUB_REF") == "refs/heads/develop"
            and env.get("GITHUB_EVENT_NAME") == "push"
            and env.get("AUDIT_ENABLED") == "true"
            and env.get("AUDIT_PUSH_APPROVED") == "true"
            and env.get("AUDIT_ACKNOWLEDGEMENT") == "READ_ONLY_PROD_METADATA"):
        raise EvidenceError("Read-only production metadata inspection not explicitly approved")
    if not (ACCOUNT_PATTERN.fullmatch(account) and REGION_PATTERN.fullmatch(region)
            and ID_PATTERN.fullmatch(instance) and VPC_PATTERN.fullmatch(vpc)):
        raise EvidenceError("Invalid verified AWS target identifiers")
    if role != f"arn:aws:iam::{account}:role/HIPOCRATIQ-PROD-ReadOnly":
        raise EvidenceError("Only the exact approved production metadata role is allowed")

    # No credentials or arbitrary runtime command are requested by this script.
    previous_region = os.environ.get("AUDIT_AWS_REGION")
    os.environ["AUDIT_AWS_REGION"] = region
    try:
        caller = aws_call(["sts", "get-caller-identity"], runner)
        if caller.get("Account") != account:
            raise EvidenceError("Caller AWS account mismatch")
        if not str(caller.get("Arn", "")).startswith(
            f"arn:aws:sts::{account}:assumed-role/HIPOCRATIQ-PROD-ReadOnly/"
        ):
            raise EvidenceError("Unexpected AWS principal")
        response = aws_call(["ec2", "describe-instances", "--instance-ids", instance], runner)
        instances = [x for group in response.get("Reservations", [])
                     for x in group.get("Instances", [])]
        if len(instances) != 1 or instances[0].get("InstanceId") != instance:
            raise EvidenceError("Production EC2 was not uniquely identified")
        if instances[0].get("VpcId") != vpc:
            raise EvidenceError("Production VPC mismatch")
        health = aws_call(["ec2", "describe-instance-status", "--instance-ids", instance,
                           "--include-all-instances"], runner)
        ssm = aws_call(["ssm", "describe-instance-information",
                        "--filters", f"Key=InstanceIds,Values={instance}"], runner)
    finally:
        if previous_region is None:
            os.environ.pop("AUDIT_AWS_REGION", None)
        else:
            os.environ["AUDIT_AWS_REGION"] = previous_region

    health_items = health.get("InstanceStatuses", [])
    ssm_items = ssm.get("InstanceInformationList", [])
    status_ok = len(health_items) == 1 and all(
        health_items[0].get(field, {}).get("Status") == "ok"
        for field in ("SystemStatus", "InstanceStatus")
    )
    ssm_online = (len(ssm_items) == 1
                  and ssm_items[0].get("InstanceId") == instance
                  and ssm_items[0].get("PingStatus") == "Online")

    return {
        "gate": "HIPOCRATIQ_AWS_PRODUCTION_METADATA_ONLY",
        "status": "INFRASTRUCTURE_IDENTIFIED_SOURCE_SHA_UNVERIFIED",
        "account_verified": True,
        "region": region,
        "instance_id": instance,
        "vpc_verified": True,
        "ec2_state": instances[0].get("State", {}).get("Name", "unknown"),
        "ec2_status_ok": status_ok,
        "ssm_agent_online": ssm_online,
        "docker_image_ids": None,
        "deployed_backend_sha": None,
        "deployed_frontend_sha": None,
        "no_ssm_commands_or_ssh": True,
        "clinical_data_accessed": False,
        "production_changed": False,
        "limitations": [
            "EC2 Describe* and SSM DescribeInstanceInformation cannot read Docker containers.",
            "GitHub main/develop SHAs MUST NOT be presented as deployed runtime SHAs.",
            "Container image revision labels are not currently part of the Dockerfiles.",
            "A separately approved runtime inspection or new versioned deployment is needed.",
        ],
    }

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=["public", "aws"], required=True)
    parser.add_argument("--report", required=True)
    args = parser.parse_args()
    try:
        result = public_probe() if args.mode == "public" else aws_probe(os.environ)
        from pathlib import Path
        target = Path(args.report)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n",
                          encoding="utf-8")
        print("EVIDENCE_STATUS=" + result["status"])
        print("SHA_VERIFIED=false")  # No supported production SHA proof in these modes.
        return 0
    except (EvidenceError, OSError, subprocess.TimeoutExpired) as exc:
        # Deliberately omit AWS exception details or response bodies.
        print("EVIDENCE_STATUS=INCOMPLETE_NO_GO", file=sys.stderr)
        return 2

if __name__ == "__main__":
    raise SystemExit(main())
