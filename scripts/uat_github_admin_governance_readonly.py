#!/usr/bin/env python3
"""Read-only GitHub governance evidence: branches and OIDC Environment.

Default invocation is OFFLINE and makes zero requests. --live requires an
administrative read token, but still performs GET calls only. NO AWS access.
This is a necessary UAT prerequisite, never a production GO certificate.
"""
import argparse
import json
import os
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

API = "https://api.github.com"
OWNER = "VaftecPeru"
BACKEND = OWNER + "/Hipocratiq_backend"
FRONTEND = OWNER + "/Hipocratiq_Frontend"
ENVIRONMENT = "hipocratiq-uat-readonly"
EXPECTED_CHECKS = {
    (BACKEND, "develop"): {"lint"},
    (BACKEND, "main"): {"lint", "Release evidence / main P0 NO-GO (read-only)"},
    (FRONTEND, "develop"): {"web", "Frontend CI"},
    (FRONTEND, "main"): {"web", "Frontend CI", "Frontend E2E role routing"},
}


class GovernanceError(Exception):
    pass


def evaluate_branch(repo, branch, metadata, policy):
    """Never infer full branch protection solely from metadata.protected."""
    prefix = repo.split("/")[-1] + "_" + branch.upper()
    blockers = []
    if not isinstance(metadata, dict) or metadata.get("name") != branch:
        return [prefix + "_UNVERIFIED"]
    if metadata.get("protected") is not True:
        blockers.append(prefix + "_UNPROTECTED")
    if not isinstance(policy, dict):
        return blockers + [prefix + "_POLICY_UNAVAILABLE"]

    statuses = policy.get("required_status_checks") or {}
    contexts = set(statuses.get("contexts") or [])
    contexts.update(c.get("context") for c in statuses.get("checks") or []
                    if isinstance(c, dict) and c.get("context"))
    if not EXPECTED_CHECKS[(repo, branch)].issubset(contexts):
        blockers.append(prefix + "_REQUIRED_CHECKS_MISSING")
    if statuses.get("strict") is not True:
        blockers.append(prefix + "_OUTDATED_CHECKS_NOT_BLOCKED")

    reviewers = policy.get("required_pull_request_reviews") or {}
    if (not isinstance(reviewers, dict)
            or reviewers.get("required_approving_review_count", 0) < 1
            or reviewers.get("dismiss_stale_reviews") is not True):
        blockers.append(prefix + "_REVIEWS_NOT_ENFORCED")
    if (policy.get("enforce_admins") or {}).get("enabled") is not True:
        blockers.append(prefix + "_ADMIN_BYPASS_NOT_BLOCKED")
    for name, code in (
        ("allow_force_pushes", "FORCE_PUSH_ALLOWED"),
        ("allow_deletions", "DELETION_ALLOWED"),
    ):
        if (policy.get(name) or {}).get("enabled") is not False:
            blockers.append(prefix + "_" + code)
    if (policy.get("required_conversation_resolution") or {}).get("enabled") is not True:
        blockers.append(prefix + "_CONVERSATIONS_NOT_REQUIRED")
    return blockers


def evaluate_environment(environment, branch_policies):
    """Confirm actual enforced protection, not the YAML 'environment:' label."""
    blockers = []
    if not isinstance(environment, dict) or environment.get("name") != ENVIRONMENT:
        return ["UAT_ENVIRONMENT_UNVERIFIED"]
    deployment = environment.get("deployment_branch_policy") or {}
    if (deployment.get("custom_branch_policies") is not True
            or deployment.get("protected_branches") is not False):
        blockers.append("UAT_ENVIRONMENT_DEVELOP_ONLY_POLICY_MISSING")

    allowed = branch_policies.get("branch_policies", []) if isinstance(branch_policies, dict) else []
    if len(allowed) != 1 or allowed[0].get("name") != "develop" or (
        allowed[0].get("type") not in (None, "branch")
    ):
        blockers.append("UAT_ENVIRONMENT_ALLOWED_BRANCH_NOT_EXACT_DEVELOP")

    rules = environment.get("protection_rules") or []
    reviewer_rules = [x for x in rules if x.get("type") == "required_reviewers"]
    if len(reviewer_rules) != 1:
        blockers.append("UAT_ENVIRONMENT_REQUIRED_REVIEWERS_UNAVAILABLE")
    else:
        rule = reviewer_rules[0]
        people = rule.get("reviewers") or []
        if not any((entry.get("reviewer") or {}).get("id") for entry in people):
            blockers.append("UAT_ENVIRONMENT_NO_NAMED_REVIEWER")
        if (rule.get("prevent_self_review") is not True
                and environment.get("prevent_self_review") is not True):
            blockers.append("UAT_ENVIRONMENT_SELF_APPROVAL_NOT_PREVENTED")
    return blockers


