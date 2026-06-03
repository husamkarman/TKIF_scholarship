SET @has_email_verified_at := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'email_verified_at'
);

SET @alter_users_sql := IF(
  @has_email_verified_at = 0,
  'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER is_active',
  'SELECT 1'
);

PREPARE alter_users_stmt FROM @alter_users_sql;
EXECUTE alter_users_stmt;
DEALLOCATE PREPARE alter_users_stmt;

UPDATE users
SET email_verified_at = COALESCE(email_verified_at, NOW());

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
