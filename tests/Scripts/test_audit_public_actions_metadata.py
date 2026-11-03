"""Synthetic tests only: never call GitHub or download credential-containing artifacts."""
import importlib.util
import json
import unittest
from pathlib import Path

SRC = Path(__file__).resolve().parents[2] / "scripts" / "audit_public_actions_metadata.py"
SPEC = importlib.util.spec_from_file_location("audit_public_actions_metadata", SRC)
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)

class ActionsMetadataAuditTest(unittest.TestCase):
    def test_aggregate_only_and_never_fetch_individual_artifact(self):
        calls = []
        token = "synthetic-token"
        def fetch(path, actual_token):
            self.assertEqual(actual_token, token)
            calls.append(path)
            self.assertTrue(
                path.startswith(f"/repos/{audit.REPOSITORY}/actions/artifacts?")
                or path.startswith(f"/repos/{audit.REPOSITORY}/releases?")
            )
            if "/actions/artifacts?" in path:
                return {"total_count": 2, "artifacts": [
                    {"id": 1, "name": "sensitive-should-not-appear", "expired": False,
                     "archive_download_url": "https://example.invalid/secret"},
                    {"id": 2, "name": "confidential-should-not-appear", "expired": True},
                ]}
            return [{"name": "internal-release-should-not-appear", "assets": [
                {"id": 99, "name": "secret-asset-not-to-appear",
                 "browser_download_url": "https://example.invalid/private"}
            ]}]
        report = audit.collect(token, fetch=fetch)
        self.assertEqual(report["artifact_metadata_count"], 2)
        self.assertEqual(report["expired_artifact_metadata_count"], 1)
        self.assertEqual(report["release_metadata_count"], 1)
        self.assertEqual(report["release_assets_metadata_count"], 1)
        self.assertFalse(report["security_clearance_granted"])
        self.assertFalse(report["artifact_contents_downloaded"])
        report_text = json.dumps(report)
        for sensitive in ("sensitive-should-not-appear", "confidential-should-not-appear",
                          "internal-release-should-not-appear", "secret-asset-not-to-appear",
                          "example.invalid"):
            self.assertNotIn(sensitive, report_text)
        self.assertEqual(len(calls), 2)

    def test_pagination_counts_more_than_one_page_without_contents(self):
        seen = []
        def fetch(path, _token):
            seen.append(path)
            if "actions/artifacts?" in path:
                if "page=1" in path:
                    return {"total_count": 101, "artifacts": [
                        {"expired": False} for _ in range(100)]}
                if "page=2" in path:
                    return {"total_count": 101, "artifacts": [{"expired": False}]}
                self.fail("Unexpected artifact page")
            return []
        report = audit.collect("synthetic-token", fetch=fetch)
        self.assertEqual(report["artifact_metadata_count"], 101)
        self.assertEqual(report["artifact_pages_checked"], 2)
        self.assertEqual(report["release_metadata_count"], 0)

    def test_incomplete_or_malformed_metadata_fails_closed(self):
        for response in (
            {"total_count": 3, "artifacts": [{"expired": False}]},
            {"total_count": 1, "artifacts": [{"expired": "false"}]},
            {"total_count": 1, "artifacts": None},
        ):
            with self.subTest(response=str(response)):
                def fetch(path, _token):
                    if "actions/artifacts?" in path:
                        return response if "page=1" in path else {"total_count": 3, "artifacts": []}
                    return []
                with self.assertRaises(audit.InventoryError):
                    audit.collect("synthetic-token", fetch=fetch)

    def test_rejects_empty_token_or_wrong_path_before_network(self):
        for bad_path in ("/repos/other/repo/actions/artifacts", "/user",
                         "https://external.invalid/payload"):
            with self.assertRaises(audit.InventoryError):
                audit.github_json(bad_path, "synthetic-token")
        with self.assertRaises(audit.InventoryError):
            audit.github_json(f"/repos/{audit.REPOSITORY}/actions/artifacts", "")

    def test_pagination_limit_does_not_claim_completed_review(self):
        original = audit.MAX_PAGES
        try:
            audit.MAX_PAGES = 1
            def fetch(path, _token):
                if "actions/artifacts?" in path:
                    return {"total_count": 101, "artifacts": [
                        {"expired": False} for _ in range(100)]}
                return []
            with self.assertRaises(audit.InventoryError):
                audit.collect("synthetic-token", fetch=fetch)
        finally:
            audit.MAX_PAGES = original

if __name__ == "__main__":
    unittest.main()
