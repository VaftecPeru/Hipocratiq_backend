"""Offline fail-closed Frontend SHA coherence tests (no HTTP requests)."""
import importlib.util
import pathlib
import unittest

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


if __name__ == "__main__":
    unittest.main()
