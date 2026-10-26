#!/usr/bin/env python3
"""Read-only full reachable Git-history safety inventory.

No filenames, object contents, secret values, digests or object SHAs are logged
or written to artifacts. Evidence is diagnostic and does not close clinical or
credential exposure issues. A shallow/incomplete scan fails closed.
"""
from __future__ import annotations

import argparse
from collections import Counter
import json
import os
from pathlib import Path
import re
import subprocess
import sys

MAX_OBJECT_SIZE = 1024 * 1024
MAX_CANDIDATES = 20000
SECRET_ASSIGNMENTS = frozenset({
    "DB_PASSWORD", "REDIS_PASSWORD", "AWS_ACCESS_KEY_ID",
    "AWS_SECRET_ACCESS_KEY", "AWS_SESSION_TOKEN", "APP_KEY",
    "JWT_SECRET", "MAIL_PASSWORD", "SMTP_PASSWORD",
    "TWILIO_AUTH_TOKEN", "STRIPE_SECRET", "SECRET_KEY",
})
EXAMPLE_NAMES = (".env.example", ".env.sample", ".env.template", ".env.dist")


def classify_path(path: str) -> str | None:
    """Returns only a category, not an identifying filename."""
    p = path.replace("\\", "/").lower()
    name = p.rsplit("/", 1)[-1]

    if name in EXAMPLE_NAMES:
        return "EXAMPLE_TEMPLATE"
    if name == ".env" or name.startswith(".env.") or name.startswith(".env-"):
        return "TRACKED_ENV_FILE"
    if name in ("id_rsa", "id_ed25519", "id_ecdsa", "credentials.json"):
        return "POTENTIAL_PRIVATE_KEY"
    if name.endswith((".p12", ".pfx", ".pem", ".key")) and not name.endswith(".pub"):
        return "POTENTIAL_PRIVATE_KEY"
    if name.endswith((".dump", ".sql.gz", ".sqlite", ".sqlite3", ".db")):
        if "/schema/" not in p and "/migrations/" not in p:
            return "POSSIBLE_DATABASE_EXPORT"
    return None


def template_sensitive_assignments(raw: bytes) -> int:
    """Count non-empty assignments, without returning actual values or lines."""
    found = set()
    for line in raw.decode("utf-8", errors="replace").splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or "=" not in stripped:
            continue
        key, _, value = stripped.partition("=")
        key = key.strip().removeprefix("export ").strip()
        if key in SECRET_ASSIGNMENTS and value.strip().strip("'\"").strip():
            found.add(key)
    return len(found)


def git(*args: str, root: Path, input_bytes: bytes | None = None) -> bytes:
    p = subprocess.run(
        ["git", "-C", str(root), *args],
        input=input_bytes, capture_output=True, check=False, timeout=180,
    )
    if p.returncode:
        raise RuntimeError("GIT_OBJECT_ACCESS_FAILED")
    return p.stdout


def run_audit(root: Path) -> dict:
    if git("rev-parse", "--is-shallow-repository", root=root).strip() != b"false":
        raise RuntimeError("SHALLOW_HISTORY_NOT_AUDITABLE")
    listing = git("rev-list", "--objects", "--all", root=root).splitlines()
    if not listing:
        raise RuntimeError("EMPTY_GIT_HISTORY")

    counts = Counter()
    paths_seen = set()
    objects_seen = set()
    candidates = 0

    for entry in listing:
        _, _, raw_path = entry.partition(b" ")
        if not raw_path:
            continue
        # Filenames can be non-UTF-8. Never output the parsed value.
        path = raw_path.decode("utf-8", errors="replace")
        category = classify_path(path)
        if category is None:
            continue
        candidates += 1
        if candidates > MAX_CANDIDATES:
            raise RuntimeError("SCAN_CANDIDATE_LIMIT_REACHED")

        object_sha = entry[:40].decode("ascii", errors="replace")
        if not re.fullmatch(r"[0-9a-f]{40}", object_sha):
            raise RuntimeError("UNEXPECTED_GIT_OBJECT_ID")
        if git("cat-file", "-t", object_sha, root=root).strip() != b"blob":
            continue
        object_size = int(git("cat-file", "-s", object_sha, root=root).strip())
        if object_size > MAX_OBJECT_SIZE:
            raise RuntimeError("SENSITIVE_CANDIDATE_TOO_LARGE_TO_INSPECT")

        if category == "EXAMPLE_TEMPLATE":
            if object_sha in objects_seen:
                continue
            objects_seen.add(object_sha)
            content = git("cat-file", "blob", object_sha, root=root)
            if template_sensitive_assignments(content):
                counts["HISTORICAL_NONEMPTY_TEMPLATE_CREDENTIALS"] += 1
            continue

        # Blob classification alone warrants a private review; do not read
        # a potentially sensitive .env/key/dump merely to publish CI evidence.
        key = (category, path)
        if key not in paths_seen:
            paths_seen.add(key)
            counts[category] += 1

    signals = dict(sorted(counts.items()))
    return {
        "gate": "HIPOCRATIQ_PUBLIC_GIT_HISTORY_REDACTED",
        "status": "PRIVATE_REVIEW_REQUIRED" if signals else "NO_SCANNER_SIGNAL_NOT_CLEARANCE",
        "scanned_reachable_objects": len(listing),
        "candidate_entries": candidates,
        "signal_counts": signals,
        "secret_values_recorded": False,
        "sensitive_paths_recorded": False,
        "aws_calls": 0,
        "limitations": [
            "Only objects reachable by refs available in this complete checkout",
            "GitHub Actions artifacts, releases, issues, remote forks and clones are NOT audited",
            "No comparison with live UAT/production credentials is performed",
            "Scanner signals are not proof of operational credential exposure",
            "No signal does NOT certify the absence of leaked secrets",
            "P0 #102 needs private human audit and rotation decision before closure",
        ],
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", default=".")
    parser.add_argument("--report", default="")
    opts = parser.parse_args(argv)
    try:
        result = run_audit(Path(opts.repository).resolve())
    except (RuntimeError, subprocess.TimeoutExpired, ValueError, OSError):
        print("HISTORY_AUDIT=INCOMPLETE_FAIL_CLOSED", file=sys.stderr)
        return 2

    serialized = json.dumps(result, ensure_ascii=False, sort_keys=True, indent=2)
    if opts.report:
        path = Path(opts.report)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(serialized + "\n", encoding="utf-8")
    print("HISTORY_AUDIT=" + result["status"])
    for category, count in result["signal_counts"].items():
        print("HISTORY_SIGNAL_" + category + "=" + str(count))
    print("HISTORY_AUDIT_NO_VALUES_PRINTED=true")
    return 0  # Diagnostic only. A separate main release GO/NO-GO gate blocks #102.


if __name__ == "__main__":
    sys.exit(main())
