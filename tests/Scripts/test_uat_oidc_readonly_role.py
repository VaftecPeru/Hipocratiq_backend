"""Offline checks for the HIPOCRATIQ least-privilege GitHub OIDC IAM role.

Pure CloudFormation JSON validation; no AWS requests or credentials.
"""
import copy
import json
import unittest
from pathlib import Path

TEMPLATE = Path(__file__).resolve().parents[2] / "infra/aws/uat-readonly-oidc-role.json"
EXPECTED_ACTIONS = {
    "ec2:DescribeInstances",
    "ec2:DescribeInstanceStatus",
    "ec2:DescribeVolumes",
    "ec2:DescribeSecurityGroupRules",
    "ssm:DescribeInstanceInformation",
}
EXPECTED_SUBJECT = "repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-uat-readonly"
OIDC_ISSUER = "token.actions.githubusercontent.com"


def inspect_template(doc):
    resources = doc["Resources"]
    if set(resources) != {"HipocratiqUatReadOnlyRole"}:
        raise ValueError("CloudFormation must only create the designated IAM role")
    role = resources["HipocratiqUatReadOnlyRole"]
    if role["Type"] != "AWS::IAM::Role":
        raise ValueError("Unexpected resource type")
    props = role["Properties"]
    if props.get("RoleName") != "HIPOCRATIQ-UAT-ReadOnly":
        raise ValueError("Unexpected role name")
    if props.get("MaxSessionDuration", 3601) > 3600:
        raise ValueError("Session must not exceed 3600 seconds")

    trust = props["AssumeRolePolicyDocument"]
    if len(trust.get("Statement", [])) != 1:
        raise ValueError("Only one trust statement allowed")
    statement = trust["Statement"][0]
    if statement.get("Effect") != "Allow" or statement.get("Action") != "sts:AssumeRoleWithWebIdentity":
        raise ValueError("Only OIDC role assumption is allowed")
    expected_principal = {
        "Fn::Sub": (
            "arn:" + chr(36) + "{AWS::Partition}:iam::" + chr(36)
            + "{AWS::AccountId}:oidc-provider/" + OIDC_ISSUER
        )
    }
    if statement.get("Principal") != {"Federated": expected_principal}:
        raise ValueError("Trust must use same-account GitHub OIDC provider")
    if statement.get("Condition") != {
        "StringEquals": {
            OIDC_ISSUER + ":aud": "sts.amazonaws.com",
            OIDC_ISSUER + ":sub": EXPECTED_SUBJECT,
        }
    }:
        raise ValueError("Trust must pin repository and UAT Environment")

    policies = props.get("Policies", [])
    if len(policies) != 1:
        raise ValueError("Only one read-only policy permitted")
    policy = policies[0]["PolicyDocument"]
    statements = policy.get("Statement", [])
    if len(statements) != 1 or statements[0].get("Effect") != "Allow":
        raise ValueError("Unexpected IAM statements")
    grant = statements[0]
    if grant.get("Resource") != "*" or set(grant.get("Action", [])) != EXPECTED_ACTIONS:
        raise ValueError("AWS API surface must be exactly five Describe actions")
    if len(grant["Action"]) != len(EXPECTED_ACTIONS):
        raise ValueError("No duplicate or additional IAM permissions")
    if set(doc.get("Outputs", {})) != {"UatReadOnlyRoleArn"}:
        raise ValueError("No extra CloudFormation outputs")
    return True


class OidcRoleTemplateTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with TEMPLATE.open(encoding="utf-8") as handle:
            cls.document = json.load(handle)

    def test_exact_role_principal_policy_and_environment(self):
        self.assertTrue(inspect_template(self.document))

    def test_production_branch_or_untrusted_repository_is_blocked(self):
        subjects = [
            "repo:VaftecPeru/Hipocratiq_backend:ref:refs/heads/main",
            "repo:VaftecPeru/Hipocratiq_backend:ref:refs/heads/develop",
            "repo:VaftecPeru/Hipocratiq_Frontend:environment:hipocratiq-uat-readonly",
            "repo:other/repo:environment:hipocratiq-uat-readonly",
            "repo:VaftecPeru/Hipocratiq_backend:environment:*",
        ]
        for subject in subjects:
            with self.subTest(subject=subject):
                doc = copy.deepcopy(self.document)
                doc["Resources"]["HipocratiqUatReadOnlyRole"]["Properties"][
                    "AssumeRolePolicyDocument"
                ]["Statement"][0]["Condition"]["StringEquals"][OIDC_ISSUER + ":sub"] = subject
                with self.assertRaises(ValueError):
                    inspect_template(doc)

    def test_writes_wildcards_escalation_and_remote_shell_are_blocked(self):
        actions = [
            "ec2:*", "ec2:StartInstances", "ssm:SendCommand", "ssm:StartSession",
            "iam:PassRole", "iam:*", "secretsmanager:GetSecretValue",
            "rds:DescribeDBInstances", "sns:Publish", "sts:AssumeRole",
        ]
        for action in actions:
            with self.subTest(action=action):
                doc = copy.deepcopy(self.document)
                doc["Resources"]["HipocratiqUatReadOnlyRole"]["Properties"][
                    "Policies"
                ][0]["PolicyDocument"]["Statement"][0]["Action"].append(action)
                with self.assertRaises(ValueError):
                    inspect_template(doc)

    def test_cannot_add_production_resource_to_template(self):
        doc = copy.deepcopy(self.document)
        doc["Resources"]["UnexpectedResource"] = {
            "Type": "AWS::EC2::Instance", "Properties": {}
        }
        with self.assertRaises(ValueError):
            inspect_template(doc)

    def test_exact_audience_and_environment_are_required(self):
        for key, value in [
            (OIDC_ISSUER + ":aud", "other-audience"),
            (OIDC_ISSUER + ":sub", "*"),
        ]:
            doc = copy.deepcopy(self.document)
            doc["Resources"]["HipocratiqUatReadOnlyRole"]["Properties"][
                "AssumeRolePolicyDocument"
            ]["Statement"][0]["Condition"]["StringEquals"][key] = value
            with self.assertRaises(ValueError):
                inspect_template(doc)


if __name__ == "__main__":
    unittest.main()
