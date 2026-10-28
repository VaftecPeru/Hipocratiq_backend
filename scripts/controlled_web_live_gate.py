#!/usr/bin/env python3
"""Fail-closed GitHub preflight for controlled web release on existing AWS.
This is NOT production authorization and cannot verify clinical records or AWS backups.
"""
import json
import os
import sys
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError

REPO = "VaftecPeru/Hipocratiq_backend"
REQUIRED_ISSUES = (92, 102, 113)

def check(event, backend_sha, issues):
    blockers = []
    pr = event.get("pull_request", {})
    if pr.get("base", {}).get("ref") != "main":
        blockers.append("Not a main release PR.")
    if pr.get("head", {}).get("repo", {}).get("full_name") != REPO:
        blockers.append("Unapproved source repository.")
    if pr.get("head", {}).get("sha") != backend_sha or len(backend_sha) != 40:
        blockers.append("Release SHA does not match immutable candidate.")
    for issue in REQUIRED_ISSUES:
        if issues.get(issue) != "closed":
            blockers.append(f"Required evidence issue #{issue} is not closed.")
    # A GitHub issue closure alone never proves the referenced evidence is authentic.
    blockers.append("Independent clinical, security, encrypted backup/restore and change attestations require manual verification.")
    return blockers

def main():
    blockers = []
    try:
        with open(os.environ["GITHUB_EVENT_PATH"], encoding="utf-8") as stream:
            event = json.load(stream)
        token = os.environ["GITHUB_TOKEN"]
        sha = os.environ["GITHUB_SHA_CANDIDATE"]
        issues = {}
        for number in REQUIRED_ISSUES:
            url = f"https://api.github.com/repos/{REPO}/issues/{number}"
            req = Request(url, headers={"Authorization": "Bearer " + token,
                                       "Accept": "application/vnd.github+json",
                                       "User-Agent": "hipocratiq-release-preflight"})
            with urlopen(req, timeout=15) as response:
                issues[number] = json.load(response).get("state")
        blockers = check(event, sha, issues)
    except (KeyError, OSError, ValueError, HTTPError, URLError, TimeoutError) as error:
        blockers = ["Evidence collection unavailable: " + type(error).__name__]
    print("HIPOCRATIQ_CONTROLLED_WEB_GATE=NO_GO")
    for reason in blockers:
        print("BLOCKER: " + reason)
    return 1

if __name__ == "__main__":
    sys.exit(main())
