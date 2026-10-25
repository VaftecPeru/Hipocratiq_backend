#!/usr/bin/env python3
"""Read-only, fail-closed HIPOCRATIQ release PR gate.

This is a necessary GitHub evidence preflight, NOT a clinical GO or a deploy.
It never assumes AWS credentials, alters GitHub state, or handles PHI.
"""
import datetime as dt
import json
import os
import sys
from urllib.error import HTTPError, URLError
from urllib.parse import quote
from urllib.request import Request, urlopen

REPOSITORY = "VaftecPeru/Hipocratiq_backend"
FRONTEND_REPOSITORY = "VaftecPeru/Hipocratiq_Frontend"
FRONTEND_RELEASE_PR = 80
ISSUE = 92
AWS_JOB_NAME = "Approved AWS UAT instance metadata ONLY"
AWS_ARTIFACT = "hipocratiq-uat-aws-readonly-metadata"
MAX_INVENTORY_AGE_DAYS = 7
NOW_UTC = dt.timezone.utc


class GateError(Exception):
    pass


def http_get_json(path, token, repository=REPOSITORY):
    if not token:
        raise GateError("No read-only GITHUB_TOKEN available.")
    if repository not in (REPOSITORY, FRONTEND_REPOSITORY):
        raise GateError("Refusing an unapproved repository.")
    if not path.startswith("/repos/" + repository + "/"):
        raise GateError("Refusing unapproved GitHub API path.")
    req = Request(
        "https://api.github.com" + path,
        headers={
            "Authorization": "Bearer " + token,
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "HIPOCRATIQ-Release-Readiness-ReadOnly",
        },
    )
    try:
        with urlopen(req, timeout=15) as response:
            return json.load(response)
    except (HTTPError, URLError, TimeoutError, ValueError) as err:
        raise GateError("GitHub evidence API unavailable; NO-GO. " +
                        type(err).__name__) from None


def normalize_inventory_run(run, jobs, artifacts):
    match = next((j for j in jobs if j.get("name") == AWS_JOB_NAME), None)
    matching_artifacts = [
        a for a in artifacts
        if a.get("name") == AWS_ARTIFACT
        and not a.get("expired")
        and isinstance(a.get("size_in_bytes"), int)
        and a["size_in_bytes"] > 100
    ]
    return {
        "created_at": run.get("created_at", ""),
        "head_branch": run.get("head_branch"),
        "event": run.get("event"),
        "run_conclusion": run.get("conclusion"),
        "aws_job_conclusion": match.get("conclusion") if match else None,
        "artifact_present": bool(matching_artifacts),
        "run_id": run.get("id"),
    }


def assess(pr, main, develop, issue, inventory, now=None):
    """Return reasons not to release. An empty result is *not* deploy approval."""
    if now is None:
        now = dt.datetime.now(NOW_UTC)
    blockers = []
    if pr.get("base", {}).get("ref") != "main":
        blockers.append("PR target must be main.")
    if pr.get("head", {}).get("repo", {}).get("full_name") != REPOSITORY:
        blockers.append("Release source must be this repository (no fork).")
    if pr.get("draft") is not False:
        blockers.append("Release PR is DRAFT: clinical GO is not recorded.")
    if pr.get("state") != "open":
        blockers.append("Release PR must remain open during assessment.")

    expected_sha = develop.get("commit", {}).get("sha")
    if not expected_sha or pr.get("head", {}).get("sha") != expected_sha:
        blockers.append("Release PR head SHA differs from current develop.")
    if main.get("protected") is not True:
        blockers.append("GitHub Backend main branch is not protected.")
    if develop.get("protected") is not True:
        blockers.append("GitHub Backend develop branch is not protected.")
    if issue.get("state") != "closed":
        blockers.append("P0 clinical release tracking issue #92 remains open.")

    accepted = []
    for run in inventory:
        try:
            recorded = dt.datetime.fromisoformat(run["created_at"].replace("Z", "+00:00"))
            age = now - recorded
        except (KeyError, ValueError, TypeError):
            continue
        if (dt.timedelta(0) <= age <= dt.timedelta(days=MAX_INVENTORY_AGE_DAYS)
            and run.get("head_branch") == "develop"
            and run.get("event") in ("workflow_dispatch", "push")
            and run.get("run_conclusion") == "success"
            and run.get("aws_job_conclusion") == "success"
            and run.get("artifact_present") is True):
            accepted.append(run)
    if not accepted:
        blockers.append(
            "No recent real AWS UAT inventory: job must be SUCCESS, not SKIPPED, "
            "with a retained non-empty metadata artifact."
        )

    return blockers


