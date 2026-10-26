"""Synthetic-only regression checks for the release evidence guard."""
import datetime as dt
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
from release_go_no_go import (  # noqa: E402
    REPOSITORY, FRONTEND_REPOSITORY, assess, assess_frontend_release,
    normalize_inventory_run, http_get_json, GateError
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
    develop = {"commit": {"sha": SHA}, "protected": True}
    issue = {"state": "closed"}
    inventory = [{
        "created_at": "2026-10-24T19:00:00Z",
        "head_branch": "develop", "head_sha": SHA,
        "event": "workflow_dispatch",
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

    def test_backend_develop_requires_protection(self):
        pr, main, develop, issue, inv = fixtures()
        develop["protected"] = False
        self.assertIn(
            "Backend develop branch is not protected",
            " ".join(assess(pr, main, develop, issue, inv, now=NOW)),
        )

    def test_frontend_release_must_be_protected_ready_and_sha_aligned(self):
        frontend_pr = {
            "base": {"ref": "main"},
            "head": {"sha": SHA, "repo": {"full_name": FRONTEND_REPOSITORY}},
            "state": "open",
            "draft": False,
        }
        frontend_main = {"protected": True}
        frontend_develop = {"protected": True, "commit": {"sha": SHA}}

        self.assertEqual(
            assess_frontend_release(frontend_pr, frontend_main, frontend_develop),
            [],
        )
        frontend_main["protected"] = False
        frontend_pr["draft"] = True
        frontend_pr["head"]["sha"] = "a" * 40
        errors = assess_frontend_release(frontend_pr, frontend_main, frontend_develop)
        self.assertTrue(any("Frontend main branch is not protected" in x for x in errors))
        self.assertTrue(any("DRAFT" in x for x in errors))
        self.assertTrue(any("SHA differs" in x for x in errors))

    def test_frontend_release_rejects_fork_wrong_base_or_unprotected_develop(self):
        frontend_pr = {
            "base": {"ref": "develop"},
            "head": {"sha": SHA, "repo": {"full_name": "untrusted/fork"}},
            "state": "closed",
            "draft": False,
        }
        frontend_main = {"protected": True}
        frontend_develop = {"protected": False, "commit": {"sha": SHA}}
        reasons = assess_frontend_release(frontend_pr, frontend_main, frontend_develop)
        self.assertGreaterEqual(len(reasons), 4)

    def test_rejects_other_repositories_when_reading_github_evidence(self):
        with self.assertRaises(GateError):
            http_get_json(
                "/repos/unknown/project/branches/main",
                token="synthetic",
                repository="unknown/project",
            )

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

    def test_recent_real_inventory_from_old_develop_sha_must_fail(self):
        """Successful AWS job and artifact for prior commit cannot unblock release."""
        pr, main, develop, issue, inv = fixtures()
        inv[0]["head_sha"] = "a" * 40
        self.assertIn(
            "exact current develop SHA",
            " ".join(assess(pr, main, develop, issue, inv, now=NOW)),
        )

    def test_inventory_without_commit_sha_must_fail(self):
        pr, main, develop, issue, inv = fixtures()
        inv[0].pop("head_sha")
        self.assertTrue(assess(pr, main, develop, issue, inv, now=NOW))

    def test_normalizer_retains_aws_inventory_commit_sha(self):
        run = {
            "id": 123, "head_branch": "develop", "head_sha": SHA,
            "created_at": "2026-10-24T19:00:00Z", "event": "workflow_dispatch",
            "conclusion": "success",
        }
        jobs = [{"name": "Approved AWS UAT instance metadata ONLY",
                 "conclusion": "success"}]
        artifacts = [{"name": "hipocratiq-uat-aws-readonly-metadata",
                      "expired": False, "size_in_bytes": 2048}]
        item = normalize_inventory_run(run, jobs, artifacts)
        self.assertEqual(item["head_sha"], SHA)
        self.assertTrue(item["artifact_present"])

    def test_forked_release_branch_refuses(self):
        pr, main, develop, issue, inv = fixtures()
        pr["head"]["repo"]["full_name"] = "somebody/unauthorized-fork"
        self.assertIn("no fork", " ".join(assess(pr, main, develop, issue, inv, now=NOW)))

    def test_deny_unapproved_github_api_requests(self):
        with self.assertRaises(GateError):
            http_get_json("/repos/other/project/issues/1", token="synthetic")

    def test_release_evidence_runs_for_all_main_pull_requests(self):
        """No paths filter: every code-only PR to main must execute NO-GO."""
        workflow_path = Path(__file__).resolve().parents[2] / (
            ".github/workflows/release-readiness.yml"
        )
        workflow = workflow_path.read_text(encoding="utf-8")
        self.assertIn("  pull_request:", workflow)
        trigger_section = workflow.split("on:\n", 1)[-1].split(
            "\npermissions:", 1
        )[0]
        self.assertIn("branches: [develop, main]", trigger_section)
        self.assertNotIn("\n    paths:", trigger_section)
        self.assertNotIn("\n    paths-ignore:", trigger_section)
        self.assertIn(
            "github.event_name == 'pull_request' && github.base_ref == 'main'",
            workflow,
        )
        self.assertIn("python3 scripts/release_go_no_go.py", workflow)


if __name__ == "__main__":
    unittest.main()
