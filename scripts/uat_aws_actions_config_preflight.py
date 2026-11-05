#!/usr/bin/env python3
"""Offline-only AWS UAT GitHub Actions configuration diagnosis.

This program reads *only* local GitHub Actions environment variables.
It never uses AWS/GitHub APIs, acquires credentials or prints configured values.
A passing syntax check is NOT proof of AWS resources, OIDC trust or deployment safety.
"""
import json
import os
import re
import sys
from pathlib import Path

REPOSITORY = "VaftecPeru/Hipocratiq_backend"
PREFIX = "HIPOCRATIQ_"
NAMES = (
    "HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED",
    "HIPOCRATIQ_UAT_AWS_ACCOUNT_ID",
    "HIPOCRATIQ_UAT_AWS_REGION",
    "HIPOCRATIQ_UAT_INSTANCE_ID",
    "HIPOCRATIQ_PROD_INSTANCE_ID",
    "HIPOCRATIQ_UAT_VPC_ID",
    "HIPOCRATIQ_PROD_VPC_ID",
    "HIPOCRATIQ_UAT_READONLY_ROLE_ARN",
)
PATTERNS = {
    "HIPOCRATIQ_UAT_AWS_ACCOUNT_ID": r"\d{12}",
    "HIPOCRATIQ_UAT_AWS_REGION": r"[a-z]{2}-[a-z]+-\d",
    "HIPOCRATIQ_UAT_INSTANCE_ID": r"i-[0-9a-f]{8,17}",
    "HIPOCRATIQ_PROD_INSTANCE_ID": r"i-[0-9a-f]{8,17}",
    "HIPOCRATIQ_UAT_VPC_ID": r"vpc-[0-9a-f]{8,17}",
    "HIPOCRATIQ_PROD_VPC_ID": r"vpc-[0-9a-f]{8,17}",
}
MANUAL_STEPS = [
    "Confirm AWS GitHub OIDC provider and HIPOCRATIQ-UAT-ReadOnly role actually exist.",
    "Confirm IAM trust subject pins the GitHub UAT Environment and STS audience.",
    "Confirm hipocratiq-uat-readonly Environment reviewers and develop-only deployment policy.",
    "Corroborate account, EC2 and VPC UAT/production identifiers independently.",
    "Run the existing manual AWS UAT Inventory from develop with explicit acknowledgement.",
    "Confirm real AWS job SUCCESS and review retained redacted inventory artifact.",
]


def check_variables(env):
    """Return sanitized readiness evidence. No values appear in the report."""
    blockers = []
    checks = {}
    for name in NAMES:
        value = env.get(name, "")
        configured = bool(value)
        valid_format = configured
        if name == "HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED":
            valid_format = value == "true"
        elif name in PATTERNS:
            valid_format = bool(re.fullmatch(PATTERNS[name], value))
        elif name == "HIPOCRATIQ_UAT_READONLY_ROLE_ARN":
            account = env.get("HIPOCRATIQ_UAT_AWS_ACCOUNT_ID", "")
            valid_format = bool(
                re.fullmatch(
                    rf"arn:aws:iam::{re.escape(account)}:role/(?:[\w+=,.@/-]*/)?HIPOCRATIQ-UAT-ReadOnly",
                    value,
                )
            ) if re.fullmatch(r"\d{12}", account) else False

        checks[name] = {
            "configured": configured,
            "syntax_or_opt_in_valid": valid_format,
        }
        if not configured:
            blockers.append("MISSING_" + name)
        elif not valid_format:
            blockers.append("INVALID_" + name)

    for first, second, label in (
        ("HIPOCRATIQ_UAT_INSTANCE_ID", "HIPOCRATIQ_PROD_INSTANCE_ID", "EC2"),
        ("HIPOCRATIQ_UAT_VPC_ID", "HIPOCRATIQ_PROD_VPC_ID", "VPC"),
    ):
        if env.get(first) and env.get(second) and env[first] == env[second]:
            blockers.append("UAT_PRODUCTION_" + label + "_IDENTICAL")

    # For actual AWS execution prefer manual dispatch behind Environment review,
    # never surprise inventory calls on a normal develop push.
    if env.get("HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH") == "true":
        blockers.append("DISABLE_AUTOMATIC_DEVELOP_PUSH_INVENTORY")

    if env.get("GITHUB_REPOSITORY") != REPOSITORY:
        blockers.append("UNEXPECTED_REPOSITORY")

    return {
        "gate": "HIPOCRATIQ_GITHUB_AWS_UAT_CONFIG_OFFLINE",
        "status": "CONFIG_SYNTAX_PASS_NOT_AWS_VERIFIED" if not blockers else "BLOCKED_NO_AWS_DIAGNOSTIC",
        "aws_api_requests": 0,
        "cloud_resources_verified": False,
        "actual_oidc_assumption_verified": False,
        "configuration_checks": checks,
        "visibility_scope": "workflow_without_github_environment",
        "visibility_warning": (
            "An absent variable is not visible to this offline job; "
            "it may exist in GitHub Environment scope. "
            "This result cannot establish AWS/GitHub admin configuration."
        ),
        "blocker_codes": blockers,
        "manual_preconditions_unverified": MANUAL_STEPS,
    }


def report_no_sensitive_values(report, env):
    # Both artifact and summary contain only booleans and variable NAMES.
    path = env.get("UAT_READINESS_REPORT_PATH")
    if path:
        p = Path(path)
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    summary = env.get("GITHUB_STEP_SUMMARY")
    if summary:
        with Path(summary).open("a", encoding="utf-8") as f:
            f.write("## HIPOCRATIQ — Offline AWS UAT Actions readiness\n\n")
            f.write("**" + report["status"] + "** — No AWS or production access performed.\n\n")
            for name, state in report["configuration_checks"].items():
                f.write("- " + name + ": " +
                        ("format/opt-in OK" if state["syntax_or_opt_in_valid"]
                         else "missing or not ready") + "\n")
            if report["blocker_codes"]:
                f.write("\nUnmet prerequisites:\n")
                for blocker in report["blocker_codes"]:
                    f.write("- " + blocker + "\n")
            f.write("\n**Scope:** This offline job has no GitHub Environment; an absent variable may be Environment-scoped.\n")
            f.write("**Unverified:** GitHub Environment approvals, OIDC trust, actual resource IDs and live EC2/VPC.\n")


def main():
    data = check_variables(os.environ)
    report_no_sensitive_values(data, os.environ)
    print("UAT_AWS_CONFIG_DIAGNOSTIC=" + data["status"])
    # The workflow should succeed as a diagnostic, even if it correctly
    # identifies missing prerequisites. A separate release GO gate remains fail-closed.
    return 0


if __name__ == "__main__":
    sys.exit(main())
