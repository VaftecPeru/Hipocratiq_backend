#!/usr/bin/env python3
"""Offline schema validator for isolated PostgreSQL restore-drill attestations.

Never contacts a database, runs pg_restore, or reads backup contents. A positive
self-attestation is not independently verified recovery or release approval.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

REQUIRED_TRUE = {
    "used_synthetic_data",
    "isolated_target_confirmed",
    "backup_sha256_verified",
    "restore_command_succeeded",
    "post_restore_integrity_checked",
    "production_source_untouched",
    "rollback_rehearsed",
}
FIELDS = REQUIRED_TRUE | {"schema_version", "target_environment"}


class AttestationError(Exception):
    pass


def assess(data: object) -> dict:
    if not isinstance(data, dict) or set(data) != FIELDS:
        raise AttestationError("REJECT_UNEXPECTED_OR_MISSING_FIELDS")
    if type(data["schema_version"]) is not int or data["schema_version"] != 1:
        raise AttestationError("UNSUPPORTED_SCHEMA")
    if data["target_environment"] != "isolated_nonproduction":
        raise AttestationError("PRODUCTION_TARGET_NOT_ALLOWED")
    if any(type(data[key]) is not bool for key in REQUIRED_TRUE):
        raise AttestationError("INVALID_ATTESTATION_BOOLEAN")
    all_attested = all(data[key] for key in REQUIRED_TRUE)
    return {
        "status": ("ISOLATED_DRILL_SELF_ATTESTED_INDEPENDENT_REVIEW_PENDING"
                   if all_attested else "RECOVERY_EVIDENCE_INCOMPLETE_NO_GO"),
        "required_controls_attested_count": sum(data[key] for key in REQUIRED_TRUE),
        "required_controls_total": len(REQUIRED_TRUE),
        "independent_reviewer_verified": False,
        "database_connection_made": False,
        "restoration_executed_by_validator": False,
        "production_changed": False,
        "recovery_certified": False,
        "release_approved": False,
    }


def main(argv=None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--attestation", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        path = args.attestation
        if path.stat().st_size > 4096:
            raise AttestationError("UNEXPECTED_SIZE")
        report = assess(json.loads(path.read_text(encoding="utf-8")))
    except (OSError, ValueError, AttestationError):
        print("RESTORE_DRILL=NO_GO_INVALID_EVIDENCE")
        return 2
    print("RESTORE_DRILL=" + report["status"])
    print("RECOVERY_CERTIFIED=false")
    print("PRODUCTION_CHANGED=false")
    return 0 if report["required_controls_attested_count"] == len(REQUIRED_TRUE) else 1


if __name__ == "__main__":
    sys.exit(main())
