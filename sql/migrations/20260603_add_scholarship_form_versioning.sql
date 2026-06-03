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