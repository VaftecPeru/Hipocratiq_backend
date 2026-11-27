import datetime as dt
import unittest
import json
from scripts.controlled_web_release_evidence import validate, REQUIRED, unique_object

BACK = "a" * 40
FRONT = "b" * 40
NOW = dt.datetime(2026, 10, 28, 13, 0, tzinfo=dt.timezone.utc)


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

    def test_duplicate_json_keys_are_rejected(self):
        with self.assertRaises(ValueError):
            json.loads('{"security_review":{"status":"pending","status":"approved"}}',
                       object_pairs_hook=unique_object)

    def test_independent_uat_flag_must_be_explicit_boolean_false(self):
        for value in (None, 0, "false", True):
            with self.subTest(value=value):
                x = valid()
                x["uat_independent_completed"] = value
                self.assertTrue(validate(x, BACK, FRONT, NOW))
        x = valid()
        del x["uat_independent_completed"]
        self.assertTrue(validate(x, BACK, FRONT, NOW))

    def test_noncanonical_or_future_approval_rejected(self):
        for stamp in ("2026-10-28T12:00:00+00:00",
                      "2026-10-28 12:00:00Z", "2026-10-28T13:00:01Z"):
            with self.subTest(stamp=stamp):
                x = valid()
                x["restore_drill"]["approved_at"] = stamp
                self.assertTrue(validate(x, BACK, FRONT, NOW))

    def test_reference_must_be_opaque_and_approver_printable(self):
        for reference in ("  TEST-REF-123", "https://example.test/secret", "ABC\nDEF"):
            with self.subTest(reference=reference):
                x = valid()
                x["security_review"]["evidence_id"] = reference
                self.assertTrue(validate(x, BACK, FRONT, NOW))
        x = valid()
        x["security_review"]["approved_by"] = "reviewer\nother"
        self.assertTrue(validate(x, BACK, FRONT, NOW))

    def test_nonobject_or_nonstring_sha_rejected(self):
        self.assertTrue(validate([], BACK, FRONT, NOW))
        self.assertTrue(validate(valid(), 123, FRONT, NOW))


if __name__ == "__main__":
    unittest.main()
