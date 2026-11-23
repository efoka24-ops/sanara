CREATE TABLE pharmacy_applications (
  id CHAR(26) NOT NULL PRIMARY KEY,
  number VARCHAR(16) NOT NULL UNIQUE,
  tracking_code VARCHAR(32) NOT NULL UNIQUE,
  country_code CHAR(2) NOT NULL,
  status VARCHAR(16) NOT NULL,
  pharmacy_name VARCHAR(160) NOT NULL,
  license_number VARCHAR(64) NOT NULL,
  license_date VARCHAR(10) NOT NULL,
  pharmacy_phone VARCHAR(32) NOT NULL,
  pharmacy_email VARCHAR(190),
  pos_count INT NOT NULL,
  current_tool VARCHAR(40),
  takes_duty TINYINT NOT NULL,
  offers_delivery_drop TINYINT NOT NULL,
  region VARCHAR(80) NOT NULL,
  department VARCHAR(80) NOT NULL,
  city VARCHAR(80) NOT NULL,
  district VARCHAR(80) NOT NULL,
  address VARCHAR(255),
  latitude DECIMAL(9,6),
  longitude DECIMAL(9,6),
  owner_last_name VARCHAR(80) NOT NULL,
  owner_first_name VARCHAR(80) NOT NULL,
  owner_order_number VARCHAR(64) NOT NULL,
  owner_phone VARCHAR(32) NOT NULL,
  owner_email VARCHAR(190) NOT NULL,
  locale VARCHAR(5) NOT NULL,
  info_message TEXT,
  info_docs JSON,
  rejection_reason VARCHAR(80),
  rejection_note TEXT,
  allow_reapply TINYINT NOT NULL,
  plan_code VARCHAR(32),
  tenant_id CHAR(26),
  reviewed_by CHAR(26),
  reviewed_at DATETIME(3),
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_apps_status ON pharmacy_applications (status, created_at);
CREATE INDEX idx_apps_email ON pharmacy_applications (owner_email);
CREATE TABLE application_documents (
  id CHAR(26) NOT NULL PRIMARY KEY,
  application_id CHAR(26) NOT NULL,
  doc_type VARCHAR(24) NOT NULL,
  storage_id CHAR(26) NOT NULL,
  original_name VARCHAR(120) NOT NULL,
  mime VARCHAR(64) NOT NULL,
  size_bytes INT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  superseded TINYINT NOT NULL,
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_appdocs_app ON application_documents (application_id);
CREATE TABLE activation_tokens (
  id CHAR(26) NOT NULL PRIMARY KEY,
  user_id CHAR(26) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME(3) NOT NULL,
  used_at DATETIME(3),
  created_at DATETIME(3) NOT NULL
)
