#!/usr/bin/env bash
# Fail-closed test suite: no AWS/SSM/network calls, fake IDs only.
set -Eeuo pipefail

GUARD="$(cd "$(dirname "$0")/../.." && pwd)/scripts/uat-aws-readonly-preflight.sh"
BASE=(
  GITHUB_REPOSITORY=VaftecPeru/Hipocratiq_backend
  GITHUB_EVENT_NAME=workflow_dispatch
  GITHUB_REF=refs/heads/develop
  UAT_READONLY_ENABLED=true
  UAT_ACKNOWLEDGEMENT=INVENTORY_ONLY_NO_PRODUCTION
  UAT_AWS_ACCOUNT_ID=111122223333
  UAT_AWS_REGION=us-east-2
  UAT_EC2_INSTANCE_ID=i-0123456789abcdef0
  UAT_OIDC_ROLE_ARN=arn:aws:iam::111122223333:role/HIPOCRATIQ-UAT-ReadOnly
)

if ! env -i PATH="$PATH" "${BASE[@]}" bash "$GUARD" >/dev/null; then
  echo "::error::Valid synthetic UAT preflight was unexpectedly denied" >&2
  exit 1
fi
echo "Valid synthetic preflight: PASS"

if ! env -i PATH="$PATH" "${BASE[@]}" GITHUB_EVENT_NAME=push UAT_PUSH_APPROVED=true bash "$GUARD" >/dev/null; then
  echo "::error::Explicitly approved synthetic develop push was unexpectedly denied" >&2
  exit 1
fi
echo "Explicitly approved synthetic push preflight: PASS"

deny() {
  local scenario="$1"
  shift
  if env -i PATH="$PATH" "${BASE[@]}" "$@" bash "$GUARD" >/dev/null 2>&1; then
    echo "::error::Unsafe case was accepted: $scenario" >&2
    exit 1
  fi
  echo "Unsafe case denied: $scenario"
}

deny "production branch" GITHUB_REF=refs/heads/main
deny "wrong repository" GITHUB_REPOSITORY=VaftecPeru/unrelated
deny "PR event" GITHUB_EVENT_NAME=pull_request
deny "unapproved develop push" GITHUB_EVENT_NAME=push UAT_PUSH_APPROVED=false
deny "disabled feature" UAT_READONLY_ENABLED=false
deny "missing acknowledgment" UAT_ACKNOWLEDGEMENT=
deny "unapproved AWS region" UAT_AWS_REGION=not-a-region
deny "bad account ID" UAT_AWS_ACCOUNT_ID=123
deny "missing instance" UAT_EC2_INSTANCE_ID=
deny "different AWS IAM account" UAT_OIDC_ROLE_ARN=arn:aws:iam::222233334444:role/Other
deny "malformed role" UAT_OIDC_ROLE_ARN=not-a-role
echo "UAT_AWS_PREFLIGHT_TESTS=PASS (synthetic, no cloud credentials)"
