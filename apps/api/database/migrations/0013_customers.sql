CREATE TABLE customers (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(30),
  email VARCHAR(190),
  credit_limit BIGINT NOT NULL,
  reminder_consent TINYINT NOT NULL,
  active TINYINT NOT NULL,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_customers_name ON customers (tenant_id, name);
CREATE TABLE customer_entries (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  customer_id CHAR(26) NOT NULL,
  kind VARCHAR(12) NOT NULL,
  amount BIGINT NOT NULL,
  sale_id CHAR(26),
  return_id CHAR(26),
  method VARCHAR(20),
  reference VARCHAR(80),
  receipt_number VARCHAR(30),
  user_id CHAR(26) NOT NULL,
  created_at DATETIME(3) NOT NULL
);
CREATE INDEX idx_customer_entries ON customer_entries (tenant_id, customer_id, created_at);
ALTER TABLE sales ADD COLUMN customer_id CHAR(26);
CREATE TABLE ref_changes (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  entity VARCHAR(10) NOT NULL,
  entity_id CHAR(26) NOT NULL,
  changed_at DATETIME(3) NOT NULL,
  UNIQUE (tenant_id, entity, entity_id)
);
CREATE INDEX idx_ref_changes_at ON ref_changes (tenant_id, entity, changed_at)
