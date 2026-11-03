#!/usr/bin/env python3
"""Fail-closed current Git index credential guard for HIPOCRATIQ P0 #102.

Scans only paths currently tracked by Git; prints category counts, never
filenames, matching lines, credential bytes or hashes. This does not audit
older commits, GitHub artifacts, forks, runtime secrets, or PHI.
"""
from __future__ import annotations

import argparse
from collections import Counter
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys

MAX_TRACKED = 35000
MAX_TEXT_BYTES = 2_000_000

# High-confidence credential signatures, not arbitrary API_KEY assignments.
# Do NOT add real credential examples to this source or its tests.
PATTERNS = (
    ("AWS_ACCESS_KEY_ID_FORMAT", re.compile(rb"(?<![A-Za-z0-9])(?:AKIA|ASIA)[0-9A-Z]{16}(?![A-Za-z0-9])")),
    ("GITHUB_TOKEN_FORMAT", re.compile(rb"(?:gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{35,})")),
    ("STRIPE_LIVE_SECRET_FORMAT", re.compile(rb"sk_live_[A-Za-z0-9]{16,}")),
    ("OPENAI_PROJECT_KEY_FORMAT", re.compile(rb"sk-proj-[A-Za-z0-9_-]{25,}")),
    ("PRIVATE_KEY_PEM_HEADER", re.compile(rb"-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----")),
)
TEXT_EXTENSIONS = frozenset({
    ".php", ".py", ".js", ".mjs", ".cjs", ".jsx", ".ts", ".tsx", ".dart",
    ".json", ".jsonc", ".md", ".txt", ".yml", ".yaml", ".xml", ".toml",
    ".ini", ".sh", ".bash", ".env", ".sql", ".conf", ".config",
    ".properties", ".gradle", ".html", ".css", ".scss", ".lock",
})
TEXT_NAMES = frozenset({
    "Dockerfile", "Makefile", "Procfile", ".gitignore", ".dockerignore",
    ".npmrc", ".pypirc", ".htaccess", ".env.example", ".env.sample",
})


class CurrentTreeSecurityError(Exception):
    pass


def sensitive_path_category(path: str) -> str | None:
    p = path.replace("\\", "/")
    name = PurePosixPath(p).name.lower()
    if name == ".env" or (
        (name.startswith(".env.") or name.startswith(".env-"))
        and name not in {".env.example", ".env.sample", ".env.template", ".env.dist"}
    ):
        return "TRACKED_RUNTIME_ENV"
    if name in {"auth.json", "credentials.json", "id_rsa", "id_ed25519", "id_ecdsa"}:
        return "TRACKED_CREDENTIAL_FILE"
    if name.endswith((".p12", ".pfx", ".key", ".dump", ".sql.gz", ".sqlite3")):
        return "TRACKED_SENSITIVE_EXPORT_OR_KEY"
    return None


def scan(root: Path) -> dict:
    root = root.resolve(strict=True)
    try:
        result = subprocess.run(
            ["git", "-C", str(root), "ls-files", "--cached", "-z"],
            capture_output=True, check=False, timeout=30,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise CurrentTreeSecurityError("TRACKED_FILE_LIST_UNAVAILABLE") from None
    if result.returncode:
        raise CurrentTreeSecurityError("TRACKED_FILE_LIST_UNAVAILABLE")
    paths = [os.fsdecode(p) for p in result.stdout.split(b"\0") if p]
    if not paths or len(paths) > MAX_TRACKED:
        raise CurrentTreeSecurityError("TRACKED_FILE_LIST_INVALID")

    counts = Counter()
    scanned = 0
    for relative in paths:
        path = root / relative
        category = sensitive_path_category(relative)
        if category:
            counts[category] += 1
            continue
        try:
            mode = path.lstat().st_mode
        except OSError:
            raise CurrentTreeSecurityError("TRACKED_FILE_NOT_READABLE") from None
        if stat.S_ISLNK(mode):
            # Do not follow symlinks to external files or secrets.
            counts["TRACKED_SYMLINK_REQUIRES_REVIEW"] += 1
            continue
        if not stat.S_ISREG(mode):
            raise CurrentTreeSecurityError("TRACKED_ENTRY_NOT_REGULAR")
        name = path.name
        if path.suffix.lower() not in TEXT_EXTENSIONS and name not in TEXT_NAMES:
            continue
        try:
            if path.stat().st_size > MAX_TEXT_BYTES:
                counts["OVERSIZED_TEXT_FILE_NOT_SCANNED"] += 1
                continue
            data = path.read_bytes()
        except OSError:
            raise CurrentTreeSecurityError("TRACKED_FILE_NOT_READABLE") from None
        scanned += 1
        for kind, pattern in PATTERNS:
            if pattern.search(data):
                counts[kind] += 1

    return {
        "status": "NO_GO" if counts else "NO_CURRENT_TREE_SCANNER_SIGNAL_NOT_CLEARANCE",
        "tracked_files": len(paths),
        "text_files_examined": scanned,
        "findings_by_category": dict(sorted(counts.items())),
        "filenames_or_credentials_recorded": False,
        "history_or_artifacts_audited": False,
        "security_clearance_granted": False,
    }


def main(argv=None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", default=".")
    args = parser.parse_args(argv)
    try:
        report = scan(Path(args.repository))
    except (CurrentTreeSecurityError, OSError, ValueError):
        print("CURRENT_TREE_SECURITY=INCOMPLETE_FAIL_CLOSED", file=sys.stderr)
        return 2

    print("CURRENT_TREE_SECURITY=" + report["status"])
    print("TRACKED_FILES_CHECKED=" + str(report["tracked_files"]))
    for kind, count in report["findings_by_category"].items():
        print("FINDING_CATEGORY_" + kind + "=" + str(count))
    print("SECRET_VALUES_PRINTED=false")
    print("P0_102_SECURITY_APPROVAL=false")
    return 1 if report["status"] == "NO_GO" else 0


if __name__ == "__main__":
    raise SystemExit(main())
