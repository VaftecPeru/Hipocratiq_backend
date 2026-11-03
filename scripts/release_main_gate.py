#!/usr/bin/env python3
"""Read-only production release gate. NO AWS calls, PHI, deployment, or secrets.

Two modes:
  readiness: on main push, report GO/NO-GO from public GitHub metadata.
  deploy: on manual, approved main workflow, enforce additional independent evidence.
This is a necessary gate only; GitHub Environment review and AWS IAM are separate.
"""
from __future__ import annotations
import argparse
import json
import os
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

BACKEND = "VaftecPeru/Hipocratiq_backend"
FRONTEND = "VaftecPeru/Hipocratiq_Frontend"
REQUIRED_ISSUES = (92, 102, 111, 113)
SHA = re.compile(r"[0-9a-f]{40}\Z")
REFERENCE = re.compile(r"[A-Za-z0-9][A-Za-z0-9._:/-]{7,159}\Z")
ALLOWED_API_PATHS = frozenset([
    f"/repos/{BACKEND}/branches/main",
    f"/repos/{FRONTEND}/branches/main",
    *(f"/repos/{BACKEND}/issues/{n}" for n in REQUIRED_ISSUES),
])

class ReleaseGateError(Exception):
    pass

def read_github(path, token, fetcher=urlopen):
    if path not in ALLOWED_API_PATHS or not token:
        raise ReleaseGateError("UNAUTHORIZED_GITHUB_EVIDENCE_QUERY")
    req = Request("https://api.github.com" + path, headers={
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "Hipocratiq-Controlled-Main-Release",
    })
    try:
        with fetcher(req, timeout=15) as response:
            data = response.read(150001)
        if len(data) > 150000:
            raise ReleaseGateError("GITHUB_RESPONSE_TOO_LARGE")
        decoded = json.loads(data)
    except (HTTPError, URLError, ValueError, UnicodeError, OSError, TimeoutError):
        raise ReleaseGateError("GITHUB_EVIDENCE_UNAVAILABLE") from None
    if not isinstance(decoded, dict):
        raise ReleaseGateError("INVALID_GITHUB_EVIDENCE")
    return decoded

def assess(env, backend, frontend, issues, mode):
    errors = []
    event = env.get("GITHUB_EVENT_NAME", "")
    if env.get("GITHUB_REPOSITORY") != BACKEND:
        errors.append("UNEXPECTED_REPOSITORY")
    if env.get("GITHUB_REF") != "refs/heads/main":
        errors.append("NOT_MAIN")
    if event not in {"push", "workflow_dispatch"}:
        errors.append("UNAUTHORIZED_EVENT")
    if mode == "deploy" and event != "workflow_dispatch":
        errors.append("DEPLOY_MUST_BE_MANUAL")
    sha = env.get("GITHUB_SHA", "")
    if not SHA.fullmatch(sha):
        errors.append("INVALID_BACKEND_SHA")
    if backend.get("name") != "main" or backend.get("protected") is not True:
        errors.append("BACKEND_MAIN_NOT_PROTECTED")
    if frontend.get("name") != "main" or frontend.get("protected") is not True:
        errors.append("FRONTEND_MAIN_NOT_PROTECTED")
    if (backend.get("commit") or {}).get("sha") != sha:
        errors.append("BACKEND_MAIN_SHA_MISMATCH")
    frontend_sha = (frontend.get("commit") or {}).get("sha", "")
    if not SHA.fullmatch(frontend_sha):
        errors.append("FRONTEND_MAIN_SHA_NOT_VERIFIABLE")
    for number in REQUIRED_ISSUES:
        item = issues.get(number) or {}
        if item.get("number") != number or item.get("state") != "closed":
            errors.append(f"P0_{number}_NOT_CLOSED")
    if mode == "deploy":
        if env.get("RELEASE_ACK") != "APPROVED_HIPOCRATIQ_CONTROLLED_PRODUCTION":
            errors.append("PRODUCTION_ACK_MISSING")
        if env.get("APPROVED_FRONTEND_SHA") != frontend_sha:
            errors.append("FRONTEND_SHA_NOT_APPROVED")
        if env.get("RELEASE_DEPLOY_ENABLED") != "true":
            errors.append("PRODUCTION_DEPLOY_NOT_ENABLED")
        for variable in (
            "RELEASE_CHANGE_ID", "UAT_CLINICAL_EVIDENCE", "SECURITY_EVIDENCE",
            "UAT_RESTORE_EVIDENCE", "BACKUP_AT_REST_EVIDENCE",
        ):
            if not REFERENCE.fullmatch(env.get(variable, "")):
                errors.append(variable + "_MISSING_OR_INVALID")
    return {
        "status": "GO_PRELIMINARY_REQUIRES_HUMAN_AWS_REVIEW" if not errors
                  else "NO_GO",
        "blockers": errors,
        "backend_sha_matches_main": sha == (backend.get("commit") or {}).get("sha"),
        "frontend_main_sha_verified": bool(SHA.fullmatch(frontend_sha)),
        "live_aws_runtime_verified": False,
        "clinical_records_accessed": False,
        "production_changed": False,
        "release_authorized_by_this_job": False,
    }

def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=["readiness", "deploy"], required=True)
    args = parser.parse_args(argv)
    env = os.environ
    if (env.get("GITHUB_REPOSITORY") != BACKEND
        or env.get("GITHUB_REF") != "refs/heads/main"
        or env.get("GITHUB_EVENT_NAME") not in ("push", "workflow_dispatch")
        or (args.mode == "deploy" and env.get("GITHUB_EVENT_NAME") != "workflow_dispatch")):
        print("MAIN_RELEASE=NO_GO_UNAUTHORIZED_CONTEXT", file=sys.stderr)
        return 2
    token = env.get("GITHUB_TOKEN", "")
    try:
        backend = read_github(f"/repos/{BACKEND}/branches/main", token)
        frontend = read_github(f"/repos/{FRONTEND}/branches/main", token)
        issues = {n: read_github(f"/repos/{BACKEND}/issues/{n}", token)
                  for n in REQUIRED_ISSUES}
        result = assess(env, backend, frontend, issues, args.mode)
    except ReleaseGateError:
        print("MAIN_RELEASE=NO_GO_GITHUB_EVIDENCE_UNAVAILABLE", file=sys.stderr)
        return 2
    print("MAIN_RELEASE=" + result["status"])
    for item in result["blockers"]:
        print("BLOCKER=" + item)
    print("AWS_RUNTIME_VERIFIED=false")
    print("PRODUCTION_CHANGED=false")
    return 0 if not result["blockers"] else 3

if __name__ == "__main__":
    raise SystemExit(main())
