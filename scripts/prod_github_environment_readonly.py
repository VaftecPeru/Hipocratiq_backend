#!/usr/bin/env python3
"""Administrative GET-only verification of the existing production metadata Environment.

Run --live only on an authorized workstation with a scoped GitHub read token.
Default/offline operation never calls GitHub or AWS. No settings are modified.
"""
import argparse
import json
import os
import sys
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener

from prod_version_evidence_readonly import APPROVED_PROD_TARGET

ROOT = "/repos/VaftecPeru/Hipocratiq_backend"
ENV = "hipocratiq-prod-readonly"
ENV_PATH = ROOT + "/environments/" + ENV
BRANCH_PATH = ENV_PATH + "/deployment-branch-policies?per_page=100"
REPO_VARS = ROOT + "/actions/variables?per_page=100"
ENV_VARS = ENV_PATH + "/variables?per_page=100"
PATHS = {ENV_PATH, BRANCH_PATH, REPO_VARS, ENV_VARS}
EXPECTED = {
    "HIPOCRATIQ_PROD_AWS_ACCOUNT_ID": APPROVED_PROD_TARGET["account"],
    "HIPOCRATIQ_PROD_AWS_REGION": APPROVED_PROD_TARGET["region"],
    "HIPOCRATIQ_PROD_INSTANCE_ID": APPROVED_PROD_TARGET["instance"],
    "HIPOCRATIQ_PROD_VPC_ID": APPROVED_PROD_TARGET["vpc"],
    "HIPOCRATIQ_PROD_READONLY_ROLE_ARN":
        "arn:aws:iam::" + APPROVED_PROD_TARGET["account"] + ":role/HIPOCRATIQ-PROD-ReadOnly",
}
# These two values are read by the job-level conditional before entering Environment.
OPT_INS = {
    "HIPOCRATIQ_PROD_METADATA_ENABLED": "true",
    "HIPOCRATIQ_PROD_METADATA_ACK": "READ_ONLY_PROD_METADATA",
}
PUSH = "HIPOCRATIQ_PROD_METADATA_ON_DEVELOP_PUSH"


class AuditError(Exception):
    pass


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def get_json(path, token, opener=None):
    if path not in PATHS or not token:
        raise AuditError("READ_NOT_AUTHORIZED")
    request = Request("https://api.github.com" + path, method="GET", headers={
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
    })
    try:
        with (opener or build_opener(NoRedirect)).open(request, timeout=15) as response:
            body = response.read(500001)
        if len(body) > 500000:
            raise AuditError("RESPONSE_TOO_LARGE")
        result = json.loads(body)
        if not isinstance(result, dict):
            raise AuditError("INVALID_RESPONSE")
        return result
    except (HTTPError, URLError, OSError, TimeoutError, ValueError):
        raise AuditError("ADMIN_READ_UNAVAILABLE") from None


def variable_map(payload):
    rows = payload.get("variables")
    if not isinstance(rows, list) or payload.get("total_count") != len(rows):
        raise AuditError("VARIABLE_LIST_INCOMPLETE")
    result = {}
    for row in rows:
        if not isinstance(row, dict) or not isinstance(row.get("name"), str) or not isinstance(row.get("value"), str) or row["name"] in result:
            raise AuditError("INVALID_VARIABLE_LIST")
        result[row["name"]] = row["value"]
    return result


def evaluate(environment, branches, repo_vars, env_vars):
    blockers = []
    if environment.get("name") != ENV:
        blockers.append("PROD_ENVIRONMENT_UNVERIFIED")
    policy = environment.get("deployment_branch_policy") or {}
    if policy.get("custom_branch_policies") is not True or policy.get("protected_branches") is not False:
        blockers.append("PROD_ENVIRONMENT_CUSTOM_BRANCH_POLICY_REQUIRED")
    allowed = branches.get("branch_policies")
    if not isinstance(allowed, list) or branches.get("total_count") != 1 or len(allowed) != 1 or allowed[0].get("name") != "develop" or allowed[0].get("type") != "branch":
        blockers.append("PROD_ENVIRONMENT_DEVELOP_ONLY_NOT_VERIFIED")
    reviews = [r for r in environment.get("protection_rules", []) if r.get("type") == "required_reviewers"]
    if len(reviews) != 1 or not any((r.get("reviewer") or {}).get("id") for r in reviews[0].get("reviewers", [])):
        blockers.append("PROD_ENVIRONMENT_REVIEWERS_UNVERIFIED")
    if len(reviews) != 1 or (reviews[0].get("prevent_self_review") is not True and environment.get("prevent_self_review") is not True):
        blockers.append("PROD_ENVIRONMENT_SELF_REVIEW_NOT_PREVENTED")
    # An absent API field is not proof that administrators cannot bypass protection.
    if environment.get("can_admins_bypass") is not False:
        blockers.append("PROD_ENVIRONMENT_ADMIN_BYPASS_NOT_VERIFIED")
    for key, expected in OPT_INS.items():
        if repo_vars.get(key) != expected:
            blockers.append("REPO_OPT_IN_NOT_VERIFIED_" + key)
        if key in env_vars and env_vars[key] != expected:
            blockers.append("ENV_OPT_IN_CONFLICT_" + key)
    if repo_vars.get(PUSH, "false") != "false" or env_vars.get(PUSH, "false") != "false":
        blockers.append("AUTOMATIC_PROD_METADATA_PUSH_MUST_BE_DISABLED")
    for key, expected in EXPECTED.items():
        effective = env_vars.get(key, repo_vars.get(key))
        if effective != expected:
            blockers.append("TARGET_NOT_VERIFIED_" + key)
        if key in env_vars and key in repo_vars and env_vars[key] != repo_vars[key]:
            blockers.append("TARGET_SCOPE_CONFLICT_" + key)
    return blockers


def inspect(token, read=get_json):
    try:
        blockers = evaluate(read(ENV_PATH, token), read(BRANCH_PATH, token),
                            variable_map(read(REPO_VARS, token)), variable_map(read(ENV_VARS, token)))
    except (AuditError, TypeError, AttributeError, KeyError):
        blockers = ["GITHUB_ADMIN_CONFIGURATION_UNVERIFIED"]
    return {
        "status": "GITHUB_SETTINGS_VERIFIED_NOT_AWS_APPROVAL" if not blockers else "NO_GO",
        "blockers": blockers,
        "aws_api_calls": 0,
        "settings_modified": False,
        "iam_role_live_verified": False,
        "ec2_live_verified": False,
        "release_authorized": False,
    }


def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--live", action="store_true")
    args = parser.parse_args(argv)
    if not args.live:
        print("PROD_ENVIRONMENT_AUDIT=OFFLINE_NO_API_CALLS")
        return 0
    if os.environ.get("CI") or os.environ.get("GITHUB_ACTIONS"):
        print("PROD_ENVIRONMENT_AUDIT=REFUSED_ON_CI", file=sys.stderr)
        return 2
    token = os.environ.get("HIPOCRATIQ_GH_ADMIN_READ_TOKEN", "")
    if not token:
        print("PROD_ENVIRONMENT_AUDIT=ADMIN_READ_TOKEN_REQUIRED", file=sys.stderr)
        return 2
    report = inspect(token)
    print(json.dumps(report, sort_keys=True))
    return 2 if report["blockers"] else 0


if __name__ == "__main__":
    sys.exit(main())
