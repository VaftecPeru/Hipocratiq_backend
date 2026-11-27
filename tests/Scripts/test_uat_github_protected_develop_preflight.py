"""Offline UAT GitHub branch-protection guard: GitHub and AWS calls are mocked."""
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import Mock
from urllib.error import HTTPError

SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "uat_github_protected_develop_preflight.py"
spec = importlib.util.spec_from_file_location("uat_protected_develop", SCRIPT)
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)

SHA = "abcdef1234567890abcdef1234567890abcdef12"
ENV = {
    "GITHUB_REPOSITORY": "VaftecPeru/Hipocratiq_backend",
    "GITHUB_REF": "refs/heads/develop",
    "GITHUB_SHA": SHA,
    "GITHUB_EVENT_NAME": "workflow_dispatch",
}
RESPONSE = {"name": "develop", "protected": True, "commit": {"sha": SHA}}


class ReadOnlyBranchGateTests(unittest.TestCase):
    def test_exact_protected_develop_sha_is_necessary_but_not_admin_approval(self):
        result = guard.assess_branch_protection(RESPONSE, ENV)
        self.assertTrue(result["protected_develop_sha_confirmed"])
        self.assertFalse(result["environment_reviewers_verified"])
        self.assertFalse(result["aws_credentials_requested"])

    def test_unprotected_develop_denies_before_aws(self):
        with self.assertRaisesRegex(guard.UatGateError, "DEVELOP_BRANCH_UNPROTECTED"):
            guard.assess_branch_protection(dict(RESPONSE, protected=False), ENV)

    def test_workflow_sha_must_match_current_protected_branch(self):
        with self.assertRaisesRegex(guard.UatGateError, "DEVELOP_SHA_CHANGED_OR_UNVERIFIED"):
            guard.assess_branch_protection(
                dict(RESPONSE, commit={"sha": "f" * 40}), ENV
            )

    def test_rejects_missing_or_bad_sha(self):
        for sha in ("", "not-a-sha", "1" * 39, "A" * 40):
            with self.subTest(sha=sha):
                with self.assertRaisesRegex(guard.UatGateError, "INVALID_WORKFLOW_SHA"):
                    guard.assess_branch_protection(RESPONSE, dict(ENV, GITHUB_SHA=sha))

    def test_rejects_other_repo_branch_or_trigger(self):
        examples = (
            ("GITHUB_REPOSITORY", "other/repo", "UNEXPECTED_REPOSITORY"),
            ("GITHUB_REF", "refs/heads/main", "NOT_DEVELOP"),
            ("GITHUB_REF", "refs/pull/1/merge", "NOT_DEVELOP"),
            ("GITHUB_EVENT_NAME", "pull_request", "UNAUTHORIZED_TRIGGER"),
        )
        for key, value, expected in examples:
            with self.subTest(key=key, value=value):
                with self.assertRaisesRegex(guard.UatGateError, expected):
                    guard.assess_branch_protection(RESPONSE, dict(ENV, **{key: value}))

    def test_rejects_wrong_response_or_protected_null(self):
        for payload in ({}, None, dict(RESPONSE, name="main"),
                        dict(RESPONSE, protected=None)):
            with self.subTest(payload=str(payload)):
                with self.assertRaises(guard.UatGateError):
                    guard.assess_branch_protection(payload, ENV)

    def test_github_fetch_is_read_only_one_fixed_url(self):
        fake_body = json.dumps(RESPONSE).encode()
        response = Mock()
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        response.read.return_value = fake_body
        fetcher = Mock(return_value=response)
        self.assertEqual(guard.read_github_branch("not-a-real-token", fetcher), RESPONSE)
        self.assertEqual(fetcher.call_count, 1)
        request, = fetcher.call_args.args
        self.assertEqual(request.full_url, guard.URL)
        self.assertEqual(request.get_method(), "GET")
        self.assertEqual(fetcher.call_args.kwargs["timeout"], 10)

    def test_no_token_or_http_error_fails_closed_without_token_leak(self):
        fetcher = Mock(side_effect=AssertionError("should not connect"))
        with self.assertRaisesRegex(guard.UatGateError, "GITHUB_TOKEN_MISSING"):
            guard.read_github_branch("", fetcher)
        fetcher.assert_not_called()
        def denied(request, timeout):
            raise HTTPError(request.full_url, 403, "Forbidden", {}, None)
        with self.assertRaisesRegex(guard.UatGateError, "GITHUB_BRANCH_READ_UNAVAILABLE"):
            guard.read_github_branch("sensitive-value", denied)

    def test_oversize_or_invalid_response_fails_closed(self):
        for content in (b"{" * 1000001, b"unparseable"):
            response = Mock()
            response.__enter__ = Mock(return_value=response)
            response.__exit__ = Mock(return_value=False)
            response.read.return_value = content
            with self.assertRaises(guard.UatGateError):
                guard.read_github_branch("synthetic-token", Mock(return_value=response))


if __name__ == "__main__":
    unittest.main()
