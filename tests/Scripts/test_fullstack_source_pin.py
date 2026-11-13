"""Offline contract tests for the two-repository immutable CI source pin.

Never calls GitHub, AWS or services. Ensure maintainers cannot accidentally
test a Frontend SHA different from the expected release candidate.
"""
import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "uat-fullstack-integrated.yml"
SHA_RE = r"[0-9a-f]{40}"


class FullstackSourcePinTests(unittest.TestCase):
    def setUp(self):
        self.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_exact_same_full_sha_used_for_precheck_checkout_assertion_and_postcheck(self):
        checks = re.findall(
            rf"scripts/verify_frontend_release_sync\.py --expected ({SHA_RE})",
            self.workflow,
        )
        self.assertEqual(len(checks), 2, "Pre-check and post-check are mandatory.")
        refs = re.findall(rf"^\s+ref: ({SHA_RE})\s*$", self.workflow, re.M)
        self.assertEqual(len(refs), 1, "Frontend checkout must use an immutable SHA.")
        assertions = re.findall(
            rf'git -C frontend-src rev-parse HEAD\)" = "({SHA_RE})"', self.workflow
        )
        self.assertEqual(len(assertions), 1, "Assert the actually checked-out SHA.")
        self.assertEqual(len(set(checks + refs + assertions)), 1, "All 4 SHAs must match.")

    def test_precheck_before_checkout_and_postcheck_after_http_smoke(self):
        source = self.workflow
        before = source.index("Verify current Frontend develop and release PR #80")
        checkout = source.index("Checkout audited public Frontend at exact immutable SHA")
        smoke = source.index("node scripts/uat-fullstack-http-smoke.mjs")
        after = source.index("Re-check Frontend develop and release SHA after full-stack smoke")
        self.assertTrue(before < checkout < smoke < after)
        self.assertIn("No AWS", source.replace("NO AWS", "No AWS"))


if __name__ == "__main__":
    unittest.main()
