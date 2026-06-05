# Manual cPanel Upload Guide (MVP)

## Package Locally
1. Ensure `.env` is configured for server DB and URLs.
2. Complete security rotation checklist before packaging:
   - `SECURITY_ROTATION.md`
   - `bash scripts/check_env_security.sh .env`
2. Zip these paths:
   - `public/`
   - `app/`
   - `sql/` (optional on production if already imported)
   - `.env` (do not commit)

## Upload to cPanel
1. Upload app files to target directory.
2. Preferred: set the web root to `public/`.
3. If cPanel cannot change the document root, keep the project root as the upload target and use the root-level `.htaccess` to forward requests into `public/`.
4. Import DB schema:
   - `sql/schema.sql`
   - `sql/seed.sql` (only for demo/test)
5. Verify PHP extensions:
   - `pdo_mysql`, `curl`, `mbstring`, `json`

## Internal Worker Scheduling (No Third-Party Service)
1. Ensure these `.env` values exist:
   - `INTERNAL_NOTIFICATION_SECRET=<strong-random-secret>`
   - `INTERNAL_NOTIFICATION_WORKER_TOKEN=<strong-random-token>`
2. Apply migrations:
   - `mysql -u root -p < sql/migrations/20260603_add_notification_inbox.sql`
   - `mysql -u root -p < sql/migrations/20260603_add_notification_jobs.sql`
3. Add cPanel cron job (every minute):
   - `* * * * * /usr/bin/curl -sS -X POST "https://your-domain/?page=notification_worker_run" -H "X-Worker-Token: YOUR_WORKER_TOKEN" >/dev/null 2>&1`
4. Optional fallback via CLI cron (if HTTP cron is restricted):
   - `* * * * * /usr/local/bin/php -r '$_SERVER["REQUEST_METHOD"]="POST"; $_GET["page"]="notification_worker_run"; $_POST["token"]="YOUR_WORKER_TOKEN"; include "/home/USER/public_html/public/index.php";' >/dev/null 2>&1`

## Email Verification (Registration Gate)
1. Ensure these `.env` values exist:
   - `EMAIL_VERIFICATION_ENABLED=true`
   - `EMAIL_VERIFICATION_METHOD=code` (or `link`)
   - `EMAIL_VERIFICATION_REQUIRE_FOR_LOGIN=true`
2. Ensure SMTP delivery is configured (`SMTP_ENABLED=true` and SMTP credentials).
3. Apply migration:
   - `mysql -u root -p < sql/migrations/20260603_add_email_verification.sql`

## OAuth Preflight (Before Go-Live)
1. Set production callbacks in `.env`:
   - `GOOGLE_REDIRECT_URI_PROD=https://your-domain/?page=auth_google_callback`
   - `MICROSOFT_REDIRECT_URI_PROD=https://your-domain/?page=auth_microsoft_callback`
2. Set `APP_ENV=production`.
3. Register the exact same redirect URIs in Google and Microsoft app consoles.
4. Confirm provider client IDs in `.env` match the app registrations you edited.
5. Run quick live check:
   - open login page and click provider sign-in once.
   - if provider shows mismatch, copy exact `redirect_uri` from error details and update console entry exactly.

## Step 10 Ops Hardening
1. Apply rate limit migration:
   - `mysql -u root -p < sql/migrations/20260604_add_rate_limit_events.sql`
2. Add nightly cleanup cron:
   - `0 3 * * * /usr/local/bin/php /home/USER/public_html/scripts/cleanup_maintenance.php --apply >/dev/null 2>&1`
3. Add periodic health check cron (every 5 minutes):
   - `*/5 * * * * /usr/local/bin/php /home/USER/public_html/scripts/ops_health_check.php >/dev/null 2>&1`
4. Add security retention cleanup cron (daily):
    - Ensure `.env` includes:
       - `SECURITY_RETENTION_ENABLED=true`
       - `SECURITY_RETENTION_WORKER_TOKEN=<strong-random-token>`
    - Cron command:
       - `0 2 * * * /usr/bin/curl -sS -X POST "https://your-domain/?page=security_retention_run" -H "X-Worker-Token: YOUR_RETENTION_TOKEN" -d "token=YOUR_RETENTION_TOKEN" >/dev/null 2>&1`
    - Optional helper script usage:
       - `SECURITY_RETENTION_URL="https://your-domain/?page=security_retention_run" SECURITY_RETENTION_TOKEN="YOUR_RETENTION_TOKEN" bash /home/USER/public_html/scripts/security_retention_cron.sh >/dev/null 2>&1`
4. Use Dashboard -> Admin Support Tools for:
   - resend verification
   - unlock user
   - inspect verification attempts

## Blacklist Operations
1. For registered users, add blacklist rows using `register_id` or email.
2. For non-registered persons, use email only.
3. Use the Dashboard `Preview Person` button before blacklisting so IT/Admin can verify the person first.
4. Import remains supported for `.csv` and `.xlsx` using headers `register_id,email,reason`.

## Post-Upload Smoke Tests
1. Login with each role and verify dashboard opens.
2. Register a new guest account and verify it lands on `/?page=verify_email`.
3. Complete email verification and confirm dashboard access.
4. Student submits application.
5. Admin approves/rejects application.
6. Trigger worker endpoint once and verify `notification_jobs` rows move to `sent`.
7. Verify new rows appear in `notification_inbox` with `auth_valid = 1`.
8. Confirm audit log rows are created for decisions.
