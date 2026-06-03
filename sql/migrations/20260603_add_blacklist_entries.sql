USE tkif_scholarship;

CREATE TABLE IF NOT EXISTS blacklist_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  register_id BIGINT UNSIGNED NULL,
  email_original VARCHAR(190) NULL,
  email_normalized VARCHAR(190) NULL,
  reason VARCHAR(255) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_blacklist_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_blacklist_creator FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_blacklist_tenant_register (tenant_id, register_id),
  INDEX idx_blacklist_tenant_email (tenant_id, email_normalized)
);
