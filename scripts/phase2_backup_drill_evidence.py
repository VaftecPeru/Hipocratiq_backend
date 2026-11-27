#!/usr/bin/env python3
"""Offline schema validator for isolated PostgreSQL restore-drill attestations.

Never contacts a database, runs pg_restore, or reads backup contents. A positive
self-attestation is not independently verified recovery or release approval.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
import re
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
V2_REQUIRED_TRUE = REQUIRED_TRUE | {
    "encrypted_backup_verified", "private_custody_confirmed",
    "retention_policy_approved", "production_network_isolation_confirmed",
}
V2_FIELDS = V2_REQUIRED_TRUE | {
    "schema_version", "target_environment", "backend_sha", "frontend_sha",
    "backup_ciphertext_sha256", "backup_plaintext_sha256", "executed_at_utc",
    "evidence_ref", "rto_observed_seconds", "rpo_observed_seconds",
    "rto_limit_seconds", "rpo_limit_seconds",
}
COMMIT_SHA = re.compile(r"[0-9a-f]{40}")
DIGEST = re.compile(r"[0-9a-f]{64}")
PRIVATE_REF = re.compile(r"EV-[A-Z0-9-]{3,64}")
UTC = re.compile(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z")


class AttestationError(Exception):
    pass


def reject_duplicate_keys(pairs):
    """Reject conflicting or repeated evidence fields before dict normalization."""
    result = {}
    for key, value in pairs:
        if key in result:
            raise AttestationError("DUPLICATE_EVIDENCE_FIELD")
        result[key] = value
    return result


def reject_nonfinite_constant(value):
    """Python JSON accepts NaN/Infinity by default; attestation JSON must not."""
    raise AttestationError("NON_STANDARD_JSON_CONSTANT")


def assess(data: object) -> dict:
    if not isinstance(data, dict):
        raise AttestationError("REJECT_UNEXPECTED_OR_MISSING_FIELDS")
    version = data.get("schema_version")
    if type(version) is not int or version not in (1, 2):
        raise AttestationError("UNSUPPORTED_SCHEMA")
    controls = REQUIRED_TRUE if version == 1 else V2_REQUIRED_TRUE
    if set(data) != (FIELDS if version == 1 else V2_FIELDS):
        raise AttestationError("REJECT_UNEXPECTED_OR_MISSING_FIELDS")
    if data["target_environment"] != "isolated_nonproduction":
        raise AttestationError("PRODUCTION_TARGET_NOT_ALLOWED")
    if any(type(data[key]) is not bool for key in controls):
        raise AttestationError("INVALID_ATTESTATION_BOOLEAN")
    objectives_met = False
    if version == 2:
        for key, pattern in (
            ("backend_sha", COMMIT_SHA), ("frontend_sha", COMMIT_SHA),
            ("backup_ciphertext_sha256", DIGEST), ("backup_plaintext_sha256", DIGEST),
            ("evidence_ref", PRIVATE_REF), ("executed_at_utc", UTC),
        ):
            if not isinstance(data[key], str) or not pattern.fullmatch(data[key]):
                raise AttestationError("INVALID_PRIVATE_EVIDENCE_METADATA")
        if data["backup_ciphertext_sha256"] == data["backup_plaintext_sha256"]:
            raise AttestationError("ENCRYPTION_DIGESTS_NOT_DISTINCT")
        try:
            timestamp = datetime.strptime(data["executed_at_utc"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
        except ValueError:
            raise AttestationError("INVALID_UTC_TIME") from None
        if timestamp > datetime.now(timezone.utc):
            raise AttestationError("FUTURE_EXECUTION_TIME")
        for key in ("rto_observed_seconds", "rpo_observed_seconds",
                    "rto_limit_seconds", "rpo_limit_seconds"):
            if type(data[key]) is not int or data[key] < 0:
                raise AttestationError("INVALID_RECOVERY_TIME")
        objectives_met = (data["rto_observed_seconds"] <= data["rto_limit_seconds"]
                          and data["rpo_observed_seconds"] <= data["rpo_limit_seconds"])
    all_attested = all(data[key] for key in controls) and (version == 1 or objectives_met)
    return {
        "status": ("ISOLATED_DRILL_SELF_ATTESTED_INDEPENDENT_REVIEW_PENDING"
                   if all_attested else "RECOVERY_EVIDENCE_INCOMPLETE_NO_GO"),
        "required_controls_attested_count": sum(data[key] for key in controls),
        "required_controls_total": len(controls),
        "version_bound_metadata_present": version == 2,
        "self_reported_recovery_objectives_met": objectives_met,
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
        report = assess(json.loads(path.read_text(encoding="utf-8"),
                                   object_pairs_hook=reject_duplicate_keys,
                                   parse_constant=reject_nonfinite_constant))
    except (OSError, ValueError, AttestationError):
        print("RESTORE_DRILL=NO_GO_INVALID_EVIDENCE")
        return 2
    print("RESTORE_DRILL=" + report["status"])
    print("RECOVERY_CERTIFIED=false")
    print("PRODUCTION_CHANGED=false")
    return 0 if report["status"] == "ISOLATED_DRILL_SELF_ATTESTED_INDEPENDENT_REVIEW_PENDING" else 1


if __name__ == "__main__":
    sys.exit(main())
