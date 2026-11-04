"""Synthetic-only guardrail tests for the 36-position clinical UAT metadata ledger."""

import copy
import json
from pathlib import Path
import sys
import unittest


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))
from clinical_92_ledger import LedgerError, validate  # noqa: E402


class Clinical92LedgerContractTest(unittest.TestCase):
    def setUp(self):
        self.ledger = json.loads(
            (ROOT / "docs/releases/clinical-92-uat36-ledger.json").read_text(encoding="utf-8")
        )

    def test_canonical_ledger_is_no_go_and_all_36_are_unexecuted(self):
        report = validate(self.ledger)
        self.assertEqual(report["matrix_positions"], 36)
        self.assertEqual(report["not_executed"], 36)
        self.assertEqual(report["self_reported_pass"], 0)
        self.assertEqual(report["release_decision"], "NO_GO")
        self.assertFalse(report["clinical_signoff_verified"])

    def test_duplicates_or_missing_scenarios_fail_closed(self):
        altered = copy.deepcopy(self.ledger)
        altered["rows"][35]["position"] = 1
        with self.assertRaises(LedgerError):
            validate(altered)
        altered["rows"].pop()
        with self.assertRaises(LedgerError):
            validate(altered)

    def test_no_fabricated_metadata_is_allowed_on_unexecuted_cases(self):
        altered = copy.deepcopy(self.ledger)
        altered["rows"][0]["evidence_ref"] = "EV-FAKE01"
        with self.assertRaises(LedgerError):
            validate(altered)

    def test_pass_without_real_metadata_shape_fails_closed(self):
        altered = copy.deepcopy(self.ledger)
        altered["rows"][0]["status"] = "PASS"
        with self.assertRaises(LedgerError):
            validate(altered)

    def test_arbitrary_links_or_patient_values_cannot_be_evidence_references(self):
        altered = copy.deepcopy(self.ledger)
        altered["rows"][0] = self.synthetic_pass(1)
        altered["rows"][0]["evidence_ref"] = "https://example.test/clinical-patient"
        with self.assertRaises(LedgerError):
            validate(altered)

    def test_mixed_candidate_shas_fail_closed(self):
        altered = copy.deepcopy(self.ledger)
        altered["rows"][0] = self.synthetic_pass(1)
        altered["rows"][1] = self.synthetic_pass(2)
        altered["rows"][1]["frontend_sha"] = "c" * 40
        with self.assertRaises(LedgerError):
            validate(altered)

    def test_all_self_reported_pass_does_not_grant_clinical_signoff(self):
        altered = copy.deepcopy(self.ledger)
        altered["rows"] = [self.synthetic_pass(n) for n in range(1, 37)]
        report = validate(altered)
        self.assertEqual(report["self_reported_pass"], 36)
        self.assertFalse(report["clinical_signoff_verified"])
        self.assertEqual(report["release_decision"], "NO_GO")

    @staticmethod
    def synthetic_pass(position):
        return {
            "position": position,
            "status": "PASS",
            "backend_sha": "a" * 40,
            "frontend_sha": "b" * 40,
            "executed_at_utc": "2026-11-04T20:00:00Z",
            "executor_ref": "QA-SYNTHETIC001",
            "evidence_ref": "EV-SYNTHETIC001",
        }


if __name__ == "__main__":
    unittest.main()
