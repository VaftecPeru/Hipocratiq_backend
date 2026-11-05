#!/usr/bin/env python3
"""Offline, fail-closed validator for an *approved* controlled web release.
Does not replace the existing UAT gate, deploy anything, or claim clinical approval.
Input contains only attestations/metadata, never patient information or secrets.
"""
import datetime as dt
import json
import re
import sys

SHA = re.compile(r"^[a-f0-9]{40}$")
REQUIRED = (
    "clinical_web_signoff", "security_review", "aws_readonly_inventory",
    "encrypted_backup", "restore_drill", "rollback_plan",
    "production_oidc_review", "change_approval",
)


def validate(evidence, backend_sha, frontend_sha, now=None):
    now = now or dt.datetime.now(dt.timezone.utc)
    blockers = []
    if not SHA.fullmatch(backend_sha or "") or not SHA.fullmatch(frontend_sha or ""):
        return ["Both immutable 40-character Git SHAs are required."]
    if evidence.get("release_type") != "controlled_web_existing_aws":
        blockers.append("Release type must be the explicitly approved controlled web path.")
    if evidence.get("backend_sha") != backend_sha:
        blockers.append("Backend SHA does not match release candidate.")
    if evidence.get("frontend_sha") != frontend_sha:
        blockers.append("Frontend SHA does not match release candidate.")
    if evidence.get("uat_independent_completed") is True:
        blockers.append("Do not misrepresent an independent AWS UAT run.")
    for name in REQUIRED:
        record = evidence.get(name)
        if not isinstance(record, dict) or record.get("status") != "approved":
            blockers.append(f"{name}: independently approved evidence missing.")
            continue
        if record.get("backend_sha") != backend_sha or record.get("frontend_sha") != frontend_sha:
            blockers.append(f"{name}: wrong release SHAs.")
        if not isinstance(record.get("evidence_id"), str) or len(record["evidence_id"].strip()) < 6:
            blockers.append(f"{name}: traceable evidence reference missing.")
        if not isinstance(record.get("approved_by"), str) or not record["approved_by"].strip():
            blockers.append(f"{name}: approver identity missing.")
        try:
            approved = dt.datetime.fromisoformat(record["approved_at"].replace("Z", "+00:00"))
            if approved.tzinfo is None or not (dt.timedelta(0) <= now - approved <= dt.timedelta(days=7)):
                blockers.append(f"{name}: approval missing valid recent UTC timestamp.")
        except (ValueError, TypeError, AttributeError, KeyError):
            blockers.append(f"{name}: approval timestamp invalid.")
    return blockers


def main(argv):
    if len(argv) != 4:
        print("Usage: controlled_web_release_evidence.py evidence.json BACKEND_SHA FRONTEND_SHA", file=sys.stderr)
        return 2
    try:
        with open(argv[1], encoding="utf-8") as f:
            evidence = json.load(f)
        if not isinstance(evidence, dict):
            raise ValueError("Expected evidence object.")
        blockers = validate(evidence, argv[2], argv[3])
    except (OSError, ValueError, TypeError) as err:
        blockers = [f"Invalid evidence file: {type(err).__name__}"]
    print("HIPOCRATIQ_CONTROLLED_WEB_EVIDENCE=" + ("NO_GO" if blockers else "PRELIMINARY_EVIDENCE_PASS"))
    for item in blockers:
        print("BLOCKER: " + item)
    print("This validator is NOT production permission; independently verify referenced evidence.")
    return 1 if blockers else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
