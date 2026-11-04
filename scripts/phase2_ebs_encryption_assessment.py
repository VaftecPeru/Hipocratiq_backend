#!/usr/bin/env python3
"""Read only sanitized EBS metadata and produce a fail-closed encryption decision.

Never invokes AWS, KMS, EC2 modification, snapshot creation or volume migration.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys


class AssessmentError(Exception):
    pass


def assess(report: object) -> dict:
    if not isinstance(report, dict):
        raise AssessmentError("INVALID_REPORT")
    required = (
        "gate", "status", "aws_account_and_instance_verified",
        "attached_ebs_volumes", "ebs_encrypted_volumes",
        "ebs_kms_key_reference_present_count", "all_attached_volumes_encrypted",
        "snapshot_created", "production_changed",
        "security_or_release_approval_granted",
    )
    if any(field not in report for field in required):
        raise AssessmentError("MISSING_EVIDENCE")
    if (report["gate"] != "HIPOCRATIQ_PRODUCTION_EBS_SNAPSHOT_METADATA_ONLY"
            or report["status"] != "READ_ONLY_METADATA_COLLECTED_RECOVERY_UNVERIFIED"
            or report["aws_account_and_instance_verified"] is not True
            or report["snapshot_created"] is not False
            or report["production_changed"] is not False
            or report["security_or_release_approval_granted"] is not False):
        raise AssessmentError("UNTRUSTED_METADATA")
    total = report["attached_ebs_volumes"]
    encrypted = report["ebs_encrypted_volumes"]
    with_kms = report["ebs_kms_key_reference_present_count"]
    if (any(type(x) is not int for x in (total, encrypted, with_kms))
            or not 0 < total <= 24 or not 0 <= encrypted <= total
            or not 0 <= with_kms <= total
            or report["all_attached_volumes_encrypted"] is not (encrypted == total)):
        raise AssessmentError("INCONSISTENT_COUNTS")
    pending = total - encrypted
    if pending:
        status = "UNENCRYPTED_EBS_PRESENT_MIGRATION_APPROVAL_REQUIRED"
    elif with_kms < total:
        status = "EBS_ENCRYPTED_KEY_METADATA_INCOMPLETE"
    else:
        status = "ENCRYPTION_METADATA_ONLY_HUMAN_VERIFICATION_REQUIRED"
    return {
        "status": status,
        "attached_volumes": total,
        "encrypted_volumes": encrypted,
        "unencrypted_volumes": pending,
        "kms_reference_present_count": with_kms,
        "remediation_executed": False,
        "backup_restored": False,
        "production_changed": False,
        "security_or_release_approval_granted": False,
    }


def main(argv=None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        if args.report.stat().st_size > 16384:
            raise AssessmentError("OVERSIZED_EVIDENCE")
        result = assess(json.loads(args.report.read_text(encoding="utf-8")))
    except (AssessmentError, OSError, ValueError, TypeError):
        print("EBS_ENCRYPTION=NO_GO_MISSING_OR_INVALID_EVIDENCE")
        return 2
    print("EBS_ENCRYPTION=" + result["status"])
    print("UNENCRYPTED_ATTACHED_VOLUMES=" + str(result["unencrypted_volumes"]))
    print("REMEDIATION_EXECUTED=false")
    return 0 if result["unencrypted_volumes"] == 0 and result["kms_reference_present_count"] == result["attached_volumes"] else 1


if __name__ == "__main__":
    sys.exit(main())
