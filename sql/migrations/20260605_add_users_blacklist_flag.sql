USE tkif_scholarship;

SET @has_blacklist := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'blacklist'
);

SET @alter_sql := IF(
  @has_blacklist = 0,
  'ALTER TABLE users ADD COLUMN blacklist TINYINT(1) NOT NULL DEFAULT 0 AFTER role',
  'SELECT 1'
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_count := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'idx_users_tenant_blacklist'
);

SET @idx_sql := IF(
  @idx_count = 0,
  'ALTER TABLE users ADD INDEX idx_users_tenant_blacklist (tenant_id, blacklist)',
  'SELECT 1'
);
PREPARE stmt2 FROM @idx_sql;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

UPDATE users
SET blacklist = 0
WHERE blacklist IS NULL;
