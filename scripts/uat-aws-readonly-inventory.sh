#!/usr/bin/env bash
# EC2/SSM control-plane queries ONLY. Never run SSM SendCommand or SSH.
set -Eeuo pipefail

fail() {
  echo "::error::UAT AWS inventory NO-GO: $1" >&2
  exit 1
}
bash "$(dirname "$0")/uat-aws-readonly-preflight.sh"
command -v aws >/dev/null 2>&1 || fail "AWS CLI is unavailable"
command -v jq >/dev/null 2>&1 || fail "jq is unavailable"

account="$(aws sts get-caller-identity --query Account --output text 2>/dev/null)" ||
  fail "Unable to verify STS identity"
[[ "$account" == "$UAT_AWS_ACCOUNT_ID" ]] ||
  fail "STS identity does not match the approved AWS account"
echo "IAM account allowlist: PASS"

instance="$(aws ec2 describe-instances --region "$UAT_AWS_REGION" \
  --instance-ids "$UAT_EC2_INSTANCE_ID" \
  --query 'Reservations[0].Instances[0].{State:State.Name,Tags:Tags}' \
  --output json 2>/dev/null)" ||
  fail "Unable to describe the approved EC2 instance"
state="$(jq -r '.State // "missing"' <<< "$instance")"
environment="$(jq -r '[.Tags[]? | select((.Key | ascii_downcase) == "environment") | .Value][0] // "missing"' <<< "$instance")"
project="$(jq -r '[.Tags[]? | select((.Key | ascii_downcase) == "project") | .Value][0] // "missing"' <<< "$instance")"
environment="$(printf '%s' "$environment" | tr '[:upper:]' '[:lower:]')"
project="$(printf '%s' "$project" | tr '[:upper:]' '[:lower:]')"

[[ "$environment" == "uat" || "$environment" == "qa" || "$environment" == "staging" ]] ||
  fail "EC2 Environment tag is missing or is not strictly non-production"
[[ "$project" == *hipocratiq* ]] ||
  fail "EC2 Project tag does not identify HIPOCRATIQ"
[[ "$state" == "running" ]] ||
  fail "Approved UAT EC2 instance is not running"
echo "EC2 tagged UAT/QA/staging and running: PASS"

health="$(aws ec2 describe-instance-status --region "$UAT_AWS_REGION" \
  --instance-ids "$UAT_EC2_INSTANCE_ID" --include-all-instances \
  --query 'InstanceStatuses[0].{Instance:InstanceStatus.Status,System:SystemStatus.Status}' \
  --output json 2>/dev/null)" ||
  fail "Unable to inspect EC2 status checks"
instance_status="$(jq -r '.Instance // "missing"' <<< "$health")"
system_status="$(jq -r '.System // "missing"' <<< "$health")"
[[ "$instance_status" == "ok" && "$system_status" == "ok" ]] ||
  fail "EC2 instance or system health checks are not OK"
echo "EC2 instance and system status checks: PASS"

ssm_status="$(aws ssm describe-instance-information --region "$UAT_AWS_REGION" \
  --filters "Key=InstanceIds,Values=$UAT_EC2_INSTANCE_ID" \
  --query 'InstanceInformationList[0].PingStatus' --output text 2>/dev/null)" ||
  fail "Unable to inspect AWS Systems Manager connectivity"
[[ "$ssm_status" == "Online" ]] ||
  fail "SSM connectivity is not Online (no remote commands were attempted)"
echo "AWS Systems Manager agent connectivity: PASS"
echo "UAT_AWS_INVENTORY=PASS (control plane only; NO SSM SendCommand; NO SSH)"
echo "UAT release is NOT approved. Docker, PostgreSQL isolation and HTTPS require separate UAT gates."
