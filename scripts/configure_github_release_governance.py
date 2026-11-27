#!/usr/bin/env python3
"""HIPOCRATIQ GitHub admin bootstrap. OFFLINE plan is default; --apply is explicit.

Targets ONLY the two audited GitHub repositories, develop/main branch protection
and the Backend's read-only UAT Environment. No AWS API calls, workflows, secrets,
workflow dispatch, production deployment, patient data or main-branch commits.
Use a GitHub admin-scoped token on a trusted workstation, NEVER GitHub Actions.
"""
import argparse
import json
import os
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

API = "https://api.github.com"
BACKEND = "VaftecPeru/Hipocratiq_backend"
FRONTEND = "VaftecPeru/Hipocratiq_Frontend"
ENVIRONMENT = "hipocratiq-uat-readonly"
ACK = "HIPOCRATIQ_GITHUB_GOVERNANCE_ONLY"
REQUIRED = {
    (BACKEND, "develop"): (
        "lint", "Actual Next.js to Laravel with PostgreSQL17 Redis8 (no PHI)"
    ),
    (BACKEND, "main"): (
        "lint", "Actual Next.js to Laravel with PostgreSQL17 Redis8 (no PHI)",
        "Release evidence / main P0 NO-GO (read-only)"
    ),
    (FRONTEND, "develop"): ("web", "Frontend CI"),
    (FRONTEND, "main"): ("web", "Frontend CI", "Frontend E2E role routing"),
}


class GuardError(Exception):
    pass


def protection_payload(repo, branch):
    """Exact, review-required, strict protection. No merges or deployments."""
    contexts = REQUIRED[(repo, branch)]
    return {
        "required_status_checks": {"strict": True, "contexts": list(contexts)},
        "enforce_admins": True,
        "required_pull_request_reviews": {
            "dismiss_stale_reviews": True,
            "require_code_owner_reviews": False,
            "required_approving_review_count": 1,
            "require_last_push_approval": True,
        },
        "restrictions": None,
        "required_linear_history": False,  # Existing project uses merge commits
        "allow_force_pushes": False,
        "allow_deletions": False,
        "required_conversation_resolution": True,
        "lock_branch": False,
        "block_creations": False,
        "allow_fork_syncing": False,
    }


def environment_payload(reviewer_id):
    if type(reviewer_id) is not int or reviewer_id < 1:
        raise GuardError("REVIEWER_ID_INVALID")
    return {
        "wait_timer": 0,
        "prevent_self_review": True,
        "reviewers": [{"type": "User", "id": reviewer_id}],
        "deployment_branch_policy": {
            "protected_branches": False, "custom_branch_policies": True
        },
    }


def validate_execution(args, env):
    if not args.apply:
        return
    if env.get("GITHUB_ACTIONS") == "true" or env.get("CI") == "true":
        raise GuardError("MUST_RUN_ON_TRUSTED_ADMIN_WORKSTATION_NOT_CI")
    if args.acknowledgement != ACK:
        raise GuardError("EXPLICIT_ADMIN_ACKNOWLEDGEMENT_REQUIRED")
    if not env.get("HIPOCRATIQ_GH_ADMIN_TOKEN"):
        raise GuardError("GITHUB_ADMIN_TOKEN_MISSING")
    if type(args.reviewer_id) is not int or args.reviewer_id < 1:
        raise GuardError("INDEPENDENT_REVIEWER_ID_REQUIRED")


class GithubAPI:
    """Fixed-host API; never log the admin token or response bodies."""
    def __init__(self, token, fetch=urlopen):
        if not token:
            raise GuardError("GITHUB_ADMIN_TOKEN_MISSING")
        self.token = token
        self.fetch = fetch

    def request(self, method, path, payload=None, *, allow_missing=False):
        if not path.startswith("/repos/VaftecPeru/") and path != "/user":
            raise GuardError("OUT_OF_SCOPE_GITHUB_API_REQUEST")
        if method not in ("GET", "PUT", "POST"):
            raise GuardError("HTTP_METHOD_NOT_ALLOWLISTED")
        data = json.dumps(payload).encode("utf-8") if payload is not None else None
        headers = {
            "Authorization": "Bearer " + self.token,
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "HIPOCRATIQ-Governance-Admin-Bootstrap",
        }
        if data is not None:
            headers["Content-Type"] = "application/json"
        req = Request(API + path, headers=headers, data=data, method=method)
        try:
            with self.fetch(req, timeout=15) as response:
                body = response.read(500001)
            if len(body) > 500000:
                raise GuardError("GITHUB_RESPONSE_OVERSIZED")
            return json.loads(body) if body else {}
        except HTTPError as e:
            if allow_missing and e.code == 404:
                return None
            raise GuardError(f"GITHUB_API_{method}_FAILED_HTTP_{e.code}") from None
        except (URLError, OSError, ValueError, TimeoutError):
            raise GuardError("GITHUB_API_UNAVAILABLE_OR_INVALID") from None


