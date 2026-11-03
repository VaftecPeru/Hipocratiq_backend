"""Offline fail-closed Frontend SHA coherence tests (no HTTP requests)."""
import importlib.util
import pathlib
import os
import unittest
from unittest.mock import patch

SCRIPT = pathlib.Path(__file__).resolve().parents[2] / "scripts" / "verify_frontend_release_sync.py"
spec = importlib.util.spec_from_file_location("release_sync", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

SHA = "4" * 40
OTHER_SHA = "a" * 40


class FrontendReleaseSyncTests(unittest.TestCase):
    def setUp(self):
        self.develop = {"commit": {"sha": SHA}}
        self.release_pr = {
            "state": "open",
            "draft": True,
            "base": {"ref": "main"},
            "head": {
                "sha": SHA,
                "repo": {"full_name": "VaftecPeru/Hipocratiq_Frontend"},
            },
        }

    def test_accepts_exact_immutable_sha_with_draft_release(self):
        value = module.verify_frontend_sync(SHA, self.develop, self.release_pr)
        self.assertEqual(value["status"], "FRONTEND_RELEASE_SHA_MATCH")
        self.assertTrue(value["frontend_release_is_draft"])

    def test_denies_stale_develop_sha(self):
        self.develop["commit"]["sha"] = OTHER_SHA
        with self.assertRaisesRegex(module.ReleaseSyncError, "develop has advanced"):
            module.verify_frontend_sync(SHA, self.develop, self.release_pr)

    def test_denies_stale_release_candidate(self):
        self.release_pr["head"]["sha"] = OTHER_SHA
        with self.assertRaisesRegex(module.ReleaseSyncError, "HEAD differs"):
            module.verify_frontend_sync(SHA, self.develop, self.release_pr)

    def test_denies_wrong_target_or_closed_release(self):
        self.release_pr["base"]["ref"] = "develop"
        self.release_pr["state"] = "closed"
        with self.assertRaises(module.ReleaseSyncError):
            module.verify_frontend_sync(SHA, self.develop, self.release_pr)

    def test_denies_forked_release_source(self):
        self.release_pr["head"]["repo"]["full_name"] = "other/repository"
        with self.assertRaisesRegex(module.ReleaseSyncError, "approved repository"):
            module.verify_frontend_sync(SHA, self.develop, self.release_pr)

    def test_denies_non_commit_refs_and_fake_shas(self):
        for invalid in ["develop", "a" * 39, "A" * 40, "", None]:
            with self.subTest(invalid=invalid):
                with self.assertRaises(module.ReleaseSyncError):
                    module.verify_frontend_sync(invalid, self.develop, self.release_pr)

    def test_rejects_non_whitelisted_api_requests(self):
        with self.assertRaises(module.ReleaseSyncError):
            module.fetch_public_json("/user")


    def test_ci_uses_temporary_read_only_token_for_whitelisted_public_api(self):
        class FakeResponse:
            def __enter__(self):
                return self
            def __exit__(self, *_args):
                return False
            def read(self, max_bytes):
                self.max_bytes = max_bytes
                return b'{"commit":{"sha":"synthetic"}}'
        class FakeHttp:
            def __init__(self):
                self.requests = []
            def __call__(self, req, timeout):
                self.requests.append((req, timeout))
                return FakeResponse()
        fake = FakeHttp()
        with patch.dict(os.environ, {
            "GITHUB_ACTIONS": "true",
            "GITHUB_TOKEN": "synthetic-ephemeral-token"
        }, clear=True), patch.object(module, "urlopen", fake):
            got = module.fetch_public_json(
                "/repos/VaftecPeru/Hipocratiq_Frontend/branches/develop"
            )
        self.assertEqual(got["commit"]["sha"], "synthetic")
        self.assertEqual(len(fake.requests), 1)
        request, timeout = fake.requests[0]
        self.assertEqual(timeout, 12)
        self.assertEqual(
            request.get_header("Authorization"), "Bearer synthetic-ephemeral-token"
        )
        self.assertEqual(
            request.full_url,
            "https://api.github.com/repos/VaftecPeru/Hipocratiq_Frontend/branches/develop"
        )

    def test_ci_missing_token_fails_without_network(self):
        with patch.dict(os.environ, {"GITHUB_ACTIONS": "true"}, clear=True), \
             patch.object(module, "urlopen") as http:
            with self.assertRaisesRegex(module.ReleaseSyncError, "token is required"):
                module.fetch_public_json(
                    "/repos/VaftecPeru/Hipocratiq_Frontend/branches/develop"
                )
            http.assert_not_called()


if __name__ == "__main__":
    unittest.main()
