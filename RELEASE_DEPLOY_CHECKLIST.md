# Release Deployment Checklist

## Scope
- Baseline: Step 10 hardening completed and smoke-tested.
- Includes: OAuth state stabilization and environment-aware OAuth redirect resolution.

## Pre-Tag Validation
1. Ensure working tree is clean.
2. Run syntax checks:
   - `php -l public/index.php`
   - `php -l app/config.php`
3. Run health check:
   - `php scripts/ops_health_check.php`
4. Run smoke test:
   - `bash scripts/acceptance_smoke.sh http://localhost/TKIF_scholarship`

## Secrets and Environment
1. Rotate production secrets if needed:
   - SMTP
   - OAuth client secrets
   - internal notification secrets/tokens
2. Verify production `.env`:
   - `APP_ENV=production`
   - DB credentials
   - SMTP credentials
   - `GOOGLE_REDIRECT_URI_PROD`
   - `MICROSOFT_REDIRECT_URI_PROD`
3. Confirm provider consoles have exact redirect URI entries.

## Release Tag
1. Create annotated tag:
   - `git tag -a v2026.06.04-step10-stable -m "Step 10 stable + OAuth hardening"`
2. Push code and tags:
   - `git push origin main --tags`

## Deployment
1. Upload files according to `DEPLOY_MANUAL.md`.
2. Apply required migrations.
3. Configure cron jobs:
   - notification worker
   - cleanup maintenance
   - health check

## Post-Deploy Verification
1. Role login checks: Student/Admin/Manager/IT.
2. Registration + email verification path.
3. Google and Microsoft sign-in success.
4. Notification queue processes to `sent`.
5. Inbox records show valid signatures.
6. No warnings from `php scripts/ops_health_check.php`.

## Rollback
1. Re-deploy previous release tag.
2. Restore previous `.env` values.
3. Re-run smoke test and health check.
