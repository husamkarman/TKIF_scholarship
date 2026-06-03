# TKIF Scholarship MVP Foundation

This repository is a local starter for:
- PHP + MySQL role-based scholarship portal
- n8n webhook automation
- Manual cPanel deployment path

## Included
- Student/Admin/Manager/IT login routing
- Basic scholarship apply flow
- Basic application approve/reject flow
- n8n webhook trigger on submit and decision
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

## SMTP Setup (Secure)
1. Set all SMTP values in `.env`.
2. Keep `SMTP_PASSWORD` only in `.env` (never commit).
3. Enable delivery by setting `SMTP_ENABLED=true`.
4. Current app events that trigger email:
   - Student application submitted
   - Admin/Manager application decision (approved/rejected)

## n8n Quick Start
1. Start n8n:
   - `docker compose -f docker-compose.n8n.yml up -d`
2. Open n8n UI:
   - `http://localhost:5678`
3. Import workflow file:
   - `n8n/workflows/scholarship-submit.json`
4. Activate workflow

## Next Build Step
- Add Google auth integration.
- Add WhatsApp/SMS providers when keys are available.

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
