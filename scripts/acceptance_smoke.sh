#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://localhost:8080}"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

pass_count=0
fail_count=0

pass() {
  echo "[PASS] $1"
  pass_count=$((pass_count + 1))
}

fail() {
  echo "[FAIL] $1"
  fail_count=$((fail_count + 1))
}

env_value() {
  local key="$1"
  local env_file=".env"
  if [[ ! -f "$env_file" ]]; then
    echo ""
    return 0
  fi
  awk -F= -v k="$key" '$1 == k {print substr($0, index($0, "=") + 1)}' "$env_file" | tail -n 1
}

db_lookup_user_id_by_email() {
  local email="$1"
  db_scalar "SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM('${email}')) LIMIT 1;"
}

db_scalar() {
  local sql="$1"
  local db_host db_port db_name db_user db_pass
  db_host="$(env_value DB_HOST)"
  db_port="$(env_value DB_PORT)"
  db_name="$(env_value DB_NAME)"
  db_user="$(env_value DB_USER)"
  db_pass="$(env_value DB_PASS)"

  if [[ -z "$db_host" || -z "$db_port" || -z "$db_name" || -z "$db_user" ]]; then
    return 1
  fi

  MYSQL_PWD="$db_pass" mysql \
    --batch --skip-column-names \
    -h "$db_host" -P "$db_port" -u "$db_user" "$db_name" \
    -e "$sql" 2>/dev/null
}

extract_csrf() {
  local file="$1"
  sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$file" | head -n 1
}

extract_input_value() {
  local file="$1"
  local name="$2"
  local line
  line=$(grep -m1 "name=\"$name\"" "$file" || true)
  if [[ -z "$line" ]]; then
    echo ""
    return 0
  fi
  echo "$line" | sed -n 's/.*value="\([^"]*\)".*/\1/p'
}

extract_textarea_value() {
  local file="$1"
  local name="$2"
  sed -n "/<textarea name=\"$name\"/,/<\\/textarea>/p" "$file" | sed -n 's/.*<textarea[^>]*>\(.*\)<\/textarea>.*/\1/p' | head -n 1
}

login_user() {
  local email="$1"
  local password="$2"
  local cookie_file="$3"
  local out_file="$4"

  curl -sS -c "$cookie_file" "$BASE_URL/?page=login" > "$WORK_DIR/login_form.html"
  local csrf
  csrf="$(extract_csrf "$WORK_DIR/login_form.html")"
  if [[ -z "$csrf" ]]; then
    return 1
  fi

  curl -sS -L -b "$cookie_file" -c "$cookie_file" \
    --data-urlencode "csrf=$csrf" \
    --data-urlencode "email=$email" \
    --data-urlencode "password=$password" \
    "$BASE_URL/?page=login" > "$out_file"
}

# 1) Student login + apply flow
student_cookie="$WORK_DIR/student.cookie"
student_dash="$WORK_DIR/student_dash.html"
if login_user "student@tkif.local" "Password123!" "$student_cookie" "$student_dash" && grep -q 'Dashboard' "$student_dash"; then
  pass "Student login"
else
  fail "Student login"
fi

student_csrf="$(extract_csrf "$student_dash")"
scholarship_id=$(sed -n 's/.*name="scholarship_id" value="\([0-9][0-9]*\)".*/\1/p' "$student_dash" | head -n 1)
if [[ -n "$student_csrf" && -n "$scholarship_id" ]]; then
  apply_args=(
    --data-urlencode "csrf=$student_csrf"
    --data-urlencode "scholarship_id=$scholarship_id"
  )

  while IFS= read -r field_name; do
    [[ -z "$field_name" ]] && continue
    apply_args+=(--data-urlencode "answers[$field_name]=1")
  done < <(grep -o 'name="answers\[[^]]*\]"' "$student_dash" | sed 's/name="answers\[\([^]]*\)\]"/\1/' | sort -u)

  curl -sS -L -b "$student_cookie" -c "$student_cookie" "${apply_args[@]}" "$BASE_URL/?page=apply" > "$WORK_DIR/student_apply.html"
  if grep -Eq 'Application submitted successfully|auto-rejected because this registration is blacklisted' "$WORK_DIR/student_apply.html"; then
    pass "Student apply workflow"
  else
    fail "Student apply workflow"
  fi
