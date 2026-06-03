# Secret Rotation and Security Validation (Step 1)

This runbook is for rotating sensitive credentials safely and verifying login/email integrations after rotation.

## 1. Rotate credentials at source
Rotate these credentials in their provider consoles first:

- SMTP password (mail provider)
- Microsoft OAuth client secret (Azure)
- Google OAuth client secret (Google Cloud)
- Database password (if shared outside local machine)
- N8N_API_KEY (if webhook auth is used)

## 2. Update local environment
Edit .env and replace old values with new values.

Important:
- Keep secrets only in .env
- Do not commit .env
- Keep .env.example with placeholders only

## 3. Run env security check
From workspace root:

- bash scripts/check_env_security.sh .env

Expected result:
- Result: PASS

If warnings appear for OAuth auto-provision domains, decide policy:
- Restrict by setting MICROSOFT_ALLOWED_DOMAIN and GOOGLE_ALLOWED_DOMAIN
- Or keep empty only if open-tenant auto-provision is intended

## 4. Functional validation
Run local app:

- php -S localhost:8080 -t public

Validate flows:

1. Email/password login works.
2. OTP request and verify works.
3. Microsoft login redirects and callback succeeds.
4. Google login redirects and callback succeeds.
5. Student application submit works.
6. SMTP email notifications are delivered.

## 5. Production pre-deploy checks
Before cPanel deployment:

1. Confirm no secrets are present in tracked files.
2. Confirm .env is excluded in gitignore.
3. Run env checker one more time on server .env.
4. Re-test Microsoft and Google redirect URIs against production domain.

## 6. Emergency rollback plan
If authentication fails after rotation:

1. Revert only one credential at a time to identify failing provider.
2. Verify redirect URI and client ID pair matches provider app.
3. Re-run env checker.
4. Re-test callback endpoints.
