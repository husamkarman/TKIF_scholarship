CREATE DATABASE IF NOT EXISTS tkif_scholarship CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tkif_scholarship;

CREATE TABLE IF NOT EXISTS tenants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('student','admin','manager','it') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  email_verified_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email_global (email),
  UNIQUE KEY uq_tenant_email (tenant_id, email),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

CREATE TABLE IF NOT EXISTS user_identities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('google','microsoft') NOT NULL,
  provider_user_id VARCHAR(190) NOT NULL,
  provider_email VARCHAR(190) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_identity_provider_subject (provider, provider_user_id),
  UNIQUE KEY uq_identity_user_provider (user_id, provider),
  CONSTRAINT fk_identity_user FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS scholarships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  form_schema_json JSON NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_scholarships_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_scholarships_creator FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS applications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  scholarship_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  answers_json JSON NOT NULL,
  status ENUM('submitted','in_review','approved','rejected') NOT NULL DEFAULT 'submitted',
  rejection_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_app_tenant_student_id (tenant_id, student_id, id),
  INDEX idx_app_tenant_student_status_created_sch (tenant_id, student_id, status, created_at, scholarship_id, id),
  INDEX idx_app_tenant_student_sch_created (tenant_id, student_id, scholarship_id, created_at, id),
  CONSTRAINT fk_app_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_app_scholarship FOREIGN KEY (scholarship_id) REFERENCES scholarships(id),
  CONSTRAINT fk_app_student FOREIGN KEY (student_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS scholarship_form_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scholarship_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  form_schema_json JSON NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_scholarship_version (scholarship_id, version_no),
  INDEX idx_form_versions_tenant (tenant_id, scholarship_id, version_no),
  CONSTRAINT fk_form_versions_scholarship FOREIGN KEY (scholarship_id) REFERENCES scholarships(id),
  CONSTRAINT fk_form_versions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_form_versions_creator FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS application_form_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  scholarship_id BIGINT UNSIGNED NOT NULL,
  form_version_no INT UNSIGNED NOT NULL,
  form_schema_json JSON NOT NULL,
  answers_json JSON NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_snapshot_application (application_id),
  INDEX idx_snapshots_tenant_scholarship (tenant_id, scholarship_id, form_version_no),
  CONSTRAINT fk_snapshot_application FOREIGN KEY (application_id) REFERENCES applications(id),
  CONSTRAINT fk_snapshot_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_snapshot_scholarship FOREIGN KEY (scholarship_id) REFERENCES scholarships(id)
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  event_name VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  details_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS blacklist_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  register_id BIGINT UNSIGNED NULL,
  email_original VARCHAR(190) NULL,
  email_normalized VARCHAR(190) NULL,
  reason VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_blacklist_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_blacklist_creator FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_blacklist_tenant_register (tenant_id, register_id),
  INDEX idx_blacklist_tenant_email (tenant_id, email_normalized)
);

CREATE TABLE IF NOT EXISTS otp_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_otp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_otp_user_created (user_id, created_at),
  INDEX idx_otp_expires (expires_at)
);

CREATE TABLE IF NOT EXISTS email_verification_challenges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  channel ENUM('code','link') NOT NULL,
  code_hash VARCHAR(255) NULL,
  token_hash CHAR(64) NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_verification_user_created (user_id, created_at),
  INDEX idx_email_verification_token (token_hash),
  INDEX idx_email_verification_expires (expires_at),
  CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS rate_limit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action_key VARCHAR(60) NOT NULL,
  client_key VARCHAR(190) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rate_limit_action_client_time (action_key, client_key, created_at),
  INDEX idx_rate_limit_created_at (created_at)
);

CREATE TABLE IF NOT EXISTS user_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  first_name VARCHAR(120) NULL,
  middle_name VARCHAR(120) NULL,
  last_name VARCHAR(120) NULL,
  date_of_birth DATE NULL,
  nationality VARCHAR(120) NULL,
  phone_country_code VARCHAR(10) NULL,
  phone_number VARCHAR(30) NULL,
  whatsapp_number VARCHAR(30) NULL,
  secondary_email VARCHAR(190) NULL,
  address_country VARCHAR(120) NULL,
  address_city VARCHAR(120) NULL,
  address_zip_code VARCHAR(40) NULL,
  address_text TEXT NULL,
  auth_provider_id VARCHAR(190) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uq_profile_user (user_id)
);

CREATE TABLE IF NOT EXISTS notification_inbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  application_id BIGINT UNSIGNED NULL,
  event_name VARCHAR(120) NOT NULL,
  notification_type VARCHAR(120) NULL,
  correlation_id VARCHAR(190) NULL,
  delivery_route VARCHAR(120) NULL,
  auth_valid TINYINT(1) NOT NULL DEFAULT 0,
  source_ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  headers_json JSON NULL,
  payload_json JSON NOT NULL,
  status ENUM('received','processed','failed') NOT NULL DEFAULT 'received',
  error_message VARCHAR(255) NULL,
  received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  INDEX idx_notification_received_at (received_at),
  INDEX idx_notification_tenant_event (tenant_id, event_name),
  INDEX idx_notification_correlation (correlation_id),
  CONSTRAINT fk_notification_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_notification_application FOREIGN KEY (application_id) REFERENCES applications(id)
);

CREATE TABLE IF NOT EXISTS notification_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  application_id BIGINT UNSIGNED NULL,
  event_name VARCHAR(120) NOT NULL,
  payload_json JSON NOT NULL,
  status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  last_error VARCHAR(255) NULL,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  processed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_jobs_status_available (status, available_at),
  INDEX idx_jobs_tenant_event (tenant_id, event_name),
  CONSTRAINT fk_jobs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_jobs_application FOREIGN KEY (application_id) REFERENCES applications(id)
);