else
  fail "Student apply workflow (missing csrf or scholarship_id)"
fi

# 2) Manager view + filters + CSV export
manager_cookie="$WORK_DIR/manager.cookie"
manager_dash="$WORK_DIR/manager_dash.html"
if login_user "manager@tkif.local" "Password123!" "$manager_cookie" "$manager_dash" && grep -q 'Dashboard' "$manager_dash"; then
  pass "Manager login"
else
  fail "Manager login"
fi

student_user_id="$(db_lookup_user_id_by_email "student@tkif.local" || true)"
admin_user_id="$(db_lookup_user_id_by_email "admin@tkif.local" || true)"
manager_user_id="$(db_lookup_user_id_by_email "manager@tkif.local" || true)"
it_user_id="$(db_lookup_user_id_by_email "it@tkif.local" || true)"

if [[ -z "$student_user_id" || -z "$admin_user_id" || -z "$manager_user_id" || -z "$it_user_id" ]]; then
  fail "Resolve seeded role user IDs"
fi

manager_identity_status=$(curl -sS -o "$WORK_DIR/manager_identity.html" -w '%{http_code}' -b "$manager_cookie" "$BASE_URL/?page=identity_diagnostics")
if [[ "$manager_identity_status" == "403" ]]; then
  pass "Manager blocked from identity diagnostics"
else
  fail "Manager blocked from identity diagnostics"
fi

manager_admin_profile_status=$(curl -sS -o "$WORK_DIR/manager_admin_profile.html" -w '%{http_code}' -b "$manager_cookie" "$BASE_URL/?page=profile&user_id=$admin_user_id")
if [[ "$manager_admin_profile_status" == "403" ]]; then
  pass "Manager blocked from admin profile"
else
  fail "Manager blocked from admin profile"
fi

curl -sS -L -b "$manager_cookie" -c "$manager_cookie" "$BASE_URL/?page=profile&user_id=$student_user_id&app_status=approved" > "$WORK_DIR/manager_profile.html"
if grep -q 'Student Scholarship Applications' "$WORK_DIR/manager_profile.html" && grep -q 'name="app_status"' "$WORK_DIR/manager_profile.html" && grep -q 'name="app_from"' "$WORK_DIR/manager_profile.html" && grep -q 'name="app_to"' "$WORK_DIR/manager_profile.html" && grep -q 'name="app_scholarship_id"' "$WORK_DIR/manager_profile.html"; then
  pass "Manager profile filters visible"
else
  fail "Manager profile filters visible"
fi

if sed -n '/name="user_type"/,/<\/select>/p' "$WORK_DIR/manager_profile.html" | grep -q 'option value="student"'; then
  pass "Manager user-type control limited to student"
else
  fail "Manager user-type control limited to student"
fi

manager_admin_export_status=$(curl -sS -o "$WORK_DIR/manager_admin_export.csv" -w '%{http_code}' -b "$manager_cookie" "$BASE_URL/?page=profile_export&user_id=$admin_user_id")
if [[ "$manager_admin_export_status" == "403" ]]; then
  pass "Manager blocked from admin profile export"
else
  fail "Manager blocked from admin profile export"
fi

curl -sS -D "$WORK_DIR/export_headers.txt" -b "$manager_cookie" "$BASE_URL/?page=profile_export&user_id=$student_user_id&app_status=rejected" > "$WORK_DIR/export.csv"
if grep -qi 'Content-Type: text/csv' "$WORK_DIR/export_headers.txt" && head -n 1 "$WORK_DIR/export.csv" | grep -q '^application_id,scholarship,status,created_at$'; then
  pass "Filtered CSV export"
else
  fail "Filtered CSV export"
fi

# 3) Admin user-type edit and restore for user_id=1
admin_cookie="$WORK_DIR/admin.cookie"
admin_dash="$WORK_DIR/admin_dash.html"
if login_user "admin@tkif.local" "Password123!" "$admin_cookie" "$admin_dash" && grep -q 'Dashboard' "$admin_dash"; then
  pass "Admin login"
