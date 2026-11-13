#!/usr/bin/env python3
"""Read-only GitHub branch-protection preflight executed BEFORE AWS OIDC.

A successful result is a necessary condition only. It does not prove that
GitHub Environment reviewers or production branch rules are configured.
"""
import json
import os
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

REPOSITORY = "VaftecPeru/Hipocratiq_backend"
BRANCH = "develop"
URL = "https://api.github.com/repos/" + REPOSITORY + "/branches/" + BRANCH


class UatGateError(Exception):
    pass


def assess_branch_protection(branch_payload, env):
    """Fail closed unless this is the exact protected develop SHA."""
    if env.get("GITHUB_REPOSITORY") != REPOSITORY:
        raise UatGateError("UNEXPECTED_REPOSITORY")
    if env.get("GITHUB_REF") != "refs/heads/develop":
        raise UatGateError("NOT_DEVELOP")
    if env.get("GITHUB_EVENT_NAME") not in ("workflow_dispatch", "push"):
        raise UatGateError("UNAUTHORIZED_TRIGGER")
    sha = env.get("GITHUB_SHA", "")
    if len(sha) != 40 or any(c not in "0123456789abcdef" for c in sha):
        raise UatGateError("INVALID_WORKFLOW_SHA")
    if not isinstance(branch_payload, dict) or branch_payload.get("name") != BRANCH:
        raise UatGateError("UNEXPECTED_BRANCH_RESPONSE")
    if branch_payload.get("protected") is not True:
        raise UatGateError("DEVELOP_BRANCH_UNPROTECTED")
    if branch_payload.get("commit", {}).get("sha") != sha:
        raise UatGateError("DEVELOP_SHA_CHANGED_OR_UNVERIFIED")
    return {"protected_develop_sha_confirmed": True,
            "environment_reviewers_verified": False,
            "aws_credentials_requested": False}


def read_github_branch(token, fetcher=urlopen):
    """GET one exact GitHub REST endpoint; no GitHub writes or AWS calls."""
    if not token:
        raise UatGateError("GITHUB_TOKEN_MISSING")
    request = Request(URL, headers={
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "HIPOCRATIQ-UAT-Protected-Develop",
    })
    try:
        with fetcher(request, timeout=10) as response:
            content = response.read(1_000_001)
        if len(content) > 1_000_000:
            raise UatGateError("GITHUB_BRANCH_RESPONSE_TOO_LARGE")
        return json.loads(content.decode("utf-8"))
    except (HTTPError, URLError, OSError, TimeoutError, ValueError, UnicodeError):
        raise UatGateError("GITHUB_BRANCH_READ_UNAVAILABLE") from None


def main():
    try:
        # Avoid even a GitHub read if the triggering repository/ref is not approved.
        env = os.environ
        if (env.get("GITHUB_REPOSITORY") != REPOSITORY
                or env.get("GITHUB_REF") != "refs/heads/develop"
                or env.get("GITHUB_EVENT_NAME") not in ("push", "workflow_dispatch")):
            raise UatGateError("UNAUTHORIZED_WORKFLOW_CONTEXT")
        data = read_github_branch(env.get("GITHUB_TOKEN", ""))
        assess_branch_protection(data, env)
    except UatGateError as exc:
        print("UAT_PROTECTED_DEVELOP=NO_GO: " + str(exc), file=sys.stderr)
        return 2
    print("UAT_PROTECTED_DEVELOP=PASS_GITHUB_READ_ONLY")
    print("Environment reviewers and AWS role trust still require independent admin verification.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
