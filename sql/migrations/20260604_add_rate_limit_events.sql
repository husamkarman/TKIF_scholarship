CREATE TABLE IF NOT EXISTS rate_limit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action_key VARCHAR(60) NOT NULL,
  client_key VARCHAR(190) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rate_limit_action_client_time (action_key, client_key, created_at),
  INDEX idx_rate_limit_created_at (created_at)
);