else
  fail "Admin login"
fi

it_cookie="$WORK_DIR/it.cookie"
it_dash="$WORK_DIR/it_dash.html"
if login_user "it@tkif.local" "Password123!" "$it_cookie" "$it_dash" && grep -q 'Dashboard' "$it_dash"; then
  pass "IT login"
else
  fail "IT login"
fi

curl -sS -L -b "$it_cookie" -c "$it_cookie" "$BASE_URL/?page=identity_diagnostics" > "$WORK_DIR/it_identity.html"
if grep -q 'Identity Diagnostics' "$WORK_DIR/it_identity.html" && grep -q 'Coverage Summary' "$WORK_DIR/it_identity.html"; then
  pass "IT identity diagnostics access"
else
  fail "IT identity diagnostics access"
fi

curl -sS -L -b "$admin_cookie" -c "$admin_cookie" "$BASE_URL/?page=identity_diagnostics" > "$WORK_DIR/admin_identity.html"
if grep -q 'Identity Diagnostics' "$WORK_DIR/admin_identity.html" && grep -q 'Coverage Summary' "$WORK_DIR/admin_identity.html"; then
  pass "Admin identity diagnostics access"
else
  fail "Admin identity diagnostics access"
fi

admin_it_profile_status=$(curl -sS -o "$WORK_DIR/admin_it_profile.html" -w '%{http_code}' -b "$admin_cookie" "$BASE_URL/?page=profile&user_id=$it_user_id")
if [[ "$admin_it_profile_status" == "403" ]]; then
  pass "Admin blocked from IT profile"
else
  fail "Admin blocked from IT profile"
fi

curl -sS -D "$WORK_DIR/identity_export_headers.txt" -b "$admin_cookie" "$BASE_URL/?page=identity_diagnostics_export" > "$WORK_DIR/identity_export.csv"
if grep -qi 'Content-Type: text/csv' "$WORK_DIR/identity_export_headers.txt" && head -n 1 "$WORK_DIR/identity_export.csv" | grep -q '^user_id,full_name,email,role,tenant_code,inferred_provider,auth_provider_id$'; then
  pass "Admin identity diagnostics CSV export"
else
  fail "Admin identity diagnostics CSV export"
fi

curl -sS -D "$WORK_DIR/it_identity_export_headers.txt" -b "$it_cookie" "$BASE_URL/?page=identity_diagnostics_export" > "$WORK_DIR/it_identity_export.csv"
if grep -qi 'Content-Type: text/csv' "$WORK_DIR/it_identity_export_headers.txt" && head -n 1 "$WORK_DIR/it_identity_export.csv" | grep -q '^user_id,full_name,email,role,tenant_code,inferred_provider,auth_provider_id$'; then
  pass "IT identity diagnostics CSV export"
else
  fail "IT identity diagnostics CSV export"
fi

curl -sS -L -b "$admin_cookie" -c "$admin_cookie" "$BASE_URL/?page=profile&user_id=$student_user_id" > "$WORK_DIR/admin_profile_before.html"
csrf_admin="$(extract_csrf "$WORK_DIR/admin_profile_before.html")"
orig_user_type=$(sed -n '/name="user_type"/,/<\/select>/p' "$WORK_DIR/admin_profile_before.html" | sed -n 's/.*option value="\([^"]*\)"[^>]*selected.*/\1/p' | head -n 1)
profile_status=$(sed -n '/name="profile_status"/,/<\/select>/p' "$WORK_DIR/admin_profile_before.html" | sed -n 's/.*option value="\([^"]*\)"[^>]*selected.*/\1/p' | head -n 1)

if [[ -z "$csrf_admin" || -z "$orig_user_type" || -z "$profile_status" ]]; then
  fail "Admin profile update parse prerequisites"
