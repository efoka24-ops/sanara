ALTER TABLE users ADD COLUMN totp_enabled TINYINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN totp_backup_codes TEXT;
ALTER TABLE users ADD COLUMN totp_last_counter BIGINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN pin_hash VARCHAR(255);
ALTER TABLE users ADD COLUMN pin_failures INT NOT NULL DEFAULT 0;
CREATE TABLE password_history (
  id CHAR(26) NOT NULL PRIMARY KEY,
  user_id CHAR(26) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_pwhist_user ON password_history (user_id, created_at);
CREATE TABLE password_resets (
  id CHAR(26) NOT NULL PRIMARY KEY,
  user_id CHAR(26) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME(3) NOT NULL,
  used_at DATETIME(3),
  created_at DATETIME(3) NOT NULL
)
;
ALTER TABLE refresh_tokens ADD COLUMN label VARCHAR(80)
