# Manual cPanel Upload Guide (MVP)

## Package Locally
1. Ensure `.env` is configured for server DB and URLs.
2. Zip these paths:
   - `public/`
   - `app/`
   - `sql/` (optional on production if already imported)
   - `.env` (do not commit)

## Upload to cPanel
1. Upload app files to target directory.
2. Set web root to `public/`.
3. Import DB schema:
   - `sql/schema.sql`
   - `sql/seed.sql` (only for demo/test)
4. Verify PHP extensions:
   - `pdo_mysql`, `curl`, `mbstring`, `json`

## n8n Deployment
1. If Docker is allowed on server:
   - Upload `docker-compose.n8n.yml` and `n8n/workflows/*`
   - Run `docker compose -f docker-compose.n8n.yml up -d`
2. If Docker blocked:
   - Host n8n on separate VPS and update `N8N_BASE_URL` in `.env`

## Post-Upload Smoke Tests
1. Login with each role and verify dashboard opens.
2. Student submits application.
3. Admin approves/rejects application.
4. Verify n8n webhook receives event.
5. Confirm audit log rows are created for decisions.
