#!/usr/bin/env python3
"""Offline fail-closed AWS IAM/OIDC and GitHub workflow contract validation.

Never calls GitHub or AWS; this does not verify actual environment rules or IAM.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

ACTIONS = {
    "ec2:DescribeInstances",
    "ec2:DescribeInstanceStatus",
    "ec2:DescribeVolumes",
    "ec2:DescribeSnapshots",
    "ssm:DescribeInstanceInformation",
}
SUB = "repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-prod-readonly"


class ContractError(Exception):
    pass


def require(value: bool) -> None:
    if not value:
        raise ContractError("OFFLINE_CONTRACT_INVALID")


def assess(root: Path) -> dict:
    role = json.loads((root / "infra/aws/prod-readonly-oidc-role.json").read_text())
    resources = role["Resources"]
    require(set(resources) == {"HipocratiqProdReadOnlyRole"})
    iam = resources["HipocratiqProdReadOnlyRole"]["Properties"]
    require(iam["RoleName"] == "HIPOCRATIQ-PROD-ReadOnly")
    statements = iam["AssumeRolePolicyDocument"]["Statement"]
    require(len(statements) == 1)
    trust = statements[0]
    require(trust["Effect"] == "Allow" and trust["Action"] == "sts:AssumeRoleWithWebIdentity")
    require(trust["Principal"] == {
        "Federated": {"Fn::Sub": "arn:${AWS::Partition}:iam::${AWS::AccountId}:oidc-provider/token.actions.githubusercontent.com"}
    })
    require(set(trust["Condition"]) == {"StringEquals"})
    require(trust["Condition"]["StringEquals"] == {
        "token.actions.githubusercontent.com:aud": "sts.amazonaws.com",
        "token.actions.githubusercontent.com:sub": SUB,
    })
    policies = iam["Policies"]
    require(len(policies) == 1)
    require(policies[0]["PolicyName"] == "HIPOCRATIQ-PROD-Metadata-Describe-Only")
    statements = policies[0]["PolicyDocument"]["Statement"]
    require(len(statements) == 1)
    statement = statements[0]
    require(statement["Effect"] == "Allow" and statement["Resource"] == "*")
    require(set(statement["Action"]) == ACTIONS)
    require(set(statement) == {"Sid", "Effect", "Action", "Resource"})

    workflow = (root / ".github/workflows/production-version-evidence-readonly.yml").read_text()
    require("\n  aws-prod-metadata:\n" in workflow)
    job = workflow.split("\n  aws-prod-metadata:\n", 1)[1]
    conditional = job.split("\n    needs:", 1)[0]
    for clause in (
        "github.event_name == 'push'",
        "github.ref == 'refs/heads/develop'",
        "vars.HIPOCRATIQ_PROD_METADATA_ENABLED == 'true'",
        "vars.HIPOCRATIQ_PROD_METADATA_ON_DEVELOP_PUSH == 'true'",
        "vars.HIPOCRATIQ_PROD_METADATA_ACK == 'READ_ONLY_PROD_METADATA'",
    ):
        require(clause in conditional)
    require("\n    environment: hipocratiq-prod-readonly\n" in job)
    require("\n      id-token: write\n" in job)
    require("scripts/uat_github_protected_develop_preflight.py" in job)
    require("aws-actions/configure-aws-credentials@v4" in job)
    require("HIPOCRATIQ_PROD_READONLY_ROLE_ARN" in job)
    require("scripts/prod_backup_metadata_readonly.py" in job)
    return {
        "status": "OFFLINE_CODE_CONTRACT_OK_ADMIN_VERIFICATION_REQUIRED",
        "aws_live_role_verified": False,
        "github_environment_reviewers_verified": False,
        "github_branch_rules_verified": False,
        "aws_metadata_call_made": False,
        "main_or_production_changed": False,
        "release_approved": False,
    }


def main(argv=None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", type=Path, default=Path("."))
    args = parser.parse_args(argv)
    try:
        result = assess(args.repository)
    except (OSError, ValueError, KeyError, TypeError, ContractError):
        print("OIDC_CONTRACT=NO_GO")
        return 2
    print("OIDC_CONTRACT=" + result["status"])
    print("AWS_LIVE_VERIFIED=false")
    print("RELEASE_APPROVED=false")
    return 0


if __name__ == "__main__":
    sys.exit(main())
