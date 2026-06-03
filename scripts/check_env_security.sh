#!/usr/bin/env bash
set -euo pipefail

ENV_FILE="${1:-.env}"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "[FAIL] Missing env file: $ENV_FILE"
  exit 1
fi

required_keys=(
  APP_URL
  DB_HOST
  DB_PORT
  DB_NAME
  DB_USER
  DB_PASS
  SESSION_NAME
  N8N_BASE_URL
  N8N_API_KEY
  SMTP_ENABLED
  OTP_ENABLED
  MICROSOFT_CLIENT_ID
  MICROSOFT_TENANT_ID
  MICROSOFT_REDIRECT_URI
  MICROSOFT_CLIENT_SECRET
  GOOGLE_CLIENT_ID
  GOOGLE_CLIENT_SECRET
  GOOGLE_REDIRECT_URI
)

placeholder_values_regex='^(|replace_me|changeme|change_me|example|example_value|your_value)$'

get_value() {
  local key="$1"
  local line
  line=$(grep -E "^${key}=" "$ENV_FILE" | tail -n 1 || true)
  if [[ -z "$line" ]]; then
    echo ""
    return 0
  fi
  echo "${line#*=}"
}

fail_count=0
warn_count=0

echo "Security env check: $ENV_FILE"

for key in "${required_keys[@]}"; do
  value="$(get_value "$key")"
  if [[ -z "$value" ]]; then
    echo "[FAIL] $key is missing or empty"
    fail_count=$((fail_count + 1))
    continue
  fi

  lower=$(echo "$value" | tr '[:upper:]' '[:lower:]')
  if [[ "$lower" =~ $placeholder_values_regex ]]; then
    echo "[FAIL] $key uses a placeholder value"
    fail_count=$((fail_count + 1))
  fi

done

# Optional risk checks (warnings)
ms_auto=$(echo "$(get_value MICROSOFT_AUTO_PROVISION)" | tr '[:upper:]' '[:lower:]')
ms_domain="$(get_value MICROSOFT_ALLOWED_DOMAIN)"
if [[ "$ms_auto" == "true" && -z "$ms_domain" ]]; then
  echo "[WARN] MICROSOFT_AUTO_PROVISION=true with empty MICROSOFT_ALLOWED_DOMAIN"
  warn_count=$((warn_count + 1))
fi

g_auto=$(echo "$(get_value GOOGLE_AUTO_PROVISION)" | tr '[:upper:]' '[:lower:]')
g_domain="$(get_value GOOGLE_ALLOWED_DOMAIN)"
if [[ "$g_auto" == "true" && -z "$g_domain" ]]; then
  echo "[WARN] GOOGLE_AUTO_PROVISION=true with empty GOOGLE_ALLOWED_DOMAIN"
  warn_count=$((warn_count + 1))
fi

if [[ $fail_count -gt 0 ]]; then
  echo "Result: FAIL ($fail_count failures, $warn_count warnings)"
  exit 2
fi

echo "Result: PASS ($warn_count warnings)"
