"""Synthetic-only regression checks for the release evidence guard."""
import datetime as dt
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
from release_go_no_go import (  # noqa: E402
    REPOSITORY, assess, normalize_inventory_run, http_get_json, GateError
)

NOW = dt.datetime(2026, 10, 24, 20, tzinfo=dt.timezone.utc)
SHA = "5" * 40


def fixtures():
    pr = {
        "base": {"ref": "main"},
        "head": {"sha": SHA, "repo": {"full_name": REPOSITORY}},
        "draft": False, "state": "open",
    }
    main = {"protected": True}
    develop = {"commit": {"sha": SHA}}
    issue = {"state": "closed"}
    inventory = [{
        "created_at": "2026-10-24T19:00:00Z",
        "head_branch": "develop", "event": "workflow_dispatch",
        "run_conclusion": "success", "aws_job_conclusion": "success",
        "artifact_present": True, "run_id": 123,
    }]
    return pr, main, develop, issue, inventory


class ReleaseEvidenceTests(unittest.TestCase):
    def test_only_minimal_supported_github_evidence_passes(self):
        self.assertEqual(assess(*fixtures(), now=NOW), [])

    def test_draft_or_wrong_sha_must_fail(self):
        pr, main, develop, issue, inv = fixtures()
        pr["draft"] = True
        pr["head"]["sha"] = "a" * 40
        self.assertGreaterEqual(len(assess(pr, main, develop, issue, inv, now=NOW)), 2)

    def test_unprotected_main_refuses_release(self):
        pr, main, develop, issue, inv = fixtures()
        main["protected"] = False
        self.assertIn("not protected", " ".join(assess(pr, main, develop, issue, inv, now=NOW)))

    def test_open_clinical_p0_issue_refuses_release(self):
        pr, main, develop, issue, inv = fixtures()
        issue["state"] = "open"
        self.assertIn("#92 remains open", " ".join(assess(pr, main, develop, issue, inv, now=NOW)))

    def test_green_offline_with_skipped_aws_must_fail(self):
        pr, main, develop, issue, inv = fixtures()
        inv[0]["aws_job_conclusion"] = "skipped"
        self.assertIn("not SKIPPED", " ".join(assess(pr, main, develop, issue, inv, now=NOW)))

    def test_missing_or_expired_aws_artifact_refuses(self):
        pr, main, develop, issue, inv = fixtures()
        inv[0]["artifact_present"] = False
        self.assertTrue(assess(pr, main, develop, issue, inv, now=NOW))
        run = {"created_at": "2026-10-24T19:00:00Z", "conclusion": "success"}
        jobs = [{"name": "Approved AWS UAT instance metadata ONLY", "conclusion": "success"}]
        artifacts = [{"name": "hipocratiq-uat-aws-readonly-metadata",
                      "expired": True, "size_in_bytes": 2048}]
        self.assertFalse(normalize_inventory_run(run, jobs, artifacts)["artifact_present"])

    def test_stale_or_unrelated_aws_evidence_refuses(self):
        pr, main, develop, issue, inv = fixtures()
        inv[0]["created_at"] = "2026-09-01T19:00:00Z"
        self.assertTrue(assess(pr, main, develop, issue, inv, now=NOW))
        inv[0]["created_at"] = "2026-10-24T19:00:00Z"
        inv[0]["head_branch"] = "main"
        self.assertTrue(assess(pr, main, develop, issue, inv, now=NOW))

    def test_forked_release_branch_refuses(self):
        pr, main, develop, issue, inv = fixtures()
        pr["head"]["repo"]["full_name"] = "somebody/unauthorized-fork"
        self.assertIn("no fork", " ".join(assess(pr, main, develop, issue, inv, now=NOW)))

    def test_deny_unapproved_github_api_requests(self):
        with self.assertRaises(GateError):
            http_get_json("/repos/other/project/issues/1", token="synthetic")


if __name__ == "__main__":
    unittest.main()
