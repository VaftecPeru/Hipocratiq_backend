"""Compare synthetic PostgreSQL catalog rows without printing identifiers or definitions."""
import collections
import json
import sys


def load(path, kind):
    width = 5 if kind == "constraints" else 4
    rows = []
    with open(path, encoding="utf-8") as stream:
        for line in stream:
            row = json.loads(line)
            if not isinstance(row, list) or len(row) != width:
                raise ValueError("invalid catalog row")
            if not all(isinstance(v, str) for v in row[:2]) or not isinstance(row[-1], str):
                raise ValueError("invalid catalog identity")
            if type(row[-2]) is not bool:
                raise ValueError("invalid catalog validity")
            if kind == "constraints" and row[2] not in ("c", "f", "p", "u", "t", "x", "n"):
                raise ValueError("invalid constraint type")
            rows.append(tuple(row))
    if not rows:
        raise ValueError("empty catalog")
    return rows


def compare(kind, expected, actual):
    left, right = collections.Counter(expected), collections.Counter(actual)
    missing, extra = left - right, right - left
    if not missing and not extra:
        return 0
    # Classify differing rows by stable identity and attribute, without exposing catalog values.
    key_width = 3 if kind == "constraints" else 2
    old = collections.defaultdict(list)
    new = collections.defaultdict(list)
    for row in missing.elements():
        old[row[:key_width]].append(row)
    for row in extra.elements():
        new[row[:key_width]].append(row)
    matched = validity = definition = 0
    for key in old.keys() & new.keys():
        if len(old[key]) == len(new[key]) == 1:
            a, b = old[key][0], new[key][0]
            matched += 1
            validity += a[-2] != b[-2]
            definition += a[-1] != b[-1]
    print(
        f"CATALOG_MISMATCH kind={kind} expected={len(expected)} actual={len(actual)} "
        f"missing={sum(missing.values())} extra={sum(extra.values())} "
        f"changed_identity_matches={matched} validity_changes={validity} definition_changes={definition}",
        file=sys.stderr,
    )
    if kind == "constraints":
        for label, diff in (("missing", missing), ("extra", extra)):
            counts = collections.Counter()
            for row, count in diff.items():
                counts[row[2]] += count
            summary = ",".join(f"{code}:{counts[code]}" for code in sorted(counts)) or "none"
            print(f"CONSTRAINT_TYPES_{label.upper()}={summary}", file=sys.stderr)
    return 1


def main():
    if len(sys.argv) != 4 or sys.argv[1] not in ("constraints", "indexes"):
        return 2
    kind = sys.argv[1]
    try:
        return compare(kind, load(sys.argv[2], kind), load(sys.argv[3], kind))
    except (OSError, ValueError, TypeError):
        print("CATALOG_COMPARE=INVALID_INPUT_NO_GO", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
