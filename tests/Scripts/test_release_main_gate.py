"""Synthetic release gate tests only. Never reach GitHub or AWS."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2] / "scripts"
SPEC = importlib.util.spec_from_file_location("release_main_gate", ROOT / "release_main_gate.py")
gate = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(gate)
BE_SHA = "a" * 40
FE_SHA = "b" * 40

def fixture():
    env = {
        "GITHUB_REPOSITORY": gate.BACKEND,
        "GITHUB_REF": "refs/heads/main",
        "GITHUB_EVENT_NAME": "workflow_dispatch",
        "GITHUB_SHA": BE_SHA,
        "RELEASE_ACK": "APPROVED_HIPOCRATIQ_CONTROLLED_PRODUCTION",
        "APPROVED_FRONTEND_SHA": FE_SHA,
        "RELEASE_DEPLOY_ENABLED": "true",
        "RELEASE_CHANGE_ID": "HIPOCRATIQ-CHANGE-123",
        "UAT_CLINICAL_EVIDENCE": "UAT-36-approved-2026",
        "SECURITY_EVIDENCE": "Security-102-verified",
        "UAT_RESTORE_EVIDENCE": "Restore-113-approved",
        "BACKUP_AT_REST_EVIDENCE": "BackupEncryptionEvidence",
    }
    backend = {"name": "main", "protected": True, "commit": {"sha": BE_SHA}}
    frontend = {"name": "main", "protected": True, "commit": {"sha": FE_SHA}}
    issues = {n: {"number": n, "state": "closed"} for n in gate.REQUIRED_ISSUES}
    return env, backend, frontend, issues

class MainReleaseGateTests(unittest.TestCase):
    def test_explicit_release_needs_all_independent_evidence(self):
        env, backend, frontend, issues = fixture()
        result = gate.assess(env, backend, frontend, issues, "deploy")
        self.assertEqual(result["status"], "GO_PRELIMINARY_REQUIRES_HUMAN_AWS_REVIEW")
        self.assertFalse(result["production_changed"])
        self.assertFalse(result["release_authorized_by_this_job"])
        self.assertFalse(result["live_aws_runtime_verified"])

    def test_open_p0_issue_blocks_release(self):
        for issue in gate.REQUIRED_ISSUES:
            env, be, fe, issues = fixture()
            issues[issue]["state"] = "open"
            result = gate.assess(env, be, fe, issues, "deploy")
            self.assertIn(f"P0_{issue}_NOT_CLOSED", result["blockers"])

    def test_main_push_never_qualifies_as_deploy(self):
        env, be, fe, issues = fixture()
        env["GITHUB_EVENT_NAME"] = "push"
        self.assertIn("DEPLOY_MUST_BE_MANUAL",
                      gate.assess(env, be, fe, issues, "deploy")["blockers"])
        self.assertFalse(gate.assess(env, be, fe, issues, "readiness")["production_changed"])

    def test_block_unapproved_frontend_or_backend_sha_and_unprotected_main(self):
        env, be, fe, issues = fixture()
        env["APPROVED_FRONTEND_SHA"] = "c" * 40
        env["GITHUB_SHA"] = "d" * 40
        fe["protected"] = False
        res = gate.assess(env, be, fe, issues, "deploy")
        for code in ("FRONTEND_SHA_NOT_APPROVED", "BACKEND_MAIN_SHA_MISMATCH",
                     "FRONTEND_MAIN_NOT_PROTECTED"):
            self.assertIn(code, res["blockers"])

    def test_block_missing_approval_or_unsupported_payload(self):
        env, be, fe, issues = fixture()
        del env["UAT_RESTORE_EVIDENCE"]
        env["RELEASE_DEPLOY_ENABLED"] = "false"
        env["RELEASE_ACK"] = ""
        res = gate.assess(env, be, fe, issues, "deploy")
        self.assertIn("UAT_RESTORE_EVIDENCE_MISSING_OR_INVALID", res["blockers"])
        self.assertIn("PRODUCTION_DEPLOY_NOT_ENABLED", res["blockers"])
        self.assertIn("PRODUCTION_ACK_MISSING", res["blockers"])

    def test_no_arbitrary_rest_paths_or_no_token(self):
        for path in (
            "/repos/other/other/issues/92",
            f"/repos/{gate.BACKEND}/actions/workflows",
            f"/repos/{gate.BACKEND}/issues/1",
        ):
            with self.assertRaises(gate.ReleaseGateError):
                gate.read_github(path, "fake")
        with self.assertRaises(gate.ReleaseGateError):
            gate.read_github(f"/repos/{gate.BACKEND}/branches/main", "")

if __name__ == "__main__":
    unittest.main()
