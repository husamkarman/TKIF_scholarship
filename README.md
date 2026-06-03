# TKIF Scholarship MVP Foundation

This repository is a local starter for:
- PHP + MySQL role-based scholarship portal
- Internal notification queue + worker automation
- Manual cPanel deployment path

## Included
- Student/Admin/Manager/IT login routing
- Guest registration flow (configurable)
- Basic scholarship apply flow
- Basic application approve/reject flow
- Internal queued notifications on submit and decision
- Multi-tenant schema baseline (`tenant_id` model)

## Quick Start (Local)
1. Copy env file:
   - `cp .env.example .env`
2. Install PHP dependencies:
   - `composer install`
3. Create MySQL DB and import:
   - `mysql -u root -p < sql/schema.sql`
   - `mysql -u root -p < sql/seed.sql`
4. Run PHP local server:
   - `php -S localhost:8080 -t public`
5. Open:
   - `http://localhost:8080`
6. Demo users (password: `Password123!`):
   - `student@tkif.local`
   - `admin@tkif.local`
   - `manager@tkif.local`
   - `it@tkif.local`

## cPanel Routing
1. Best setup: point the domain document root to `public/`.
2. Fallback setup: if cPanel only gives you `public_html`, upload the full project there and keep the root-level `.htaccess` so requests are forwarded into `public/`.

## SMTP Setup (Secure)
1. Set all SMTP values in `.env`.
2. Keep `SMTP_PASSWORD` only in `.env` (never commit).
3. Enable delivery by setting `SMTP_ENABLED=true`.
4. Current app events that trigger email:
   - Student application submitted
   - Admin/Manager application decision (approved/rejected)

## Guest Registration
1. Registration page is available at `/?page=register` when enabled.
2. Configure `.env` values:
   - `REGISTRATION_ENABLED=true|false`
   - `REGISTRATION_DEFAULT_ROLE=student`
   - `REGISTRATION_DEFAULT_TENANT_CODE=<optional-tenant-code>`
3. If no `REGISTRATION_DEFAULT_TENANT_CODE` is set, the first active tenant is used.

## Security Rotation (Step 1)
1. Rotate exposed secrets at provider side first (SMTP, Microsoft, Google, DB, internal worker token).
2. Update `.env` with new values.
3. Run env security check:
   - `bash scripts/check_env_security.sh .env`
4. Follow full runbook:
   - `SECURITY_ROTATION.md`

### Internal Notifications (No External Service)
Use your own app endpoint and internal worker instead of third-party webhook services.

1. Apply notification inbox migration:
   - `mysql -u root -p < sql/migrations/20260603_add_notification_inbox.sql`
2. Set `.env` values:
   - `INTERNAL_NOTIFICATION_ENDPOINT=http://localhost:8080/?page=notification_inbox_receive`
   - `INTERNAL_NOTIFICATION_SECRET=<strong-random-secret>`
   - `INTERNAL_NOTIFICATION_HMAC_TOLERANCE_SECONDS=300`
   - `INTERNAL_NOTIFICATION_WORKER_TOKEN=<strong-random-token>`
3. Apply jobs migration:
   - `mysql -u root -p < sql/migrations/20260603_add_notification_jobs.sql`
4. Process queue manually (or from cron):
   - `curl -X POST "http://localhost:8080/?page=notification_worker_run" -H "X-Worker-Token: <token>"`
5. Production cron (example every minute):
   - `* * * * * /usr/bin/curl -sS -X POST "https://your-domain/?page=notification_worker_run" -H "X-Worker-Token: <token>" >/dev/null 2>&1`

Security behavior:
- Internal worker sends signed headers: `X-TKIF-Timestamp` and `X-TKIF-Signature`.
- App verifies `sha256=HMAC(secret, timestamp + '.' + rawBody)` and rejects invalid/expired signatures.

Admin/IT can monitor received notification events from Dashboard -> Internal Notification Inbox.

Queue workflow status:
- Step 8 implemented internally with submission/decision/blacklist queue events and signed dispatch to inbox.

## Next Build Step
- Add Google auth integration.
- Add WhatsApp/SMS providers when keys are available.

## Profile Query Performance
Run this migration to optimize profile application history filters/export:
- `mysql -u root -p < sql/migrations/20260603_add_application_filter_indexes.sql`

## Acceptance Smoke Test (One Command)
Run end-to-end smoke validations (login, apply, profile filters, CSV export, admin user-type update):
- `bash scripts/acceptance_smoke.sh http://localhost:8080`

## CI Validation
GitHub Actions workflow runs the same smoke test automatically on push and pull requests:
- `.github/workflows/acceptance-smoke.yml`

## Branch Protection (Recommended)
Require CI before merge using this guide:
- `BRANCH_PROTECTION.md`

## Auth (Step 5)
1. Email OTP login is enabled (via SMTP).
2. Microsoft OAuth login is enabled when these `.env` values exist:
   - `MICROSOFT_CLIENT_ID`
   - `MICROSOFT_TENANT_ID`
   - `MICROSOFT_REDIRECT_URI`
   - `MICROSOFT_CLIENT_SECRET`
3. First-time Microsoft users can be auto-provisioned to local users when allowed by config:
   - `MICROSOFT_AUTO_PROVISION=true`
   - `MICROSOFT_ALLOWED_DOMAIN=rawasy.com`
   - `MICROSOFT_DEFAULT_ROLE=student`
   - `MICROSOFT_DEFAULT_TENANT_CODE=TKIFMS001`
4. Google OAuth login uses equivalent settings:
   - `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`
   - `GOOGLE_AUTO_PROVISION`, `GOOGLE_ALLOWED_DOMAIN`, `GOOGLE_DEFAULT_ROLE`, `GOOGLE_DEFAULT_TENANT_CODE`
5. Run tenant bootstrap migration once:
   - `mysql -u root -p < sql/migrations/20260603_add_provider_tenants.sql`

## Identity Linking (No Duplicates)
Apply in this order:
1. Add identity table:
   - `mysql -u root -p < sql/migrations/20260603_add_user_identities.sql`
2. Merge existing duplicate users safely (dry-run first):
   - `php scripts/merge_duplicate_users.php`
   - `php scripts/merge_duplicate_users.php --apply`
3. Enforce globally unique user email:
   - `mysql -u root -p < sql/migrations/20260603_enforce_unique_user_email.sql`
4. Use Identity Diagnostics page (Admin/IT only):
   - `/?page=identity_diagnostics`
   - Shows provider link coverage, duplicate-email detection, and pending backfill candidates.
   - CSV export for pending backfill candidates:
     - `/?page=identity_diagnostics_export`

## Role Hierarchy
- `IT > Admin > Manager > Student`
- IT can control/edit Admin, Manager, and Student profiles.
- Admin can control/edit Manager and Student profiles (not IT).
- Manager can control/edit Student profiles only.
- Users can still edit their own basic profile fields.

## Blacklist (Step 4)
1. Matching keys:
   - `register_id` (mapped to user registration sequence id)
   - normalized email (trim + case-insensitive)
2. Blacklist effect:
   - New applications are auto-rejected.
   - Existing `submitted` and `in_review` applications are auto-rejected.
3. Manual add:
   - Admin/IT can add blacklist rows from dashboard quickly.
4. Import file:
   - Supported: `.csv`, `.xlsx`
   - Supported headers: `register_id,email,reason`
   - `register_id` and/or `email` must exist per row.
   - `reason` is optional.
