"""No-network regressions for admin-only GitHub branch/environment bootstrap."""
import copy
import importlib.util
import os
from pathlib import Path
from types import SimpleNamespace
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/configure_github_release_governance.py"
spec = importlib.util.spec_from_file_location("configure_release_governance", SCRIPT)
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


class FakeGithub:
    def __init__(self):
        self.protected = {}
        self.environment = None
        self.policies = []
        self.writes = []
        self.preexisting_rules = []
        self.actor_id = 99

    def request(self, method, path, payload=None, *, allow_missing=False):
        if method in ("PUT", "POST"):
            self.writes.append((method, path, copy.deepcopy(payload)))
        if path == "/user":
            return {"id": self.actor_id}
        if path.endswith("/rulesets"):
            return self.preexisting_rules
        if path in ("/repos/" + guard.BACKEND, "/repos/" + guard.FRONTEND):
            return {"full_name": path.removeprefix("/repos/"),
                    "permissions": {"admin": True}}
        if path.endswith("/deployment-branch-policies?per_page=100"):
            return {"branch_policies": self.policies}
        if path.endswith("/deployment-branch-policies") and method == "POST":
            self.policies.append({"name": payload["name"], "type": payload["type"]})
            return {"name": payload["name"]}
        if path == f"/repos/{guard.BACKEND}/environments/{guard.ENVIRONMENT}":
            if method == "PUT":
                self.environment = {
                    "name": guard.ENVIRONMENT,
                    "deployment_branch_policy": payload["deployment_branch_policy"],
                    "prevent_self_review": payload["prevent_self_review"],
                    "protection_rules": [{"type": "required_reviewers",
                                          "prevent_self_review": True,
                                          "reviewers": [{"reviewer": {"id": payload["reviewers"][0]["id"]}}]}]
                }
            return self.environment
        for repo, branch in guard.REQUIRED:
            meta, protect = guard.branch_paths(repo, branch)
            if path == meta:
                return {"protected": (repo, branch) in self.protected}
            if path == protect:
                if method == "PUT":
                    self.protected[(repo, branch)] = copy.deepcopy(payload)
                raw = self.protected.get((repo, branch))
                if not raw:
                    return None
                stored = copy.deepcopy(raw)
                for name in ("enforce_admins", "required_conversation_resolution",
                             "allow_force_pushes", "allow_deletions"):
                    stored[name] = {"enabled": raw[name]} if type(raw[name]) is bool else raw[name]
                return stored
        raise AssertionError(f"Unexpected API call: {method} {path}")


class GovernanceBootstrapTest(unittest.TestCase):
    def test_exact_repo_and_branch_inventory_and_strict_checks(self):
        self.assertEqual(set(guard.REQUIRED), {
            (guard.BACKEND, "main"), (guard.BACKEND, "develop"),
            (guard.FRONTEND, "main"), (guard.FRONTEND, "develop")})
        for repo, branch in guard.REQUIRED:
            with self.subTest(repo=repo, branch=branch):
                payload = guard.protection_payload(repo, branch)
                self.assertTrue(payload["required_status_checks"]["strict"])
                self.assertEqual(payload["required_status_checks"]["contexts"],
                                 list(guard.REQUIRED[(repo, branch)]))
                self.assertTrue(payload["enforce_admins"])
                self.assertFalse(payload["allow_force_pushes"])
                self.assertFalse(payload["allow_deletions"])
                self.assertFalse(payload["required_linear_history"])
                self.assertTrue(payload["required_pull_request_reviews"]["dismiss_stale_reviews"])
                self.assertTrue(payload["required_pull_request_reviews"]["require_last_push_approval"])
                self.assertEqual(payload["required_pull_request_reviews"]["required_approving_review_count"], 1)

    def test_plan_defaults_to_offline_and_produces_no_network_access(self):
        fake = FakeGithub()
        rc = guard.main([], environ={}, api=fake)
        self.assertEqual(rc, 0)
        self.assertEqual(fake.writes, [])

    def test_apply_requires_explicit_ack_token_reviewer_and_trusted_workstation(self):
        def args(**changes):
            values = dict(apply=True, acknowledgement=guard.ACK, reviewer_id=101)
            values.update(changes)
            return SimpleNamespace(**values)
        good = {"HIPOCRATIQ_GH_ADMIN_TOKEN": "never-printed"}
        guard.validate_execution(args(), good)
        for a, env in [
            (args(acknowledgement="yes"), good),
            (args(reviewer_id=None), good),
            (args(), {}),
            (args(), good | {"GITHUB_ACTIONS": "true"}),
            (args(), good | {"CI": "true"}),
        ]:
            with self.subTest(args=a, env=tuple(env)):
                with self.assertRaises(guard.GuardError):
                    guard.validate_execution(a, env)

    def test_valid_apply_and_readback_configures_only_four_branches_and_uat(self):
        fake = FakeGithub()
        guard.apply_governance(fake, reviewer_id=101)
        self.assertEqual(len(fake.writes), 6)
        self.assertEqual(sum(path.endswith("/protection") for _, path, _ in fake.writes), 4)
        self.assertEqual(len(fake.policies), 1)
        self.assertEqual(fake.policies[0], {"name": "develop", "type": "branch"})
        self.assertTrue(guard.read_governance(fake, reviewer_id=101))
        self.assertTrue(all(path.startswith("/repos/VaftecPeru/") for _, path, _ in fake.writes))
        self.assertFalse(any("/actions/" in path or "/contents/" in path for _, path, _ in fake.writes))

    def test_existing_branch_or_environment_fails_before_any_write(self):
        for scenario in ("branch", "environment", "ruleset", "self-review"):
            fake = FakeGithub()
            if scenario == "branch":
                fake.protected[(guard.BACKEND, "main")] = guard.protection_payload(guard.BACKEND, "main")
            if scenario == "environment":
                fake.environment = {"name": guard.ENVIRONMENT}
            if scenario == "ruleset":
                fake.preexisting_rules = [{"id": 1, "enforcement": "active"}]
            if scenario == "self-review":
                fake.actor_id = 101
            with self.subTest(scenario=scenario):
                with self.assertRaises(guard.GuardError):
                    guard.apply_governance(fake, reviewer_id=101)
                self.assertEqual(fake.writes, [])

    def test_reader_rejects_unapproved_api_path_and_method_before_network(self):
        client = guard.GithubAPI("redacted", fetch=lambda *_args, **_kw: self.fail("network reached"))
        for method, path in [
            ("POST", "/repos/other-owner/other-repo/branches/main/protection"),
            ("DELETE", "/repos/" + guard.BACKEND),
            ("GET", "/orgs/VaftecPeru/members"),
        ]:
            with self.subTest(method=method, path=path):
                with self.assertRaises(guard.GuardError):
                    client.request(method, path)

    def test_environment_requires_independent_reviewer_and_exact_develop_policy(self):
        payload = guard.environment_payload(101)
        self.assertTrue(payload["prevent_self_review"])
        self.assertEqual(payload["reviewers"], [{"type": "User", "id": 101}])
        self.assertEqual(payload["deployment_branch_policy"],
                         {"protected_branches": False, "custom_branch_policies": True})
        with self.assertRaises(guard.GuardError):
            guard.environment_payload(0)
        fake = FakeGithub()
        guard.apply_governance(fake, reviewer_id=101)
        fake.policies.append({"name": "main", "type": "branch"})
        with self.assertRaises(guard.GuardError):
            guard.read_governance(fake)


if __name__ == "__main__":
    unittest.main()
