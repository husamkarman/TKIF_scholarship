CREATE TABLE IF NOT EXISTS forms (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  status ENUM('draft','published','closed','archived') NOT NULL DEFAULT 'draft',
  schema_json JSON NOT NULL,
  settings_json JSON NULL,
  theme_json JSON NULL,
  legacy_scholarship_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_forms_legacy_scholarship (legacy_scholarship_id),
  INDEX idx_forms_tenant_status_updated (tenant_id, status, updated_at),
  CONSTRAINT fk_forms_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_forms_creator FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS form_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  form_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  schema_json JSON NOT NULL,
  settings_json JSON NULL,
  theme_json JSON NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_form_versions_form_version (form_id, version_no),
  INDEX idx_form_versions_tenant_form (tenant_id, form_id, version_no),
  CONSTRAINT fk_form_versions_form FOREIGN KEY (form_id) REFERENCES forms(id),
  CONSTRAINT fk_form_versions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_form_versions_creator FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS form_submissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  form_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  submitted_by_user_id BIGINT UNSIGNED NULL,
  snapshot_version_no INT UNSIGNED NULL,
  answers_json JSON NOT NULL,
  status ENUM('submitted','in_review','approved','rejected') NOT NULL DEFAULT 'submitted',
  rejection_reason VARCHAR(255) NULL,
  legacy_application_id BIGINT UNSIGNED NULL,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_form_submissions_legacy_application (legacy_application_id),
  INDEX idx_form_submissions_form_status_time (form_id, status, submitted_at),
  INDEX idx_form_submissions_tenant_user_time (tenant_id, submitted_by_user_id, submitted_at),
  CONSTRAINT fk_form_submissions_form FOREIGN KEY (form_id) REFERENCES forms(id),
  CONSTRAINT fk_form_submissions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_form_submissions_user FOREIGN KEY (submitted_by_user_id) REFERENCES users(id)
);

INSERT INTO forms (tenant_id, title, description, status, schema_json, settings_json, theme_json, legacy_scholarship_id, created_by, created_at, updated_at)
SELECT
  s.tenant_id,
  s.title,
  s.description,
  CASE s.status
    WHEN 'draft' THEN 'draft'
    WHEN 'published' THEN 'published'
    WHEN 'closed' THEN 'closed'
    ELSE 'draft'
  END,
  s.form_schema_json,
  JSON_OBJECT(),
  JSON_OBJECT(),
  s.id,
  s.created_by,
  s.created_at,
  s.created_at
FROM scholarships s
WHERE NOT EXISTS (
  SELECT 1
  FROM forms f
  WHERE f.legacy_scholarship_id = s.id
);

INSERT INTO form_versions (form_id, tenant_id, version_no, status, schema_json, settings_json, theme_json, created_by, created_at)
SELECT
  f.id,
  v.tenant_id,
  v.version_no,
  v.status,
  v.form_schema_json,
  JSON_OBJECT(),
  JSON_OBJECT(),
  v.created_by,
  v.created_at
FROM scholarship_form_versions v
INNER JOIN forms f ON f.legacy_scholarship_id = v.scholarship_id
WHERE NOT EXISTS (
  SELECT 1
  FROM form_versions fv
  WHERE fv.form_id = f.id
    AND fv.version_no = v.version_no
);

INSERT INTO form_submissions (form_id, tenant_id, submitted_by_user_id, snapshot_version_no, answers_json, status, rejection_reason, legacy_application_id, submitted_at, updated_at)
SELECT
  f.id,
  a.tenant_id,
  a.student_id,
  snap.form_version_no,
  a.answers_json,
  a.status,
  a.rejection_reason,
  a.id,
  a.created_at,
  a.updated_at
FROM applications a
INNER JOIN forms f ON f.legacy_scholarship_id = a.scholarship_id
LEFT JOIN application_form_snapshots snap ON snap.application_id = a.id
WHERE NOT EXISTS (
  SELECT 1
  FROM form_submissions fs
  WHERE fs.legacy_application_id = a.id
);
