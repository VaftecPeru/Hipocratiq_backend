"""Offline, credential-free regression tests for UAT GitHub admin gate."""
import copy
import importlib.util
import io
import json
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import Mock

SCRIPT = Path(__file__).resolve().parents[2] / "scripts/uat_github_admin_governance_readonly.py"
spec = importlib.util.spec_from_file_location("uat_gh_admin_governance", SCRIPT)
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)

SHA = "a" * 40
PROTECTION = {
    "required_status_checks": {"strict": True, "checks": [
        {"context": "lint"},
        {"context": "Release evidence / main P0 NO-GO (read-only)"},
        {"context": "web"},
        {"context": "Frontend CI"},
        {"context": "Frontend E2E role routing"},
    ]},
    "enforce_admins": {"enabled": True},
    "required_pull_request_reviews": {
        "required_approving_review_count": 1,
        "dismiss_stale_reviews": True,
    },
    "allow_force_pushes": {"enabled": False},
    "allow_deletions": {"enabled": False},
    "required_conversation_resolution": {"enabled": True},
}
ENV = {
    "name": guard.ENVIRONMENT,
    "deployment_branch_policy": {
        "custom_branch_policies": True,
        "protected_branches": False,
    },
    "protection_rules": [{
        "type": "required_reviewers",
        "reviewers": [{"type": "User", "reviewer": {"id": 123}}],
        "prevent_self_review": True,
    }],
}
POLICIES = {"branch_policies": [{"name": "develop", "type": "branch"}]}


class GovernanceTests(unittest.TestCase):
    def test_branch_protection_full_policy(self):
        for repo, branch in guard.EXPECTED_CHECKS:
            with self.subTest(repo=repo, branch=branch):
                payload = {"name": branch, "protected": True, "commit": {"sha": SHA}}
                self.assertEqual(guard.evaluate_branch(repo, branch, payload, PROTECTION), [])

    def test_missing_protected_flag_does_not_claim_safety(self):
        results = guard.evaluate_branch(guard.BACKEND, "develop",
                                        {"name": "develop", "protected": False},
                                        PROTECTION)
        self.assertTrue(any("UNPROTECTED" in v for v in results))

    def test_required_checks_reviews_admins_and_no_force_deletion(self):
        scenarios = [
            ("required_status_checks", {"strict": True, "checks": []}, "REQUIRED_CHECKS_MISSING"),
            ("required_status_checks", {"strict": False, "checks": [{"context": "lint"}]}, "OUTDATED_CHECKS_NOT_BLOCKED"),
            ("required_pull_request_reviews", {"required_approving_review_count": 0,
                                               "dismiss_stale_reviews": False}, "REVIEWS_NOT_ENFORCED"),
            ("enforce_admins", {"enabled": False}, "ADMIN_BYPASS_NOT_BLOCKED"),
            ("allow_force_pushes", {"enabled": True}, "FORCE_PUSH_ALLOWED"),
            ("allow_deletions", {"enabled": True}, "DELETION_ALLOWED"),
            ("required_conversation_resolution", {"enabled": False}, "CONVERSATIONS_NOT_REQUIRED"),
        ]
        for key, wrong, code in scenarios:
            with self.subTest(code=code):
                policy = copy.deepcopy(PROTECTION)
                policy[key] = wrong
                checks = guard.evaluate_branch(guard.BACKEND, "develop",
                                               {"name": "develop", "protected": True}, policy)
                self.assertTrue(any(code in v for v in checks))

    def test_exact_environment_reviewers_self_review_and_branch(self):
        self.assertEqual(guard.evaluate_environment(ENV, POLICIES), [])
        scenarios = [
            (dict(ENV, deployment_branch_policy={"protected_branches": True,
                                                  "custom_branch_policies": False}),
             POLICIES, "DEVELOP_ONLY_POLICY_MISSING"),
            (ENV, {"branch_policies": [{"name": "develop"}, {"name": "main"}]},
             "ALLOWED_BRANCH_NOT_EXACT_DEVELOP"),
            (dict(ENV, protection_rules=[]), POLICIES, "REQUIRED_REVIEWERS_UNAVAILABLE"),
            (dict(ENV, protection_rules=[{"type": "required_reviewers", "reviewers": []}]),
             POLICIES, "NO_NAMED_REVIEWER"),
            (dict(ENV, protection_rules=[{"type": "required_reviewers",
                                          "reviewers": [{"reviewer": {"id": 1}}]}]),
             POLICIES, "SELF_APPROVAL_NOT_PREVENTED"),
        ]
        for env, policies, blocker in scenarios:
            with self.subTest(blocker=blocker):
                self.assertTrue(any(blocker in code
                                    for code in guard.evaluate_environment(env, policies)))

    def test_one_missing_read_fails_closed_and_no_sensitive_details(self):
        def fake_read(path, token):
            if path.endswith("/protection"):
                return copy.deepcopy(PROTECTION)
            if "/environments/" in path:
                return copy.deepcopy(POLICIES if "deployment-branch-policies" in path else ENV)
            if path.endswith("/branches/develop"):
                return {"name": "develop", "protected": True}
            if path.endswith("/branches/main"):
                return {"name": "main", "protected": True}
            raise guard.GovernanceError("REDACTED_READ_ERROR")
        result = guard.inspect(fake_read, "fake-sensitive-token")
        self.assertEqual(result["status"], "CONFIG_VERIFIED_NOT_AWS_RELEASE_APPROVAL")
        self.assertEqual(result["aws_api_calls"], 0)
        self.assertFalse(result["aws_oidc_role_verified"])
        self.assertNotIn("fake-sensitive-token", json.dumps(result))

        def denied(path, token):
            raise guard.GovernanceError("GITHUB_ADMIN_API_READ_UNAVAILABLE")
        denied_result = guard.inspect(denied, "secret-token")
        self.assertEqual(denied_result["status"], "NO_GO")
        self.assertTrue(denied_result["blockers"])
        self.assertNotIn("secret-token", json.dumps(denied_result))

    def test_get_allowlist_and_missing_token_are_safe(self):
        with self.assertRaisesRegex(guard.GovernanceError, "UNAUTHORIZED_API_ENDPOINT"):
            guard.read_json("/repos/other/other/issues/1", "secret")
        with self.assertRaisesRegex(guard.GovernanceError, "ADMIN_READ_TOKEN_UNAVAILABLE"):
            guard.read_json("/repos/" + guard.BACKEND + "/branches/develop", "")
        body = json.dumps({"name": "develop", "protected": False}).encode()
        mock = Mock()
        mock.__enter__ = Mock(return_value=mock)
        mock.__exit__ = Mock(return_value=False)
        mock.read.return_value = body
        fetcher = Mock(return_value=mock)
        result = guard.read_json("/repos/" + guard.BACKEND + "/branches/develop",
                                 "fake-token", fetch=fetcher)
        self.assertFalse(result["protected"])
        request, = fetcher.call_args.args
        self.assertEqual(request.get_method(), "GET")
        self.assertTrue(request.full_url.startswith(guard.API + "/repos/"))

    def test_default_cli_does_not_read_network_or_credentials(self):
        captured = io.StringIO()
        with redirect_stdout(captured):
            code = guard.main([])
        self.assertEqual(code, 0)
        self.assertIn("OFFLINE_NO_GITHUB_OR_AWS_READ", captured.getvalue())


if __name__ == "__main__":
    unittest.main()
