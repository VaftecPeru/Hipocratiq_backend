import importlib.util
import pathlib
import unittest

MODULE = pathlib.Path(__file__).resolve().parents[2] / "scripts" / "check_public_env_template.py"
spec = importlib.util.spec_from_file_location("check_public_env_template", MODULE)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class PublicEnvTemplateTests(unittest.TestCase):
    def test_empty_credentials_are_accepted(self):
        ok, problems = module.verify_template(
            "APP_NAME=Hipocratiq\nDB_PASSWORD=\nREDIS_PASSWORD=\nOTP_HASH_KEY=\n"
        )
        self.assertTrue(ok)
        self.assertEqual(problems, [])

    def test_nonempty_credentials_are_rejected_without_exposing_values(self):
        sample = "DB_PASSWORD=some-private-value\nREDIS_PASSWORD=another-private-value\n"
        ok, problems = module.verify_template(sample)
        self.assertFalse(ok)
        self.assertIn("DB_PASSWORD:NONEMPTY_VALUE", problems)
        self.assertIn("REDIS_PASSWORD:NONEMPTY_VALUE", problems)
        self.assertNotIn("some-private-value", repr(problems))
        self.assertNotIn("another-private-value", repr(problems))

    def test_duplicate_sensitive_key_is_rejected(self):
        ok, problems = module.verify_template(
            "DB_PASSWORD=\nDB_PASSWORD=\nREDIS_PASSWORD=\n"
        )
        self.assertFalse(ok)
        self.assertIn("DB_PASSWORD:DUPLICATE_KEY", problems)

    def test_missing_required_key_is_rejected(self):
        ok, problems = module.verify_template("DB_PASSWORD=\n")
        self.assertFalse(ok)
        self.assertIn("REDIS_PASSWORD:MISSING_KEY", problems)

    def test_non_sensitive_runtime_flags_do_not_raise_false_positives(self):
        ok, problems = module.verify_template(
            "DB_PASSWORD=\nREDIS_PASSWORD=\nEPIDEMIOLOGICAL_AI_MAX_OUTPUT_TOKENS=2000\n"
        )
        self.assertTrue(ok)
        self.assertEqual(problems, [])


if __name__ == "__main__":
    unittest.main()