def branch_paths(repo, branch):
    return (f"/repos/{repo}/branches/{branch}",
            f"/repos/{repo}/branches/{branch}/protection")


def verify_branch(meta, protection, repo, branch):
    expected = REQUIRED[(repo, branch)]
    if not meta or meta.get("protected") is not True:
        raise GuardError(f"{repo}_{branch}_NOT_PROTECTED")
    if not isinstance(protection, dict):
        raise GuardError(f"{repo}_{branch}_PROTECTION_UNREADABLE")
    checks = protection.get("required_status_checks") or {}
    actual = set(checks.get("contexts") or [
        i.get("context") for i in checks.get("checks", []) if i.get("context")
    ])
    if checks.get("strict") is not True or actual != set(expected):
        raise GuardError(f"{repo}_{branch}_REQUIRED_CHECKS_MISMATCH")
    reviews = protection.get("required_pull_request_reviews") or {}
    if (reviews.get("required_approving_review_count", 0) < 1 or
        reviews.get("dismiss_stale_reviews") is not True or
        reviews.get("require_last_push_approval") is not True):
        raise GuardError(f"{repo}_{branch}_INDEPENDENT_REVIEW_MISSING")
    if protection.get("enforce_admins", {}).get("enabled") is not True:
        raise GuardError(f"{repo}_{branch}_ADMIN_BYPASS_POSSIBLE")
    if protection.get("required_conversation_resolution", {}).get("enabled") is not True:
        raise GuardError(f"{repo}_{branch}_CONVERSATIONS_NOT_REQUIRED")
    for name in ("allow_force_pushes", "allow_deletions"):
        if protection.get(name, {}).get("enabled") is not False:
            raise GuardError(f"{repo}_{branch}_{name}_UNSAFE")
    return True


def verify_environment(env, branch_rules, reviewer_id=None):
    if not isinstance(env, dict) or env.get("name") != ENVIRONMENT:
        raise GuardError("UAT_ENVIRONMENT_ABSENT")
    policy = env.get("deployment_branch_policy") or {}
    if policy.get("custom_branch_policies") is not True or policy.get("protected_branches") is not False:
        raise GuardError("UAT_ENVIRONMENT_BRANCH_POLICY_UNSAFE")
    policies = branch_rules.get("branch_policies", []) if isinstance(branch_rules, dict) else []
    if len(policies) != 1 or policies[0].get("name") != "develop" or policies[0].get("type", "branch") != "branch":
        raise GuardError("UAT_ENVIRONMENT_NOT_DEVELOP_ONLY")
    review_rules = [r for r in env.get("protection_rules", []) if r.get("type") == "required_reviewers"]
    if len(review_rules) != 1:
        raise GuardError("UAT_REQUIRED_REVIEWERS_MISSING")
    rule = review_rules[0]
    reviewers = rule.get("reviewers") or []
    if not reviewers or any((r.get("reviewer") or {}).get("id") is None for r in reviewers):
        raise GuardError("UAT_REVIEWER_NOT_VERIFIED")
    if reviewer_id and not any((r.get("reviewer") or {}).get("id") == reviewer_id for r in reviewers):
        raise GuardError("UAT_SELECTED_REVIEWER_NOT_CONFIGURED")
    if rule.get("prevent_self_review") is not True and env.get("prevent_self_review") is not True:
        raise GuardError("UAT_SELF_REVIEW_NOT_PREVENTED")
    return True


