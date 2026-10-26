#!/usr/bin/env python3
"""Fail closed if example env files contain configured credentials.

Never print values or the original lines. This is a preventive check only;
it cannot certify Git history, GitHub artifacts or deployed secret rotation.
"""
from __future__ import annotations

import pathlib
import re
import sys

SENSITIVE = frozenset({
    "APP_KEY",
    "DB_PASSWORD",
    "REDIS_PASSWORD",
    "MAIL_PASSWORD",
    "AWS_ACCESS_KEY_ID",
    "AWS_SECRET_ACCESS_KEY",
    "OTP_HASH_KEY",
    "BILLING_HTTP_TOKEN",
    "EPIDEMIOLOGICAL_AI_API_KEY",
    "TELEMEDICINE_ROOM_SECRET",
    "WHATSAPP_ACCESS_TOKEN",
    "HIPOCRATIQ_UAT_ACCESS_PASSWORD",
})
REQUIRED = frozenset({"DB_PASSWORD", "REDIS_PASSWORD"})
ENV_ASSIGNMENT = re.compile(r"^([A-Z][A-Z0-9_]*)=(.*)$")


def verify_template(content: str) -> tuple[bool, list[str]]:
    found = set()
    problems = []
    for line in content.splitlines():
        match = ENV_ASSIGNMENT.fullmatch(line.strip())
        if match is None:
            continue
        key, raw_value = match.groups()
        if key not in SENSITIVE:
            continue
        if key in found:
            problems.append(key + ":DUPLICATE_KEY")
        found.add(key)
        if raw_value.strip().strip('\\\"\''):
            problems.append(key + ":NONEMPTY_VALUE")
    for key in sorted(REQUIRED - found):
        problems.append(key + ":MISSING_KEY")
    return not problems, problems


def main(argv: list[str] | None = None) -> int:
    args = sys.argv[1:] if argv is None else argv
    if len(args) != 1:
        print("ENV_TEMPLATE=FAIL: exactly one file path is required", file=sys.stderr)
        return 2
    path = pathlib.Path(args[0])
    if path.name != ".env.example":
        print("ENV_TEMPLATE=FAIL: file must be named .env.example", file=sys.stderr)
        return 2
    try:
        content = path.read_text(encoding="utf-8")
    except (OSError, UnicodeError):
        print("ENV_TEMPLATE=FAIL: file is unavailable", file=sys.stderr)
        return 2
    ok, problems = verify_template(content)
    if not ok:
        print("ENV_TEMPLATE=FAIL: " + ", ".join(problems), file=sys.stderr)
        return 1
    print("ENV_TEMPLATE=PASS: sensitive example settings are blank")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
