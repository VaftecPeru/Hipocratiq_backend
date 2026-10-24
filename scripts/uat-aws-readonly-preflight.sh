#!/usr/bin/env bash
# Fail closed before requesting OIDC credentials or contacting AWS.
set -Eeuo pipefail

fail() {
  printf '::error::UAT AWS inventory blocked: %s\n' "$1" >&2
  exit 1
}

[[ "$GITHUB_REPOSITORY" == "VaftecPeru/Hipocratiq_backend" ]] ||
  fail "Unexpected repository"
[[ "$GITHUB_EVENT_NAME" == "workflow_dispatch" || "$GITHUB_EVENT_NAME" == "push" ]] ||
  fail "Only manual dispatch or an explicitly approved develop push is permitted"
if [[ "$GITHUB_EVENT_NAME" == "push" ]]; then
  [[ "${UAT_PUSH_APPROVED:-}" == "true" ]] ||
    fail "Read-only push inspection was not expressly approved"
fi
[[ "$GITHUB_REF" == "refs/heads/develop" ]] ||
  fail "Only the reviewed develop branch can access the AWS inventory gate"
[[ "${UAT_READONLY_ENABLED:-}" == "true" ]] ||
  fail "GitHub UAT inventory feature flag is not enabled"
[[ "${UAT_ACKNOWLEDGEMENT:-}" == "INVENTORY_ONLY_NO_PRODUCTION" ]] ||
  fail "Explicit read-only acknowledgement is required"

[[ "${UAT_AWS_ACCOUNT_ID:-}" =~ ^[0-9]{12}$ ]] ||
  fail "A dedicated 12-digit AWS account ID must be approved"
[[ "${UAT_AWS_REGION:-}" =~ ^[a-z]{2}(-gov)?-[a-z]+-[0-9]+$ ]] ||
  fail "Invalid approved AWS region"
[[ "${UAT_EC2_INSTANCE_ID:-}" =~ ^i-[a-f0-9]{8,17}$ ]] ||
  fail "An exact, approved EC2 instance ID is required"
[[ -n "${UAT_OIDC_ROLE_ARN:-}" ]] ||
  fail "An approved OIDC IAM role is required"
role_prefix="arn:aws:iam::$UAT_AWS_ACCOUNT_ID:role/"
[[ "$UAT_OIDC_ROLE_ARN" == "$role_prefix"* ]] ||
  fail "OIDC role does not belong to the approved account"
[[ "$UAT_OIDC_ROLE_ARN" =~ ^arn:aws:iam::[0-9]{12}:role/[A-Za-z0-9+=,.@_/-]+$ ]] ||
  fail "Invalid approved IAM role ARN"

echo "UAT_AWS_INVENTORY_PREFLIGHT=PASS (offline; no AWS API calls)"
