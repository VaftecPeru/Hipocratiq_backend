"""Synthetic fixtures; no GitHub, AWS, or production calls."""
import contextlib
import io
import json
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
import prod_github_environment_readonly as audit


def fixture():
    env = {"name": audit.ENV, "can_admins_bypass": False,
           "deployment_branch_policy": {"custom_branch_policies": True, "protected_branches": False},
           "protection_rules": [{"type": "required_reviewers", "prevent_self_review": True,
                                 "reviewers": [{"reviewer": {"id": 123}}]}]}
    branches = {"total_count": 1, "branch_policies": [{"name": "develop", "type": "branch"}]}
    repo = dict(audit.OPT_INS)
    env_vars = dict(audit.EXPECTED)
    return env, branches, repo, env_vars


class ProductionEnvironmentAuditTests(unittest.TestCase):
    def test_verified_fixture_does_not_authorize_aws(self):
        env, branches, repo, values = fixture()
        def payload(v):
            return {"total_count": len(v), "variables": [{"name": k, "value": x} for k, x in v.items()]}
        responses = {audit.ENV_PATH: env, audit.BRANCH_PATH: branches,
                     audit.REPO_VARS: payload(repo), audit.ENV_VARS: payload(values)}
        seen = []
        def read(path, token):
            seen.append(path)
            return responses[path]
        report = audit.inspect("mock-token", read)
        self.assertEqual(set(seen), audit.PATHS)
        self.assertEqual(report["blockers"], [])
        self.assertFalse(report["iam_role_live_verified"])
        self.assertFalse(report["release_authorized"])
        self.assertEqual(report["aws_api_calls"], 0)
        for value in values.values():
            self.assertNotIn(value, json.dumps(report))

    def test_fails_closed_on_unsafe_governance_and_scope(self):
        env, branches, repo, values = fixture()
        env.pop("can_admins_bypass")
        env["protection_rules"][0]["prevent_self_review"] = False
        branches["branch_policies"][0]["name"] = "main"
        repo.pop(next(iter(audit.OPT_INS)))
        repo[audit.PUSH] = "true"
        values["HIPOCRATIQ_PROD_INSTANCE_ID"] = "wrong"
        blockers = audit.evaluate(env, branches, repo, values)
        for code in ("PROD_ENVIRONMENT_ADMIN_BYPASS_NOT_VERIFIED",
                     "PROD_ENVIRONMENT_SELF_REVIEW_NOT_PREVENTED",
                     "PROD_ENVIRONMENT_DEVELOP_ONLY_NOT_VERIFIED",
                     "AUTOMATIC_PROD_METADATA_PUSH_MUST_BE_DISABLED",
                     "TARGET_NOT_VERIFIED_HIPOCRATIQ_PROD_INSTANCE_ID",
                     "REPO_OPT_IN_NOT_VERIFIED_HIPOCRATIQ_PROD_METADATA_ENABLED"):
            self.assertIn(code, blockers)

    def test_environment_only_opt_in_and_conflicting_targets_are_blocked(self):
        env, branches, repo, values = fixture()
        values.update(repo)
        repo.clear()
        repo["HIPOCRATIQ_PROD_INSTANCE_ID"] = "different"
        blockers = audit.evaluate(env, branches, repo, values)
        self.assertIn("REPO_OPT_IN_NOT_VERIFIED_HIPOCRATIQ_PROD_METADATA_ENABLED", blockers)
        self.assertIn("TARGET_SCOPE_CONFLICT_HIPOCRATIQ_PROD_INSTANCE_ID", blockers)

    def test_incomplete_list_and_denied_api_cannot_pass(self):
        with self.assertRaises(audit.AuditError):
            audit.variable_map({"total_count": 101, "variables": []})
        with self.assertRaises(audit.AuditError):
            audit.get_json("/user", "mock")
        def denied(*args):
            raise audit.AuditError("denied")
        self.assertEqual(audit.inspect("mock", denied)["status"], "NO_GO")

    def test_offline_default_and_ci_live_refusal(self):
        with patch.dict(os.environ, {"CI": "true", "HIPOCRATIQ_GH_ADMIN_READ_TOKEN": "mock"}, clear=True):
            with patch.object(audit, "inspect", side_effect=AssertionError("unexpected API call")):
                with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    self.assertEqual(audit.main([]), 0)
                    self.assertEqual(audit.main(["--live"]), 2)


if __name__ == "__main__":
    unittest.main()
