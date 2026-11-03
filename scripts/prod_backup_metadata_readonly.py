#!/usr/bin/env python3
"""HIPOCRATIQ #113: production EC2-attached EBS metadata inventory, read-only.

Never accesses disk blocks, object data, database, PHI, secret values or KMS keys.
Never creates snapshots, runs SSM commands or performs a restore.
Only aggregate counts/boolean evidence are written; IAM approvals are external.
"""
from __future__ import annotations

import argparse
from collections import defaultdict
from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path
import re
import sys

from prod_version_evidence_readonly import EvidenceError, aws_call, aws_preflight

VOLUME_ID = re.compile(r"vol-[0-9a-f]{8,17}\Z")
SNAPSHOT_ID = re.compile(r"snap-[0-9a-f]{8,17}\Z")
MAX_VOLUMES = 24
MAX_SNAPSHOTS_PER_VOLUME = 1000
PAGE_LIMIT = 10
LOOKBACK_DAYS = 30


def require(condition, failure_code):
    if not condition:
        raise EvidenceError(failure_code)


def probe(env, runner=None, at=None):
    cfg = aws_preflight(env)  # Exact account, role, instance, VPC, branch, opt-ins.
    require(env.get("AUDIT_BACKUP_METADATA_ENABLED") == "true",
            "BACKUP_METADATA_NOT_APPROVED")
    require(env.get("AUDIT_BACKUP_METADATA_ACK") == "READ_ONLY_EBS_SNAPSHOT_INVENTORY",
            "BACKUP_METADATA_ACK_MISSING")
    when = at or datetime.now(timezone.utc)
    require(when.tzinfo is not None, "INVALID_TIMEZONE")
    when = when.astimezone(timezone.utc)

    # aws_call() is allowlisted to read-only commands and never writes stdout
    # or API responses to job logs. No IAM secrets are printed.
    caller = aws_call(["sts", "get-caller-identity"], runner)
    account = cfg["account"]
    instance = cfg["instance"]
    require(caller.get("Account") == account, "AWS_ACCOUNT_MISMATCH")
    require(str(caller.get("Arn", "")).startswith(
        f"arn:aws:sts::{account}:assumed-role/HIPOCRATIQ-PROD-ReadOnly/"
    ), "AWS_ROLE_MISMATCH")
    response = aws_call(["ec2", "describe-instances", "--instance-ids", instance], runner)
    items = [item for reservation in response.get("Reservations", [])
             for item in reservation.get("Instances", [])]
    require(len(items) == 1 and items[0].get("InstanceId") == instance,
            "PRODUCTION_INSTANCE_NOT_UNIQUE")
    require(items[0].get("VpcId") == cfg["vpc"], "PRODUCTION_VPC_MISMATCH")

    mappings = items[0].get("BlockDeviceMappings")
    require(isinstance(mappings, list) and 0 < len(mappings) <= MAX_VOLUMES,
            "NO_VALID_EBS_MAPPINGS")
    ids = []
    for mapping in mappings:
        value = (mapping.get("Ebs") or {}).get("VolumeId")
        require(isinstance(value, str) and VOLUME_ID.fullmatch(value),
                "INVALID_ATTACHED_EBS_VOLUME")
        ids.append(value)
    require(len(set(ids)) == len(ids), "DUPLICATE_EBS_VOLUME")

    volumes_resp = aws_call(["ec2", "describe-volumes", "--volume-ids", *ids], runner)
    volumes = volumes_resp.get("Volumes")
    require(isinstance(volumes, list) and len(volumes) == len(ids),
            "VOLUME_INVENTORY_INCOMPLETE")
    found = set()
    encrypted_volumes = 0
    kms_mapped = 0
    for volume in volumes:
        volume_id = volume.get("VolumeId")
        require(volume_id in ids and volume_id not in found, "UNEXPECTED_EBS_VOLUME")
        attached = volume.get("Attachments")
        require(isinstance(attached, list) and any(
            a.get("InstanceId") == instance for a in attached
        ), "EBS_ATTACHMENT_MISMATCH")
        found.add(volume_id)
        encrypted_volumes += int(volume.get("Encrypted") is True)
        kms_mapped += int(bool(volume.get("KmsKeyId")))

    completed_by_volume = defaultdict(int)
    recent_encrypted_by_volume = defaultdict(int)
    snapshot_total = 0
    completed_total = 0
    encrypted_completed_total = 0
    for volume in ids:
        next_token = None
        seen_tokens = set()
        pages = 0
        volume_count = 0
        while True:
            pages += 1
            require(pages <= PAGE_LIMIT, "SNAPSHOT_PAGINATION_LIMIT")
            cmd = [
                "ec2", "describe-snapshots", "--owner-ids", "self",
                "--filters", f"Name=volume-id,Values={volume}",
                "--max-results", "100", "--no-paginate",
            ]
            if next_token is not None:
                cmd.extend(["--starting-token", next_token])
            payload = aws_call(cmd, runner)
            entries = payload.get("Snapshots")
            require(isinstance(entries, list) and len(entries) <= 100,
                    "INVALID_SNAPSHOT_METADATA")
            for entry in entries:
                require(
                    isinstance(entry, dict)
                    and isinstance(entry.get("SnapshotId"), str)
                    and SNAPSHOT_ID.fullmatch(entry["SnapshotId"])
                    and entry.get("VolumeId") == volume
                    and entry.get("OwnerId") == account,
                    "SNAPSHOT_OWNER_OR_VOLUME_MISMATCH",
                )
                snapshot_total += 1
                volume_count += 1
                state = entry.get("State")
                if state == "completed":
                    completed_total += 1
                    completed_by_volume[volume] += 1
                    encrypted = entry.get("Encrypted") is True
                    encrypted_completed_total += int(encrypted)
                    if encrypted:
                        timestamp = entry.get("StartTime")
                        require(isinstance(timestamp, str), "SNAPSHOT_TIME_INVALID")
                        try:
                            moment = datetime.fromisoformat(timestamp.replace("Z", "+00:00"))
                        except ValueError:
                            raise EvidenceError("SNAPSHOT_TIME_INVALID") from None
                        require(moment.tzinfo is not None, "SNAPSHOT_TIMEZONE_MISSING")
                        moment = moment.astimezone(timezone.utc)
                        if when - timedelta(days=LOOKBACK_DAYS) <= moment <= when:
                            recent_encrypted_by_volume[volume] += 1
                else:
                    require(state in {"pending", "error", "recoverable"},
                            "UNEXPECTED_SNAPSHOT_STATE")
            require(volume_count <= MAX_SNAPSHOTS_PER_VOLUME, "TOO_MANY_SNAPSHOTS")
            token = payload.get("NextToken")
            if not token:
                break
            require(isinstance(token, str) and len(token) <= 4096
                    and token not in seen_tokens, "INVALID_NEXT_TOKEN")
            seen_tokens.add(token)
            next_token = token

    recent_covered = sum(1 for volume in ids if recent_encrypted_by_volume[volume])
    completed_covered = sum(1 for volume in ids if completed_by_volume[volume])
    inventory_fully_encrypted = encrypted_volumes == len(ids)
    return {
        "gate": "HIPOCRATIQ_PRODUCTION_EBS_SNAPSHOT_METADATA_ONLY",
        "status": "READ_ONLY_METADATA_COLLECTED_RECOVERY_UNVERIFIED",
        "aws_account_and_instance_verified": True,
        "attached_ebs_volumes": len(ids),
        "ebs_encrypted_volumes": encrypted_volumes,
        "ebs_kms_key_reference_present_count": kms_mapped,
        "ebs_volumes_with_completed_snapshot": completed_covered,
        "ebs_volumes_with_recent_encrypted_snapshot_30d": recent_covered,
        "snapshots_listed": snapshot_total,
        "completed_snapshots": completed_total,
        "encrypted_completed_snapshots": encrypted_completed_total,
        "all_attached_volumes_encrypted": inventory_fully_encrypted,
        "recent_ebs_snapshot_metadata_coverage": recent_covered == len(ids),
        "restore_tested": False,
        "database_backup_tested": False,
        "rollback_tested": False,
        "snapshot_created": False,
        "clinical_data_accessed": False,
        "production_changed": False,
        "snapshot_identifiers_or_kms_key_arns_recorded": False,
        "security_or_release_approval_granted": False,
        "limitations": [
            "Only currently attached EBS volumes for the approved EC2 are inspected.",
            "EBS snapshot metadata does not prove an application-consistent PostgreSQL backup.",
            "No S3, AWS Backup vault, RDS, file-level or database backup was inspected.",
            "Snapshots are NOT restored and no cryptographic checksums are validated.",
            "Counts do not establish retention policy, restoreability or rollback readiness.",
            "P0 #113 remains OPEN until independently authorized recovery evidence.",
        ],
    }


def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", required=True)
    args = parser.parse_args(argv)
    try:
        evidence = probe(os.environ)
        out = Path(args.report)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(evidence, sort_keys=True, indent=2) + "\n",
                       encoding="utf-8")
        print("BACKUP_EVIDENCE=READ_ONLY_METADATA_RECOVERY_NOT_VERIFIED")
        print("ATTACHED_EBS_VOLUMES=" + str(evidence["attached_ebs_volumes"]))
        print("RECENT_ENCRYPTED_EBS_SNAPSHOT_COVERAGE=" +
              str(evidence["ebs_volumes_with_recent_encrypted_snapshot_30d"]))
        print("P0_113_RELEASE_CLEARANCE=false")
        return 0
    except (EvidenceError, OSError):
        print("BACKUP_EVIDENCE=INCOMPLETE_NO_GO", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
