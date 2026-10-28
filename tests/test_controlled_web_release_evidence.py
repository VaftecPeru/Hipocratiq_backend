import datetime as dt
import unittest
from scripts.controlled_web_release_evidence import validate, REQUIRED

BACK = "a" * 40
FRONT = "b" * 40
NOW = dt.datetime(2026, 10, 28, tzinfo=dt.timezone.utc)


def valid():
    records = {name: {"status": "approved", "backend_sha": BACK, "frontend_sha": FRONT,
                      "approved_by": "test-reviewer", "evidence_id": "TEST-REF-123",
                      "approved_at": "2026-10-28T12:00:00Z"} for name in REQUIRED}
    return dict(release_type="controlled_web_existing_aws", backend_sha=BACK,
                frontend_sha=FRONT, uat_independent_completed=False, **records)


class ControlledWebTests(unittest.TestCase):
    def test_all_metadata_present_is_only_preliminary(self):
        self.assertEqual(validate(valid(), BACK, FRONT, NOW), [])
    def test_missing_clinical_fails_closed(self):
        x = valid()
        x.pop("clinical_web_signoff")
        self.assertTrue(validate(x, BACK, FRONT, NOW))
    def test_old_approval_fails_closed(self):
        x = valid()
        x["restore_drill"]["approved_at"] = "2025-01-01T00:00:00Z"
        self.assertTrue(validate(x, BACK, FRONT, NOW))
    def test_sha_mismatch_fails_closed(self):
        self.assertTrue(validate(valid(), "c"*40, FRONT, NOW))
    def test_unverified_record_fails_closed(self):
        x = valid()
        x["security_review"]["status"] = "pending"
        self.assertTrue(validate(x, BACK, FRONT, NOW))
    def test_fake_uat_assertion_fails_closed(self):
        x = valid()
        x["uat_independent_completed"] = True
        self.assertTrue(validate(x, BACK, FRONT, NOW))


if __name__ == "__main__":
    unittest.main()
