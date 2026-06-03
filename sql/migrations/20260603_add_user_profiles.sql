USE tkif_scholarship;

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

INSERT INTO user_profiles (user_id, first_name, middle_name, last_name)
SELECT
  u.id,
  TRIM(SUBSTRING_INDEX(u.full_name, ' ', 1)) AS first_name,
  '' AS middle_name,
  CASE
    WHEN INSTR(TRIM(u.full_name), ' ') > 0 THEN TRIM(SUBSTRING(TRIM(u.full_name), INSTR(TRIM(u.full_name), ' ') + 1))
    ELSE ''
  END AS last_name
FROM users u
LEFT JOIN user_profiles p ON p.user_id = u.id
WHERE p.user_id IS NULL;
