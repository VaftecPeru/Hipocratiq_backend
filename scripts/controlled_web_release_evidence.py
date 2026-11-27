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
UTC_TIMESTAMP = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", re.ASCII)
EVIDENCE_ID = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{5,127}")
REQUIRED = (
    "clinical_web_signoff", "security_review", "aws_readonly_inventory",
    "encrypted_backup", "restore_drill", "rollback_plan",
    "production_oidc_review", "change_approval",
)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Duplicate evidence field.")
        result[key] = value
    return result


def validate(evidence, backend_sha, frontend_sha, now=None):
    now = now or dt.datetime.now(dt.timezone.utc)
    blockers = []
    if not isinstance(evidence, dict):
        return ["Evidence must be an object."]
    if not all(isinstance(sha, str) and SHA.fullmatch(sha) for sha in (backend_sha, frontend_sha)):
        return ["Both immutable 40-character Git SHAs are required."]
    if evidence.get("release_type") != "controlled_web_existing_aws":
        blockers.append("Release type must be the explicitly approved controlled web path.")
    if evidence.get("backend_sha") != backend_sha:
        blockers.append("Backend SHA does not match release candidate.")
    if evidence.get("frontend_sha") != frontend_sha:
        blockers.append("Frontend SHA does not match release candidate.")
    if evidence.get("uat_independent_completed") is not False:
        blockers.append("Independent AWS UAT must be explicitly declared false for this path.")
    for name in REQUIRED:
        record = evidence.get(name)
        if not isinstance(record, dict) or record.get("status") != "approved":
            blockers.append(f"{name}: independently approved evidence missing.")
            continue
        if record.get("backend_sha") != backend_sha or record.get("frontend_sha") != frontend_sha:
            blockers.append(f"{name}: wrong release SHAs.")
        if not isinstance(record.get("evidence_id"), str) or not EVIDENCE_ID.fullmatch(record["evidence_id"]):
            blockers.append(f"{name}: traceable evidence reference missing.")
        if (not isinstance(record.get("approved_by"), str) or not record["approved_by"].strip()
                or len(record["approved_by"]) > 128 or not record["approved_by"].isprintable()):
            blockers.append(f"{name}: approver identity missing.")
        try:
            timestamp = record["approved_at"]
            if not isinstance(timestamp, str) or not UTC_TIMESTAMP.fullmatch(timestamp):
                raise ValueError("Expected canonical UTC timestamp.")
            approved = dt.datetime.fromisoformat(timestamp.replace("Z", "+00:00"))
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
            evidence = json.load(f, object_pairs_hook=unique_object)
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
