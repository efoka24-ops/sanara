CREATE TABLE sale_returns (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  number VARCHAR(40) NOT NULL,
  sale_id CHAR(26) NOT NULL,
  session_id CHAR(26),
  kind VARCHAR(10) NOT NULL,
  reason VARCHAR(190) NOT NULL,
  restock TINYINT NOT NULL,
  refund_method VARCHAR(16) NOT NULL,
  refund_amount BIGINT NOT NULL,
  created_by CHAR(26) NOT NULL,
  approved_by CHAR(26),
  created_at DATETIME(3) NOT NULL,
  UNIQUE (tenant_id, number)
);
CREATE INDEX idx_returns_sale ON sale_returns (tenant_id, sale_id);
CREATE TABLE return_lines (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  return_id CHAR(26) NOT NULL,
  sale_line_id CHAR(26) NOT NULL,
  product_id CHAR(26) NOT NULL,
  qty INT NOT NULL,
  amount BIGINT NOT NULL,
  restocked INT NOT NULL
);
CREATE TABLE credit_notes (
  id CHAR(26) NOT NULL PRIMARY KEY,
  tenant_id CHAR(26) NOT NULL,
  number VARCHAR(40) NOT NULL,
  return_id CHAR(26) NOT NULL,
  sale_id CHAR(26) NOT NULL,
  amount BIGINT NOT NULL,
  created_at DATETIME(3) NOT NULL,
  UNIQUE (tenant_id, number)
)