def read_governance(api, reviewer_id=None):
    for repo, branch in REQUIRED:
        root, protect = branch_paths(repo, branch)
        verify_branch(api.request("GET", root), api.request("GET", protect), repo, branch)
    root = f"/repos/{BACKEND}/environments/{ENVIRONMENT}"
    verify_environment(api.request("GET", root),
                       api.request("GET", root + "/deployment-branch-policies?per_page=100"),
                       reviewer_id=reviewer_id)
    return True


def preflight_unconfigured(api, reviewer_id):
    actor = api.request("GET", "/user")
    if actor.get("id") == reviewer_id:
        raise GuardError("REVIEWER_MUST_DIFFER_FROM_GITHUB_ADMIN_ACTOR")
    for repo in (BACKEND, FRONTEND):
        details = api.request("GET", f"/repos/{repo}")
        if details.get("full_name") != repo:
            raise GuardError("REPOSITORY_IDENTITY_MISMATCH")
        # Admin must be able to protect branches; never alter visibility.
        if details.get("permissions", {}).get("admin") is not True:
            raise GuardError("REPOSITORY_ADMIN_PERMISSION_REQUIRED")
        rulesets = api.request("GET", f"/repos/{repo}/rulesets")
        if rulesets:
            raise GuardError("EXISTING_RULESETS_REQUIRE_MANUAL_RECONCILIATION")
    for repo, branch in REQUIRED:
        root, protect = branch_paths(repo, branch)
        if api.request("GET", root).get("protected") is not False:
            raise GuardError(f"{repo}_{branch}_ALREADY_PROTECTED_REVIEW_EXISTING_FIRST")
        existing = api.request("GET", protect, allow_missing=True)
        if existing is not None:
            raise GuardError(f"{repo}_{branch}_EXISTING_PROTECTION_REQUIRES_REVIEW")
    env_url = f"/repos/{BACKEND}/environments/{ENVIRONMENT}"
    if api.request("GET", env_url, allow_missing=True) is not None:
        raise GuardError("UAT_ENVIRONMENT_EXISTS_REVIEW_BEFORE_APPLY")


def apply_governance(api, reviewer_id):
    """All preflight checks complete before first write; no rollback that unprotects."""
    preflight_unconfigured(api, reviewer_id)
    for repo, branch in REQUIRED:
        root, protect = branch_paths(repo, branch)
        api.request("PUT", protect, protection_payload(repo, branch))
        verify_branch(api.request("GET", root), api.request("GET", protect), repo, branch)
        print(f"PASS: {repo} {branch} protected with strict checks/review")
    root = f"/repos/{BACKEND}/environments/{ENVIRONMENT}"
    api.request("PUT", root, environment_payload(reviewer_id))
    api.request("POST", root + "/deployment-branch-policies",
                {"name": "develop", "type": "branch"})
    read_governance(api, reviewer_id)
    print("PASS: UAT Environment restricted to develop with independent reviewer")
    print("GITHUB_GOVERNANCE=VERIFIED_AWS_NOT_CONFIGURED_NO_RELEASE_APPROVAL")


def main(argv=None, environ=None, api=None):
    parser = argparse.ArgumentParser(description="HIPOCRATIQ fail-closed GitHub administration")
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--apply", action="store_true", help="Admin-only writes; requires explicit acknowledgement")
    group.add_argument("--verify", action="store_true", help="Read actual GitHub protection and Environment settings")
    parser.add_argument("--reviewer-id", type=int, help="Verified independent GitHub user numeric ID")
    parser.add_argument("--acknowledgement", help="Exact text to authorize only GitHub governance")
    args = parser.parse_args(argv)
    env = os.environ if environ is None else environ
    if not args.apply and not args.verify:
        print("PLAN_ONLY_NO_NETWORK: protect four branches + restricted UAT Environment.")
        for (repo, branch), checks in REQUIRED.items():
            print(repo + " " + branch + " checks: " + ", ".join(checks))
        print("No AWS calls, OIDC role creation, main merges or deployments.")
        return 0
    try:
        validate_execution(args, env)
        client = api or GithubAPI(env.get("HIPOCRATIQ_GH_ADMIN_TOKEN", ""))
        if args.verify:
            read_governance(client)
            print("GITHUB_GOVERNANCE=CONFIG_VERIFIED_NOT_AWS_OR_RELEASE_APPROVAL")
        else:
            apply_governance(client, args.reviewer_id)
        return 0
    except GuardError as error:
        print("GITHUB_GOVERNANCE=NO_GO: " + str(error), file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
