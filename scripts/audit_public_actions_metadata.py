#!/usr/bin/env python3
"""Metadata-only GitHub Actions/release inventory. NEVER fetch/download asset bytes.

Output contains counts only: no names, paths, hashes, credentials, URLs or
individual artifact identifiers. This diagnostic never grants release clearance.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

REPOSITORY = "VaftecPeru/Hipocratiq_backend"
MAX_PAGES = 50
PAGE_SIZE = 100

class InventoryError(Exception):
    pass

def github_json(path: str, token: str, opener=urlopen):
    if not token or not path.startswith(f"/repos/{REPOSITORY}/"):
        raise InventoryError("GITHUB_TOKEN_OR_PATH_NOT_APPROVED")
    req = Request("https://api.github.com" + path, headers={
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "HIPOCRATIQ-P0-102-Metadata-Only",
    })
    try:
        with opener(req, timeout=20) as response:
            data = response.read(2_000_001)
        if len(data) > 2_000_000:
            raise InventoryError("METADATA_RESPONSE_TOO_LARGE")
        return json.loads(data)
    except (HTTPError, URLError, ValueError, OSError, TimeoutError):
        raise InventoryError("GITHUB_METADATA_API_UNAVAILABLE") from None

def collect(token: str, fetch=github_json):
    """Read only REST *list* endpoints; never open an artifact URL."""
    artifacts_total = 0
    expired_total = 0
    metadata_pages = 0
    expected_artifact_total = None
    for page in range(1, MAX_PAGES + 1):
        payload = fetch(
            f"/repos/{REPOSITORY}/actions/artifacts?per_page={PAGE_SIZE}&page={page}", token
        )
        if not isinstance(payload, dict) or not isinstance(payload.get("artifacts"), list):
            raise InventoryError("INVALID_ARTIFACT_METADATA")
        reported = payload.get("total_count")
        if not isinstance(reported, int) or reported < 0:
            raise InventoryError("INVALID_ARTIFACT_TOTAL")
        if expected_artifact_total is None:
            expected_artifact_total = reported
        artifacts = payload["artifacts"]
        if len(artifacts) > PAGE_SIZE:
            raise InventoryError("INVALID_PAGE_SIZE")
        for artifact in artifacts:
            if not isinstance(artifact, dict) or not isinstance(artifact.get("expired"), bool):
                raise InventoryError("INVALID_ARTIFACT_ENTRY")
            artifacts_total += 1
            expired_total += int(artifact["expired"])
        metadata_pages += 1
        if artifacts_total >= reported and len(artifacts) < PAGE_SIZE:
            break
        if not artifacts:
            break
        if page == MAX_PAGES:
            raise InventoryError("ARTIFACT_PAGINATION_NOT_COMPLETE")
    if artifacts_total != expected_artifact_total:
        raise InventoryError("ARTIFACT_INVENTORY_CHANGED_OR_TRUNCATED")

    releases_total = 0
    release_assets_total = 0
    release_pages = 0
    for page in range(1, MAX_PAGES + 1):
        releases = fetch(
            f"/repos/{REPOSITORY}/releases?per_page={PAGE_SIZE}&page={page}", token
        )
        if not isinstance(releases, list) or len(releases) > PAGE_SIZE:
            raise InventoryError("INVALID_RELEASE_METADATA")
        for release in releases:
            if not isinstance(release, dict) or not isinstance(release.get("assets"), list):
                raise InventoryError("INVALID_RELEASE_ENTRY")
            releases_total += 1
            release_assets_total += len(release["assets"])
        release_pages += 1
        if len(releases) < PAGE_SIZE:
            break
        if page == MAX_PAGES:
            raise InventoryError("RELEASE_PAGINATION_NOT_COMPLETE")

    return {
        "gate": "HIPOCRATIQ_P0_102_ACTIONS_RELEASES_METADATA",
        "status": "PRIVATE_CONTENT_REVIEW_REQUIRED",
        "artifact_metadata_count": artifacts_total,
        "expired_artifact_metadata_count": expired_total,
        "artifact_pages_checked": metadata_pages,
        "release_metadata_count": releases_total,
        "release_assets_metadata_count": release_assets_total,
        "release_pages_checked": release_pages,
        "artifact_contents_downloaded": False,
        "release_asset_contents_downloaded": False,
        "names_or_paths_recorded": False,
        "secret_values_recorded": False,
        "credentials_rotated": False,
        "security_clearance_granted": False,
        "limitations": [
            "Only artifacts still listed by the GitHub API are counted",
            "Deleted and previously expired artifacts, forks, clones and cached downloads are not covered",
            "No artifact or release asset content is examined; this is not a secret scan",
            "Attachments, private risk review, secret validity and rotation remain unverified",
            "P0 #102 must remain open until confidential review and administrative signoff",
        ],
    }

def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", required=True)
    args = parser.parse_args(argv)
    try:
        report = collect(os.environ.get("GITHUB_TOKEN", ""))
        output = Path(args.report)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    except (InventoryError, OSError):
        print("ACTIONS_METADATA_AUDIT=INCOMPLETE_NO_GO")
        return 2
    print("ACTIONS_METADATA_AUDIT=METADATA_ONLY_PRIVATE_CONTENT_REVIEW_REQUIRED")
    print(f"ACTIONS_ARTIFACT_METADATA_COUNT={report['artifact_metadata_count']}")
    print(f"RELEASE_ASSET_METADATA_COUNT={report['release_assets_metadata_count']}")
    print("SECRET_VALUES_PRINTED=false")
    print("SECURITY_CLEARANCE_GRANTED=false")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
