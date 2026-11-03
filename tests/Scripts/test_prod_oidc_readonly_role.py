"""Offline OIDC role contract for PRODUCTION metadata; NEVER makes an AWS call."""
import copy
import json
from pathlib import Path
import unittest

TEMPLATE = Path(__file__).resolve().parents[2] / "infra/aws/prod-readonly-oidc-role.json"
RESOURCE = "HipocratiqProdReadOnlyRole"
OIDC = "token.actions.githubusercontent.com"
SUBJECT = "repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-prod-readonly"
ACTIONS = frozenset({
    "ec2:DescribeInstances",
    "ec2:DescribeInstanceStatus",
    "ec2:DescribeVolumes",
    "ec2:DescribeSnapshots",
    "ssm:DescribeInstanceInformation",
})


def enforce_contract(document):
    resources = document.get("Resources", {})
    if set(resources) != {RESOURCE}:
        raise ValueError("Only one production metadata-only IAM role is allowed")
    resource = resources[RESOURCE]
    if resource.get("Type") != "AWS::IAM::Role":
        raise ValueError("No infrastructure resource is authorized")
    props = resource.get("Properties", {})
    if props.get("RoleName") != "HIPOCRATIQ-PROD-ReadOnly":
        raise ValueError("Unexpected production IAM role")
    if props.get("MaxSessionDuration") != 3600:
        raise ValueError("OIDC session must be limited to 3600 seconds")
    trust = props.get("AssumeRolePolicyDocument", {})
    statements = trust.get("Statement", [])
    if len(statements) != 1 or statements[0].get("Effect") != "Allow":
        raise ValueError("Exactly one allow trust statement required")
    s = statements[0]
    if s.get("Action") != "sts:AssumeRoleWithWebIdentity":
        raise ValueError("Must use temporary OIDC only")
    expected_principal = {"Federated": {"Fn::Sub":
        "arn:" + chr(36) + "{AWS::Partition}:iam::"
        + chr(36) + "{AWS::AccountId}:oidc-provider/" + OIDC
    }}
    if s.get("Principal") != expected_principal:
        raise ValueError("GitHub OIDC provider must be from the same account")
    if s.get("Condition") != {"StringEquals": {
        OIDC + ":aud": "sts.amazonaws.com",
        OIDC + ":sub": SUBJECT,
    }}:
        raise ValueError("Trust must pin exact repo and prod read-only Environment")
    policies = props.get("Policies", [])
    if len(policies) != 1:
        raise ValueError("Exactly one metadata policy permitted")
    policy = policies[0].get("PolicyDocument", {})
    statements = policy.get("Statement", [])
    if len(statements) != 1:
        raise ValueError("No extra permission statements allowed")
    permission = statements[0]
    given = permission.get("Action", [])
    if (permission.get("Effect") != "Allow"
            or permission.get("Resource") != "*"
            or not isinstance(given, list) or len(given) != len(ACTIONS)
            or set(given) != ACTIONS):
        raise ValueError("Only the five read-only AWS metadata APIs are permitted")
    if set(document.get("Outputs", {})) != {"ProdReadOnlyRoleArn"}:
        raise ValueError("No additional outputs permitted")
    return True


class ProdOidcReadOnlyContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.document = json.loads(TEMPLATE.read_text(encoding="utf-8"))

    def test_approved_metadata_role_is_exactly_scoped(self):
        self.assertTrue(enforce_contract(self.document))

    def test_rejects_untrusted_subjects_and_audiences(self):
        invalid_subjects = (
            "repo:VaftecPeru/Hipocratiq_backend:ref:refs/heads/main",
            "repo:VaftecPeru/Hipocratiq_backend:environment:*",
            "repo:VaftecPeru/Hipocratiq_Frontend:environment:hipocratiq-prod-readonly",
            "repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-uat-readonly",
            "repo:another/repo:environment:hipocratiq-prod-readonly",
        )
        for subject in invalid_subjects:
            doc = copy.deepcopy(self.document)
            doc["Resources"][RESOURCE]["Properties"]["AssumeRolePolicyDocument"][
                "Statement"][0]["Condition"]["StringEquals"][OIDC + ":sub"] = subject
            with self.subTest(subject=subject), self.assertRaises(ValueError):
                enforce_contract(doc)
        doc = copy.deepcopy(self.document)
        doc["Resources"][RESOURCE]["Properties"]["AssumeRolePolicyDocument"][
            "Statement"][0]["Condition"]["StringEquals"][OIDC + ":aud"] = "*"
        with self.assertRaises(ValueError):
            enforce_contract(doc)

    def test_rejects_deployment_writes_ssh_secrets_and_escalation(self):
        for action in (
            "ssm:SendCommand", "ssm:StartSession", "ec2:CreateSnapshot",
            "ec2:DeleteSnapshot", "ec2:RunInstances", "ec2:*",
            "iam:PassRole", "kms:Decrypt", "rds:DescribeDBInstances",
            "secretsmanager:GetSecretValue", "sts:AssumeRole", "*",
        ):
            doc = copy.deepcopy(self.document)
            doc["Resources"][RESOURCE]["Properties"]["Policies"][0][
                "PolicyDocument"]["Statement"][0]["Action"].append(action)
            with self.subTest(action=action), self.assertRaises(ValueError):
                enforce_contract(doc)

    def test_cannot_add_aws_infrastructure_or_other_roles(self):
        for key, kind in (
            ("ExtraEBS", "AWS::EC2::Volume"),
            ("ExtraRole", "AWS::IAM::Role"),
        ):
            doc = copy.deepcopy(self.document)
            doc["Resources"][key] = {"Type": kind}
            with self.subTest(resource=key), self.assertRaises(ValueError):
                enforce_contract(doc)

    def test_cannot_use_long_sessions_or_duplicate_privileges(self):
        doc = copy.deepcopy(self.document)
        doc["Resources"][RESOURCE]["Properties"]["MaxSessionDuration"] = 43200
        with self.assertRaises(ValueError):
            enforce_contract(doc)
        doc = copy.deepcopy(self.document)
        actions = doc["Resources"][RESOURCE]["Properties"]["Policies"][0][
            "PolicyDocument"]["Statement"][0]["Action"]
        actions.append(actions[0])
        with self.assertRaises(ValueError):
            enforce_contract(doc)


if __name__ == "__main__":
    unittest.main()
