import unittest
from scripts.controlled_web_live_gate import check, REPO
SHA = "a" * 40
def event(sha=SHA):
    return {"pull_request": {"draft": False, "base": {"ref": "main", "repo": {"full_name": REPO}}, "head": {"ref": "release/synthetic-candidate", "sha": sha, "repo": {"full_name": REPO}}}}
class TestGate(unittest.TestCase):
    def test_missing_evidence_fails(self):
        self.assertIn("Required evidence issue #92 is not closed.", check(event(), SHA, {92:"open",102:"closed",113:"closed"}))
    def test_wrong_sha_fails(self):
        self.assertTrue(any("SHA" in b for b in check(event("b"*40), SHA, {92:"closed",102:"closed",113:"closed"})))
    def test_draft_is_rejected(self):
        data = event()
        data["pull_request"]["draft"] = True
        self.assertTrue(any("ready for review" in b for b in check(data, SHA, {92:"closed",102:"closed",113:"closed"})))
    def test_non_hex_sha_is_rejected(self):
        bad = "z" * 40
        self.assertTrue(any("SHA" in b for b in check(event(bad), bad, {92:"closed",102:"closed",113:"closed"})))
    def test_non_release_branch_is_rejected(self):
        data = event()
        data["pull_request"]["head"]["ref"] = "feature/unsafe"
        self.assertTrue(any("release branch" in b for b in check(data, SHA, {92:"closed",102:"closed",113:"closed"})))
    def test_target_repo_mismatch_is_rejected(self):
        data = event()
        data["pull_request"]["base"]["repo"]["full_name"] = "elsewhere/example"
        self.assertTrue(any("target repository" in b for b in check(data, SHA, {92:"closed",102:"closed",113:"closed"})))
    def test_all_issues_closed_still_not_production_go(self):
        self.assertTrue(any("manual verification" in b for b in check(event(), SHA, {92:"closed",102:"closed",113:"closed"})))
if __name__ == "__main__":
    unittest.main()