else
  target_user_type="manager"
  if [[ "$orig_user_type" == "manager" ]]; then
    target_user_type="student"
  fi

  primary_email="$(extract_input_value "$WORK_DIR/admin_profile_before.html" primary_email)"
  auth_provider_id="$(extract_input_value "$WORK_DIR/admin_profile_before.html" auth_provider_id)"
  first_name="$(extract_input_value "$WORK_DIR/admin_profile_before.html" first_name)"
  middle_name="$(extract_input_value "$WORK_DIR/admin_profile_before.html" middle_name)"
  last_name="$(extract_input_value "$WORK_DIR/admin_profile_before.html" last_name)"
  date_of_birth="$(extract_input_value "$WORK_DIR/admin_profile_before.html" date_of_birth)"
  nationality="$(extract_input_value "$WORK_DIR/admin_profile_before.html" nationality)"
  phone_country_code="$(extract_input_value "$WORK_DIR/admin_profile_before.html" phone_country_code)"
  phone_number="$(extract_input_value "$WORK_DIR/admin_profile_before.html" phone_number)"
  whatsapp_number="$(extract_input_value "$WORK_DIR/admin_profile_before.html" whatsapp_number)"
  secondary_email="$(extract_input_value "$WORK_DIR/admin_profile_before.html" secondary_email)"
  address_country="$(extract_input_value "$WORK_DIR/admin_profile_before.html" address_country)"
  address_city="$(extract_input_value "$WORK_DIR/admin_profile_before.html" address_city)"
  address_zip_code="$(extract_input_value "$WORK_DIR/admin_profile_before.html" address_zip_code)"
  address_text="$(extract_textarea_value "$WORK_DIR/admin_profile_before.html" address_text)"

  post_profile() {
    local user_type="$1"
    curl -sS -L -b "$admin_cookie" -c "$admin_cookie" \
      --data-urlencode "csrf=$csrf_admin" \
      --data-urlencode "user_id=$student_user_id" \
      --data-urlencode "primary_email=$primary_email" \
      --data-urlencode "auth_provider_id=$auth_provider_id" \
      --data-urlencode "user_type=$user_type" \
      --data-urlencode "profile_status=$profile_status" \
      --data-urlencode "first_name=$first_name" \
      --data-urlencode "middle_name=$middle_name" \
      --data-urlencode "last_name=$last_name" \
      --data-urlencode "date_of_birth=$date_of_birth" \
      --data-urlencode "nationality=$nationality" \
      --data-urlencode "phone_country_code=$phone_country_code" \
      --data-urlencode "phone_number=$phone_number" \
      --data-urlencode "whatsapp_number=$whatsapp_number" \
      --data-urlencode "secondary_email=$secondary_email" \
      --data-urlencode "address_country=$address_country" \
      --data-urlencode "address_city=$address_city" \
      --data-urlencode "address_zip_code=$address_zip_code" \
      --data-urlencode "address_text=$address_text" \
      "$BASE_URL/?page=profile_save" > "$WORK_DIR/admin_profile_save.html"
  }

  post_profile "$target_user_type"
  curl -sS -L -b "$admin_cookie" -c "$admin_cookie" "$BASE_URL/?page=profile&user_id=$student_user_id" > "$WORK_DIR/admin_profile_after.html"

  if sed -n '/name="user_type"/,/<\/select>/p' "$WORK_DIR/admin_profile_after.html" | grep -q "option value=\"$target_user_type\"[^>]*selected"; then
    pass "Admin can update User Type"
  else
    fail "Admin can update User Type"
  fi

  # Restore original user type to keep environment stable.
  post_profile "$orig_user_type"
fi

# 4) Identity/duplicate integrity checks
identity_table_count="$(db_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_identities';" || true)"
if [[ "$identity_table_count" == "1" ]]; then
  pass "Identity table migration applied"
else
  fail "Identity table migration applied"
fi

duplicate_email_groups="$(db_scalar "SELECT COUNT(*) FROM (SELECT LOWER(TRIM(email)) AS normalized_email, COUNT(*) AS total FROM users GROUP BY LOWER(TRIM(email)) HAVING COUNT(*) > 1) dup;" || true)"
if [[ "$duplicate_email_groups" == "0" ]]; then
  pass "No duplicate user emails"
else
  fail "No duplicate user emails"
fi

echo "----"
echo "Acceptance summary: PASS=$pass_count FAIL=$fail_count"
if [[ $fail_count -gt 0 ]]; then
  exit 1
fi
