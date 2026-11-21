"""Strict contract for a deployment-capable IAM role; offline only."""
import copy
import json
from pathlib import Path
import unittest

FILE = Path(__file__).resolve().parents[2] / "infra/aws/prod-deploy-oidc-role.json"
NAME = "HipocratiqProdDeployRole"
AUD = "token.actions.githubusercontent.com:aud"
SUB = "token.actions.githubusercontent.com:sub"
EXPECTED_SUB = "repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-production"


def verify(doc):
    if set(doc.get("Resources", {})) != {NAME}:
        raise ValueError("Only one IAM role allowed")
    props = doc["Resources"][NAME]["Properties"]
    if (props.get("RoleName") != "HIPOCRATIQ-PROD-Deploy"
            or props.get("MaxSessionDuration") != 3600):
        raise ValueError("Wrong deploy role")
    trust = props.get("AssumeRolePolicyDocument", {}).get("Statement", [])
    if len(trust) != 1:
        raise ValueError("Unexpected OIDC trusts")
    t = trust[0]
    if (t.get("Effect") != "Allow"
            or t.get("Action") != "sts:AssumeRoleWithWebIdentity"
            or t.get("Condition") != {"StringEquals": {
                AUD: "sts.amazonaws.com", SUB: EXPECTED_SUB,
            }}):
        raise ValueError("Environment and audience mismatch")
    expected_provider = (
        "arn:" + chr(36) + "{AWS::Partition}:iam::" + chr(36)
        + "{AWS::AccountId}:oidc-provider/token.actions.githubusercontent.com"
    )
    if t.get("Principal") != {"Federated": {"Fn::Sub": expected_provider}}:
        raise ValueError("Wrong OIDC provider")
    if doc.get("Parameters", {}).get("ProductionInstanceId", {}).get(
        "AllowedPattern"
    ) != "^i-[0-9a-f]{8,17}$":
        raise ValueError("Instance ID pattern required")

    policies = props.get("Policies", [])
    if len(policies) != 1:
        raise ValueError("Only one IAM policy")
    stmts = policies[0].get("PolicyDocument", {}).get("Statement", [])
    if len(stmts) != 3:
        raise ValueError("Three exact policy statements required")
    action_sets = [set(s.get("Action", [])) for s in stmts]
    expected = [
        {"ec2:DescribeInstances", "ssm:DescribeInstanceInformation"},
        {"ssm:SendCommand"},
        {"ssm:GetCommandInvocation"},
    ]
    if action_sets != expected or any(s.get("Effect") != "Allow" for s in stmts):
        raise ValueError("Additional permissions forbidden")
    if stmts[0].get("Resource") != "*" or stmts[2].get("Resource") != "*":
        raise ValueError("Read APIs only allow listed actions")
    expected_resources = [
        {"Fn::Sub": "arn:" + chr(36) + "{AWS::Partition}:ssm:"
         + chr(36) + "{AWS::Region}::document/AWS-RunShellScript"},
        {"Fn::Sub": "arn:" + chr(36) + "{AWS::Partition}:ec2:"
         + chr(36) + "{AWS::Region}:" + chr(36)
         + "{AWS::AccountId}:instance/" + chr(36) + "{ProductionInstanceId}"},
    ]
    if stmts[1].get("Resource") != expected_resources:
        raise ValueError("SSM may only invoke one EC2 instance and AWS-RunShellScript")
    return True


class ProductionDeployRoleContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc = json.loads(FILE.read_text())

    def test_verified_manual_oidc_role(self):
        self.assertTrue(verify(self.doc))

    def test_rejects_other_branch_or_environment(self):
        for invalid in ("repo:VaftecPeru/Hipocratiq_backend:ref:refs/heads/main",
                        "repo:VaftecPeru/Hipocratiq_backend:environment:*",
                        "repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-prod-readonly"):
            doc = copy.deepcopy(self.doc)
            doc["Resources"][NAME]["Properties"]["AssumeRolePolicyDocument"][
                "Statement"][0]["Condition"]["StringEquals"][SUB] = invalid
            with self.subTest(invalid=invalid), self.assertRaises(ValueError):
                verify(doc)

    def test_rejects_privilege_expansion_or_other_hosts(self):
        for action in ("ssm:StartSession", "iam:PassRole", "ssm:*",
                       "ec2:TerminateInstances", "kms:Decrypt", "s3:GetObject",
                       "secretsmanager:GetSecretValue"):
            doc = copy.deepcopy(self.doc)
            doc["Resources"][NAME]["Properties"]["Policies"][0][
                "PolicyDocument"]["Statement"][1]["Action"].append(action)
            with self.subTest(action=action), self.assertRaises(ValueError):
                verify(doc)
        doc = copy.deepcopy(self.doc)
        doc["Resources"][NAME]["Properties"]["Policies"][0][
            "PolicyDocument"]["Statement"][1]["Resource"][1] = "*"
        with self.assertRaises(ValueError):
            verify(doc)

    def test_cannot_create_extra_resources_or_use_broad_trust(self):
        doc = copy.deepcopy(self.doc)
        doc["Resources"]["Unexpected"] = {"Type": "AWS::EC2::Instance"}
        with self.assertRaises(ValueError):
            verify(doc)
        doc = copy.deepcopy(self.doc)
        doc["Resources"][NAME]["Properties"]["AssumeRolePolicyDocument"][
            "Statement"][0]["Condition"]["StringEquals"][AUD] = "*"
        with self.assertRaises(ValueError):
            verify(doc)

if __name__ == "__main__":
    unittest.main()
