#!/usr/bin/env python3
"""Non-sensitive 36-position clinical UAT evidence ledger contract.

Structural checks never approve clinical UAT, validate external evidence, or
authorize deploy. The canonical scenario descriptions remain in the master sheet.
"""

from __future__ import annotations

import argparse
from datetime import datetime, timedelta
import json
from pathlib import Path
import re


EXPECTED_POSITIONS = set(range(1, 37))
MASTER_MATRIX = (
    "https://docs.google.com/spreadsheets/d/"
    "1SwuLEHgvPyDVfQC3xic-Fg4d1NzUY4vrl0WCEfEBhls/edit"
)
ALLOWED_STATUSES = {"NOT_EXECUTED", "PASS", "FAIL", "BLOCKED"}
EXECUTED_KEYS = {
    "position", "status", "backend_sha", "frontend_sha",
    "executed_at_utc", "executor_ref", "evidence_ref",
}
PENDING_KEYS = {"position", "status"}
SHA = re.compile(r"^[a-f0-9]{40}$")
PRIVATE_REF = re.compile(r"^[A-Z]{2,8}-[A-Z0-9-]{3,64}$")


class LedgerError(ValueError):
    """Raised with safe, non-PHI validation descriptions."""


def _nonempty_ref(value: object) -> bool:
    return isinstance(value, str) and PRIVATE_REF.fullmatch(value) is not None


def _utc_time(value: object) -> bool:
    if not isinstance(value, str) or not value.endswith("Z"):
        return False
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
        return parsed.utcoffset() == timedelta(0)
    except ValueError:
        return False


def validate(ledger: object) -> dict[str, object]:
    if not isinstance(ledger, dict):
        raise LedgerError("ledger must be an object")
    required = {"schema_version", "source_issue", "master_matrix_url", "scope", "rows"}
    if set(ledger) != required:
        raise LedgerError("unexpected or missing top-level fields")
    if type(ledger["schema_version"]) is not int or ledger["schema_version"] != 1:
        raise LedgerError("unsupported schema version")
    if type(ledger["source_issue"]) is not int or ledger["source_issue"] != 92:
        raise LedgerError("incorrect issue reference")
    if ledger["master_matrix_url"] != MASTER_MATRIX:
        raise LedgerError("master source reference mismatch")
    if not isinstance(ledger["scope"], str) or "private" not in ledger["scope"].lower():
        raise LedgerError("missing private-evidence scope disclaimer")
    rows = ledger["rows"]
    if not isinstance(rows, list) or len(rows) != 36:
        raise LedgerError("exactly 36 matrix positions are required")

    positions: set[int] = set()
    counts = {key: 0 for key in sorted(ALLOWED_STATUSES)}
    candidate_shas: set[tuple[str, str]] = set()
    for row in rows:
        if not isinstance(row, dict):
            raise LedgerError("each row must be an object")
        number = row.get("position")
        if type(number) is not int or number not in EXPECTED_POSITIONS or number in positions:
            raise LedgerError("matrix position is missing, duplicated, or out of range")
        positions.add(number)
        status = row.get("status")
        if status not in ALLOWED_STATUSES:
            raise LedgerError("invalid UAT result state")
        counts[status] += 1
        if status == "NOT_EXECUTED":
            if set(row) != PENDING_KEYS:
                raise LedgerError("unexecuted rows must not contain fabricated evidence")
            continue
        if set(row) != EXECUTED_KEYS:
            raise LedgerError("executed rows need exact private evidence metadata")
        backend_sha, frontend_sha = row["backend_sha"], row["frontend_sha"]
        if not isinstance(backend_sha, str) or not SHA.fullmatch(backend_sha):
            raise LedgerError("invalid backend SHA")
        if not isinstance(frontend_sha, str) or not SHA.fullmatch(frontend_sha):
            raise LedgerError("invalid frontend SHA")
        if not _utc_time(row["executed_at_utc"]):
            raise LedgerError("UTC execution time required")
        if not _nonempty_ref(row["executor_ref"]) or not _nonempty_ref(row["evidence_ref"]):
            raise LedgerError("opaque private evidence and executor references required")
        candidate_shas.add((backend_sha, frontend_sha))

    if positions != EXPECTED_POSITIONS:
        raise LedgerError("missing matrix position")
    if len(candidate_shas) > 1:
        raise LedgerError("mixed candidate SHAs must be reconciled before clinical review")

    # This status intentionally NEVER changes through code, even with 36 PASS.
    # Signatures, evidence authenticity, scope and ethics need independent review.
    return {
        "matrix_positions": 36,
        "not_executed": counts["NOT_EXECUTED"],
        "self_reported_pass": counts["PASS"],
        "self_reported_fail": counts["FAIL"],
        "blocked": counts["BLOCKED"],
        "clinical_signoff_verified": False,
        "release_decision": "NO_GO",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate UAT 36 metadata contract, never grant release")
    parser.add_argument("--file", type=Path, default=Path("docs/releases/clinical-92-uat36-ledger.json"))
    args = parser.parse_args()
    try:
        result = validate(json.loads(args.file.read_text(encoding="utf-8")))
    except (LedgerError, ValueError, OSError, TypeError):
        print("UAT36_LEDGER=INVALID; RELEASE=NO_GO")
        return 2
    print(json.dumps(result, sort_keys=True))
    return 0  # Valid ledger format, NOT clinical acceptance or deployment approval.


if __name__ == "__main__":
    raise SystemExit(main())
