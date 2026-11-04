ALTER TABLE users
  ADD COLUMN created_by_admin_id BIGINT UNSIGNED NULL;

CREATE TABLE auth_sessions (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  account_type VARCHAR(16) NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auth_sessions_expiration (expires_at),
  INDEX idx_auth_sessions_account (account_type, account_id)
);
