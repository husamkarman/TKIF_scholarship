#!/usr/bin/env bash
set -euo pipefail

# cPanel-friendly runner for security retention cleanup endpoint.
# Usage:
#   SECURITY_RETENTION_URL="https://your-domain/?page=security_retention_run" \
#   SECURITY_RETENTION_TOKEN="your-token" \
#   bash scripts/security_retention_cron.sh

RETENTION_URL="${SECURITY_RETENTION_URL:-}"
RETENTION_TOKEN="${SECURITY_RETENTION_TOKEN:-}"
CURL_BIN="${CURL_BIN:-/usr/bin/curl}"

if [[ -z "$RETENTION_URL" ]]; then
  echo "ERROR: SECURITY_RETENTION_URL is required." >&2
  exit 1
fi

if [[ -z "$RETENTION_TOKEN" ]]; then
  echo "ERROR: SECURITY_RETENTION_TOKEN is required." >&2
  exit 1
fi

"$CURL_BIN" -sS -X POST "$RETENTION_URL" \
  -H "X-Worker-Token: $RETENTION_TOKEN" \
  -d "token=$RETENTION_TOKEN"
