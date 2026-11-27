CREATE TABLE patient_requests (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  code CHAR(40) NOT NULL UNIQUE,
  number VARCHAR(20) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  status VARCHAR(16) NOT NULL,
  urgent TINYINT NOT NULL,
  advice VARCHAR(16),
  locale VARCHAR(5) NOT NULL,
  locked_by CHAR(26),
  locked_at DATETIME(3),
  pickup_code VARCHAR(8),
  pickup_expires_at DATETIME(3),
  picked_up_at DATETIME(3),
  sale_id CHAR(26),
  consent_version VARCHAR(10) NOT NULL,
  consent_at DATETIME(3) NOT NULL,
  consent_ip_hash CHAR(64) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_requests_queue ON patient_requests (tenant_id, status, created_at);
CREATE INDEX idx_requests_pickup ON patient_requests (tenant_id, pickup_code);
CREATE TABLE request_documents (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  request_id CHAR(26) NOT NULL,
  storage_id CHAR(26) NOT NULL,
  original_name VARCHAR(120) NOT NULL,
  mime VARCHAR(64) NOT NULL,
  size_bytes INT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_request_docs ON request_documents (tenant_id, request_id);
CREATE INDEX idx_request_docs_hash ON request_documents (tenant_id, sha256);
CREATE TABLE request_events (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  request_id CHAR(26) NOT NULL,
  kind VARCHAR(24) NOT NULL,
  actor_id CHAR(26),
  note VARCHAR(500),
  seq INT NOT NULL,
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_request_events ON request_events (tenant_id, request_id);
CREATE TABLE quotes (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  request_id CHAR(26) NOT NULL UNIQUE,
  total BIGINT NOT NULL,
  valid_until DATETIME(3) NOT NULL,
  created_by CHAR(26) NOT NULL,
  created_at DATETIME(3) NOT NULL
);
CREATE TABLE quote_lines (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  quote_id CHAR(26) NOT NULL,
  request_id CHAR(26) NOT NULL,
  position INT NOT NULL,
  product_id CHAR(26),
  label VARCHAR(190) NOT NULL,
  quantity INT NOT NULL,
  unit_price BIGINT NOT NULL,
  line_total BIGINT NOT NULL,
  available TINYINT NOT NULL,
  accepted TINYINT,
  read_text VARCHAR(190),
  read_confidence SMALLINT,
  corrected TINYINT NOT NULL
);
CREATE INDEX idx_quote_lines ON quote_lines (tenant_id, request_id);
CREATE INDEX idx_quote_lines_product ON quote_lines (tenant_id, product_id)
