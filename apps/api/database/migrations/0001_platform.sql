CREATE TABLE countries (
  code CHAR(2) NOT NULL PRIMARY KEY,
  name_fr VARCHAR(120) NOT NULL,
  name_en VARCHAR(120) NOT NULL,
  currency CHAR(3) NOT NULL,
  minor_unit SMALLINT NOT NULL,
  timezone VARCHAR(64) NOT NULL,
  default_locale VARCHAR(5) NOT NULL,
  dial_code VARCHAR(6) NOT NULL,
  status VARCHAR(16) NOT NULL
);
CREATE TABLE country_configs (
  id CHAR(26) NOT NULL PRIMARY KEY,
  country_code CHAR(2) NOT NULL,
  config_key VARCHAR(80) NOT NULL,
  config_value JSON NOT NULL,
  UNIQUE (country_code, config_key)
);
CREATE TABLE tenants (
  id CHAR(26) NOT NULL PRIMARY KEY,
  country_code CHAR(2) NOT NULL,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  status VARCHAR(16) NOT NULL,
  address VARCHAR(255),
  latitude DECIMAL(9,6),
  longitude DECIMAL(9,6),
  phone VARCHAR(32),
  email VARCHAR(190),
  license_number VARCHAR(64),
  public_visible TINYINT NOT NULL,
  currency CHAR(3) NOT NULL,
  autonomy_level SMALLINT NOT NULL,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  deleted_at DATETIME(3)
);
CREATE TABLE tenant_settings (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  setting_key VARCHAR(80) NOT NULL,
  setting_value JSON NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  UNIQUE (tenant_id, setting_key)
);
CREATE TABLE devices (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  user_id CHAR(26),
  name VARCHAR(80) NOT NULL,
  fingerprint VARCHAR(128) NOT NULL,
  last_seen_at DATETIME(3),
  revoked_at DATETIME(3),
  created_at DATETIME(3) NOT NULL,
  UNIQUE (tenant_id, fingerprint)
);
CREATE TABLE roles (
  code VARCHAR(32) NOT NULL PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  scope VARCHAR(16) NOT NULL
);
CREATE TABLE permissions (
  code VARCHAR(48) NOT NULL PRIMARY KEY,
  description VARCHAR(190) NOT NULL
);
CREATE TABLE role_permissions (
  role_code VARCHAR(32) NOT NULL,
  permission_code VARCHAR(48) NOT NULL,
  PRIMARY KEY (role_code, permission_code)
);
CREATE TABLE users (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26),
  role_code VARCHAR(32) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(32),
  password_hash VARCHAR(255) NOT NULL,
  status VARCHAR(16) NOT NULL,
  locale VARCHAR(5) NOT NULL,
  failed_attempts INT NOT NULL,
  locked_until DATETIME(3),
  totp_secret_enc TEXT,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  deleted_at DATETIME(3)
);
CREATE INDEX idx_users_tenant ON users (tenant_id);
CREATE TABLE user_permission_overrides (
  id CHAR(26) NOT NULL PRIMARY KEY,
  user_id CHAR(26) NOT NULL,
  permission_code VARCHAR(48) NOT NULL,
  granted TINYINT NOT NULL,
  UNIQUE (user_id, permission_code)
);
CREATE TABLE refresh_tokens (
  id CHAR(26) NOT NULL PRIMARY KEY,
  user_id CHAR(26) NOT NULL,
  device_id CHAR(26),
  family_id CHAR(26) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME(3) NOT NULL,
  revoked_at DATETIME(3),
  replaced_by CHAR(26),
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_refresh_user ON refresh_tokens (user_id);
CREATE TABLE audit_logs (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26),
  user_id CHAR(26),
  device_id CHAR(26),
  action VARCHAR(80) NOT NULL,
  entity VARCHAR(64),
  entity_id VARCHAR(64),
  before_json JSON,
  after_json JSON,
  ip VARCHAR(45),
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_audit_tenant_time ON audit_logs (tenant_id, created_at);
CREATE TABLE notifications (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26),
  recipient VARCHAR(190) NOT NULL,
  channel VARCHAR(16) NOT NULL,
  template VARCHAR(64) NOT NULL,
  payload JSON NOT NULL,
  status VARCHAR(16) NOT NULL,
  sent_at DATETIME(3),
  created_at DATETIME(3) NOT NULL
);
CREATE TABLE jobs (
  id CHAR(26) NOT NULL PRIMARY KEY,
  queue VARCHAR(48) NOT NULL,
  payload JSON NOT NULL,
  attempts INT NOT NULL,
  max_attempts INT NOT NULL,
  available_at DATETIME(3) NOT NULL,
  locked_until DATETIME(3),
  status VARCHAR(16) NOT NULL,
  last_error TEXT,
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_jobs_ready ON jobs (status, available_at);
CREATE TABLE rate_limits (
  rkey VARCHAR(190) NOT NULL,
  window_start BIGINT NOT NULL,
  hits INT NOT NULL,
  PRIMARY KEY (rkey, window_start)
)
