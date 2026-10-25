#!/usr/bin/env python3
"""Fail-closed verification of the exact Frontend candidate tested with Backend.

Queries only public GitHub REST endpoints. No credentials, deployments, PHI, or
repository changes. The Backend's full-stack integration MUST exercise the
current Frontend develop and release PR #80 commit, not an earlier build.
"""
import argparse
import json
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

FRONTEND_REPO = "VaftecPeru/Hipocratiq_Frontend"
FRONTEND_RELEASE_PR = 80
GITHUB_API = "https://api.github.com"


class ReleaseSyncError(Exception):
    pass


def validate_commit_sha(sha):
    if not isinstance(sha, str) or not re.fullmatch(r"[a-f0-9]{40}", sha):
        raise ReleaseSyncError("An exact 40-character lowercase commit SHA is required.")
    return sha


def fetch_public_json(path):
    allowed = {
        f"/repos/{FRONTEND_REPO}/branches/develop",
        f"/repos/{FRONTEND_REPO}/pulls/{FRONTEND_RELEASE_PR}",
    }
    if path not in allowed:
        raise ReleaseSyncError("Disallowed GitHub evidence path.")
    request = Request(GITHUB_API + path, headers={
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "HIPOCRATIQ-FullStack-Release-Sync",
    })
    try:
        with urlopen(request, timeout=12) as response:
            data = response.read(200001)
        if len(data) > 200000:
            raise ReleaseSyncError("GitHub evidence response too large.")
        value = json.loads(data)
    except (HTTPError, URLError, OSError, TimeoutError, ValueError):
        raise ReleaseSyncError("Unable to verify current Frontend release evidence.") from None
    if not isinstance(value, dict):
        raise ReleaseSyncError("Invalid GitHub evidence.")
    return value


def verify_frontend_sync(expected, develop, release_pr):
    expected = validate_commit_sha(expected)
    blockers = []
    if (develop.get("commit") or {}).get("sha") != expected:
        blockers.append("Frontend develop has advanced beyond the pinned integration SHA.")
    if release_pr.get("state") != "open":
        blockers.append("Frontend release PR #80 must remain open.")
    if (release_pr.get("base") or {}).get("ref") != "main":
        blockers.append("Frontend release PR #80 must target main.")
    head = release_pr.get("head") or {}
    if head.get("sha") != expected:
        blockers.append("Frontend release PR #80 HEAD differs from the pinned SHA.")
    if (head.get("repo") or {}).get("full_name") != FRONTEND_REPO:
        blockers.append("Frontend release PR #80 must originate from the approved repository.")
    if blockers:
        raise ReleaseSyncError(" ".join(blockers))
    return {
        "status": "FRONTEND_RELEASE_SHA_MATCH",
        "tested_sha": expected,
        "frontend_develop_matches": True,
        "frontend_release_head_matches": True,
        "frontend_release_is_draft": release_pr.get("draft") is True,
        "note": "Source coherence only: clinical release may still be NO-GO.",
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--expected", required=True)
    args = parser.parse_args()
    try:
        expected = validate_commit_sha(args.expected)
        develop = fetch_public_json(f"/repos/{FRONTEND_REPO}/branches/develop")
        release_pr = fetch_public_json(f"/repos/{FRONTEND_REPO}/pulls/{FRONTEND_RELEASE_PR}")
        result = verify_frontend_sync(expected, develop, release_pr)
        print(json.dumps(result, sort_keys=True))
        return 0
    except ReleaseSyncError as exc:
        print("FRONTEND_RELEASE_SYNC=NO_GO: " + str(exc), file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