def assess_frontend_release(frontend_pr, frontend_main, frontend_develop):
    """Ensure the separate Web/Mobile candidate is protected and in sync.

    This is necessary cross-repository GitHub evidence only; real UAT remains
    an independent prerequisite and must not be inferred from these checks.
    """
    blockers = []
    if frontend_main.get("protected") is not True:
        blockers.append("GitHub Frontend main branch is not protected.")
    if frontend_develop.get("protected") is not True:
        blockers.append("GitHub Frontend develop branch is not protected.")
    if frontend_pr.get("state") != "open":
        blockers.append("Frontend release PR #80 is not open.")
    if frontend_pr.get("draft") is not False:
        blockers.append("Frontend release PR #80 is DRAFT.")
    if frontend_pr.get("base", {}).get("ref") != "main":
        blockers.append("Frontend release PR #80 must target main.")
    if (frontend_pr.get("head", {}).get("repo") or {}).get("full_name") != FRONTEND_REPOSITORY:
        blockers.append("Frontend release PR #80 must originate from the approved repository.")
    frontend_sha = frontend_develop.get("commit", {}).get("sha")
    if not frontend_sha or frontend_pr.get("head", {}).get("sha") != frontend_sha:
        blockers.append("Frontend release PR #80 SHA differs from Frontend develop.")
    return blockers


def collect_evidence(token, number):
    root = "/repos/" + REPOSITORY
    pr = http_get_json(root + "/pulls/" + str(number), token)
    main = http_get_json(root + "/branches/main", token)
    develop = http_get_json(root + "/branches/develop", token)
    issue = http_get_json(root + "/issues/" + str(ISSUE), token)

    frontend_root = "/repos/" + FRONTEND_REPOSITORY
    frontend_pr = http_get_json(
        frontend_root + "/pulls/" + str(FRONTEND_RELEASE_PR),
        token, repository=FRONTEND_REPOSITORY,
    )
    frontend_main = http_get_json(
        frontend_root + "/branches/main", token, repository=FRONTEND_REPOSITORY,
    )
    frontend_develop = http_get_json(
        frontend_root + "/branches/develop", token, repository=FRONTEND_REPOSITORY,
    )

    # No calls to AWS. All results come from GitHub's own workflow/job API.
    raw = http_get_json(
        root + "/actions/workflows/uat-aws-readonly-inventory.yml/runs"
        "?branch=develop&per_page=20", token
    )
    inventory = []
    for run in raw.get("workflow_runs", []):
        if run.get("event") not in ("push", "workflow_dispatch"):
            continue
        run_id = run.get("id")
        if not isinstance(run_id, int):
            continue
        jobs = http_get_json(
            root + "/actions/runs/" + str(run_id) + "/jobs?per_page=100", token
        ).get("jobs", [])
        if not any(j.get("name") == AWS_JOB_NAME and
                   j.get("conclusion") == "success" for j in jobs):
            # A green offline job with skipped AWS is NOT a real inventory.
            inventory.append(normalize_inventory_run(run, jobs, []))
            continue
        artifacts = http_get_json(
            root + "/actions/runs/" + str(run_id) + "/artifacts?per_page=100", token
        ).get("artifacts", [])
        inventory.append(normalize_inventory_run(run, jobs, artifacts))
    return pr, main, develop, issue, inventory, frontend_pr, frontend_main, frontend_develop


def main():
    if os.environ.get("GITHUB_REPOSITORY") != REPOSITORY:
        print("RELEASE_GATE=NO_GO: unexpected repository", file=sys.stderr)
        return 2
    if not os.environ.get("GITHUB_EVENT_PATH"):
        print("RELEASE_GATE=NO_GO: missing pull_request event", file=sys.stderr)
        return 2
    try:
        with open(os.environ["GITHUB_EVENT_PATH"], encoding="utf-8") as f:
            event = json.load(f)
        number = event.get("pull_request", {}).get("number")
        if not isinstance(number, int):
            raise GateError("Only GitHub main pull requests may be assessed.")
        p, m, d, i, runs, fe_pr, fe_main, fe_develop = collect_evidence(
            os.environ.get("GITHUB_TOKEN", ""), number
        )
        blockers = assess(p, m, d, i, runs)
        blockers.extend(assess_frontend_release(fe_pr, fe_main, fe_develop))
    except (GateError, OSError, ValueError) as err:
        blockers = ["Evidence unavailable; fail-closed: " + str(err)]

    status = "NO_GO" if blockers else "MINIMUM_GITHUB_EVIDENCE_PASS"
    # A release still requires UAT clinical sign-off, immutable audit trail,
    # customer acceptance and independently authorized production deployment.
    print("HIPOCRATIQ_RELEASE_EVIDENCE=" + status)
    for reason in blockers:
        print("BLOCKER: " + reason)
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as f:
            f.write("## HIPOCRATIQ release evidence (GitHub read-only)\n\n")
            f.write("**" + status + "**\n\n")
            for blocker in blockers:
                f.write("- " + blocker.replace("\n", " ") + "\n")
            f.write("\nNot a clinical acceptance or permission to deploy AWS.\n")
    return 1 if blockers else 0


if __name__ == "__main__":
    sys.exit(main())
