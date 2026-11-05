# HIPOCRATIQ Backend — Integration maintenance

Base branch: develop. Not a production release approval.

## Legacy pull requests to reconcile

- PR #26: clinical phase-4 feature tests, Composer audit, lockfile. Resolve obsolete CI and lockfile conflicts by testing on current develop, not replacing CI blindly.
- PR #27: clinic/doctor registration e-mail OTP, RFC checks. `RegistrationOtpSender` injection and `EmailRegistrationOtpSender` already exist on develop; inspect behavioral gaps and tests before porting changes.
- PR #28: Google OAuth flow. Validate state/nonce one-time use, membership checks and no Google secrets in client. Pair with Frontend #33.
- PR #29: Google SMTP diagnostic/timeout and tests. Protect credentials and run mocked tests before operational checks.

## Release checklist

- [ ] Compare six legacy feature sets against develop at test and endpoint level.
- [ ] Port only missing behavior and add regression tests.
- [ ] Run composer audit, PHPUnit, API integration and clinical sign-off tests.
- [ ] Obtain independent reviewer and security issue #102 acceptance.
- [ ] Obtain clinical issue #92 sign-off.
- [ ] Confirm release gate green before merging main.

AWS backup issue #113 is deferred, NOT a verified recoverable backup. Do not deploy destructive DB changes without recovery evidence.