def read_json(path, token, fetch=urlopen):
    """Only fixed allowlisted API paths are accepted. No writes or AWS calls."""
    allow = set()
    for repo, branch in EXPECTED_CHECKS:
        allow.add(f"/repos/{repo}/branches/{branch}")
        allow.add(f"/repos/{repo}/branches/{branch}/protection")
    allow.update((
        f"/repos/{BACKEND}/environments/{ENVIRONMENT}",
        f"/repos/{BACKEND}/environments/{ENVIRONMENT}/deployment-branch-policies?per_page=100",
    ))
    if path not in allow:
        raise GovernanceError("UNAUTHORIZED_API_ENDPOINT")
    if not token:
        raise GovernanceError("ADMIN_READ_TOKEN_UNAVAILABLE")
    request = Request(API + path, headers={
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "HIPOCRATIQ-UAT-Governance-ReadOnly",
    })
    try:
        with fetch(request, timeout=12) as response:
            body = response.read(300001)
        if len(body) > 300000:
            raise GovernanceError("GITHUB_RESPONSE_OVERSIZED")
        return json.loads(body)
    except (HTTPError, URLError, OSError, TimeoutError, ValueError):
        raise GovernanceError("GITHUB_ADMIN_API_READ_UNAVAILABLE") from None


def inspect(read, token):
    blockers = []
    for repo, branch in EXPECTED_CHECKS:
        root = f"/repos/{repo}/branches/{branch}"
        try:
            metadata = read(root, token)
            protection = read(root + "/protection", token)
        except GovernanceError:
            blockers.append(repo.split("/")[-1] + "_" + branch.upper() + "_ADMIN_EVIDENCE_UNAVAILABLE")
            continue
        blockers += evaluate_branch(repo, branch, metadata, protection)
    base = f"/repos/{BACKEND}/environments/{ENVIRONMENT}"
    try:
        environment = read(base, token)
        allowed = read(base + "/deployment-branch-policies?per_page=100", token)
        blockers += evaluate_environment(environment, allowed)
    except GovernanceError:
        blockers.append("UAT_ENVIRONMENT_ADMIN_EVIDENCE_UNAVAILABLE")
    return {
        "gate": "HIPOCRATIQ_GITHUB_ADMIN_GOVERNANCE_READONLY",
        "status": "CONFIG_VERIFIED_NOT_AWS_RELEASE_APPROVAL" if not blockers else "NO_GO",
        "blockers": blockers,
        "aws_api_calls": 0,
        "github_api_method": "GET",
        "production_changes": 0,
        "aws_oidc_role_verified": False,
        "clinical_uat_verified": False,
    }


def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--live", action="store_true",
                        help="Read existing GitHub branch and Environment settings; never write.")
    args = parser.parse_args(argv)
    if not args.live:
        print("UAT_GOVERNANCE=OFFLINE_NO_GITHUB_OR_AWS_READ")
        print("Use --live with a GitHub ADMIN READ token on an approved admin runner.")
        return 0
    token = os.environ.get("HIPOCRATIQ_GH_ADMIN_READ_TOKEN", "")
    if not token:
        print("UAT_GOVERNANCE=NO_GO: ADMIN_READ_TOKEN_UNAVAILABLE", file=sys.stderr)
        return 2
    result = inspect(read_json, token)
    print("UAT_GOVERNANCE=" + result["status"])
    for blocker in result["blockers"]:
        print("BLOCKER: " + blocker)
    # Intentionally never print IDs, tokens, policies, reviewers or account data.
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as f:
            f.write("## GitHub UAT governance (read-only)\n\n")
            f.write(result["status"] + "\n\n")
            f.write("\n".join("- " + x for x in result["blockers"]) + "\n")
            f.write("\nAWS/OIDC and clinical release still require separate evidence.\n")
    return 2 if result["blockers"] else 0


if __name__ == "__main__":
    sys.exit(main())
